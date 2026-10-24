<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use Osmium\Modules\Checkout\Services\ShopOrderPdfService;
use Osmium\Core\Library\PaymentProviders;
use Osmium\Core\Features\FeatureConfig;
use Osmium\Core\Library\CustomerNotifications;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Orders controller - order fulfilment.
 *
 * Routes:
 *   - index()        → /admin/shop/orders/
 *   - view()         → /admin/shop/orders/view/?id=N
 *   - despatchNote() → /admin/shop/orders/despatch-note/?id=N
 *   - invoice()      → /admin/shop/orders/invoice/?id=N
 *   - action()       → /admin/shop/orders/action/
 *
 * Refunds are **recorded, not executed**. The refund itself is issued inside
 * whichever provider took the money; this only marks what happened. Paying
 * money out from admin is a meaningful security surface and is deliberately
 * not built.
 */
class OrdersController extends AdminController
{
    /**
     * Statuses that may be filtered on. The filter is inlined into SQL, so it
     * is matched against this list and never taken from input directly.
     */
    private const FILTERABLE_STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'dispatched', 'refunded'];

    // =========================================================================
    // Public Actions
    // =========================================================================

    /**
     * Order list, newest first, optionally filtered by status.
     */
    public function index(): void
    {
        $status = (string)$this->osmium->getStashedParam(key: 'status', default: '');
        $this->osmium->clearStashedQuerystring();

        $statusIsFilterable = \in_array(needle: $status, haystack: self::FILTERABLE_STATUSES, strict: true);
        $filterClause = $statusIsFilterable ? "WHERE o.status = '{$status}'" : '';

        $orders = $this->admin->model->order->getList($filterClause);
        $stats = $this->admin->model->order->getStats();

        $this->data['admin']['lists'] = $this->loadListDefinitions('orders', __DIR__ . '/../lists');
        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['orders'] = \array_map([$this, 'formatOrder'], $orders);
        $this->data['admin']['orderStats'] = $this->formatStats($stats ?? []);
        $this->data['admin']['statusFilter'] = $statusIsFilterable ? $status : '';
        $this->data['admin']['statuses'] = self::FILTERABLE_STATUSES;

        $this->setView('orders/index.phtml');
    }

    /**
     * A single order: what was bought, what was paid, where it goes.
     */
    public function view(): void
    {
        $orderId = (int)$this->osmium->getStashedParam(key: 'id', default: 0);
        $emailSentParam = $this->osmium->getStashedParam(key: 'email_sent', default: null);
        $emailError = $this->osmium->getStashedParam(key: 'email_error', default: null);
        $this->osmium->clearStashedQuerystring();

        $noOrderId = $orderId < 1;
        if ($noOrderId) $this->redirect('shop/orders/');

        $order = $this->admin->model->order->getById($orderId);

        $orderMissing = $order === null;
        if ($orderMissing) $this->redirect('shop/orders/');

        $items = $this->admin->model->order->getItems($orderId);

        $formattedOrder = $this->formatOrder($order);
        $isPaid = $order['status'] === 'paid';
        $isDispatched = $order['status'] === 'dispatched';

        $this->data['admin']['order'] = $formattedOrder;
        $this->data['admin']['orderItems'] = \array_map([$this, 'formatItem'], $items);
        $this->data['admin']['orderView'] = [
            'isPaid' => $isPaid,
            'isDispatched' => $isDispatched,
            'canRefund' => $isPaid || $isDispatched,
            'canPrintDespatchNote' => $isPaid || $isDispatched,
            // A paid order always carries a provider, but the refund note must still
            // read as a sentence if one is ever missing.
            'refundVenue' => $formattedOrder['provider_label'] === '—' ? 'the payment provider' : $formattedOrder['provider_label'],
            'hasNotes' => !empty($order['admin_notes']),
        ];
        $this->data['admin']['orderConfig'] = [ // window.orderViewConfig
            'orderId' => (int)$order['id'],
            'endpoint' => ($this->data['adminUrl'])('shop/orders/action/'),
        ];
        $this->data['admin']['dispatchEmailSent'] = $emailSentParam === null ? null : $emailSentParam === '1';
        $this->data['admin']['dispatchEmailError'] = $emailError;
        $this->data['admin']['servicePanels'] = $this->serviceHooks()->collect(
            event: 'order.panels',
            payload: ['order' => $order, 'osmium' => $this->osmium],
        );

        $this->setView('orders/view.phtml');
    }

    /**
     * A despatch note PDF - what the warehouse needs to pick and pack, with
     * no prices. A real generated document rather than a printed web page,
     * so it carries no browser-injected URL/date footer.
     */
    public function despatchNote(): void
    {
        [$order, $items] = $this->loadOrderForDocument();

        $pdfService = new ShopOrderPdfService($this->osmium->config, \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium));
        $pdf = $pdfService->buildDespatchNote($order, $items);

        $this->streamPdf($pdf, 'DespatchNote-' . $order['order_ref'] . '.pdf');
    }

    /**
     * An invoice PDF - itemised, with VAT and totals.
     */
    public function invoice(): void
    {
        [$order, $items] = $this->loadOrderForDocument();
        $items = \array_map([$this, 'formatItem'], $items);

        $pdfService = new ShopOrderPdfService($this->osmium->config, \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium));
        $pdf = $pdfService->buildInvoice($order, $items, (string)$this->osmium->config->site->name);

        $this->streamPdf($pdf, 'Invoice-' . $order['order_ref'] . '.pdf');
    }

    /**
     * Shared order + items lookup for the PDF-generating actions above.
     */
    private function loadOrderForDocument(): array
    {
        $orderId = (int)$this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $noOrderId = $orderId < 1;
        if ($noOrderId) $this->redirect('shop/orders/');

        $order = $this->admin->model->order->getById($orderId);

        $orderMissing = $order === null;
        if ($orderMissing) $this->redirect('shop/orders/');

        $items = $this->admin->model->order->getItems($orderId);

        return [$this->formatOrder($order), $items];
    }

    /**
     * Stream a generated PDF inline, so the "Print" links open it directly
     * in the browser's own PDF viewer rather than downloading a file.
     */
    private function streamPdf(string $pdf, string $filename): void
    {
        \header('Content-Type: application/pdf');
        \header('Content-Disposition: inline; filename="' . $filename . '"');
        \header('Content-Length: ' . \strlen($pdf));
        \header('Cache-Control: private, max-age=0, must-revalidate');
        \header('Pragma: public');

        echo $pdf;
        exit;
    }

    /**
     * Action endpoint - dispatch, refund and notes.
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isPostMethod = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPostMethod) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int)($input['id'] ?? 0);

        $invalidToken = !$this->admin->auth->validateCsrfJson($input);
        if ($invalidToken) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }

        try {
            $result = $this->handleAction($action, $id, $input);
            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Action Handling
    // =========================================================================

    private function handleAction(string $action, int $id, array $input): array
    {
        return match ($action) {
            'mark_dispatched' => $this->markDispatched($id, $input),
            'record_refund' => $this->recordRefund($id),
            'add_note' => $this->addNote($id, $input),
            default => $this->serviceAction($action, $id, $input),
        };
    }

    /**
     * Offers an unrecognised action to installed services (order.action).
     * A service's own exceptions propagate so the admin sees the real error.
     */
    private function serviceAction(string $action, int $id, array $input): array
    {
        $result = $this->serviceHooks()->handle(
            event: 'order.action',
            payload: ['action' => $action, 'orderId' => $id, 'input' => $input, 'osmium' => $this->osmium, 'admin' => $this->admin],
        );

        $unclaimed = !\is_array($result);
        if ($unclaimed) throw new \InvalidArgumentException('Unknown action');

        return $result;
    }

    /** A method so a test can stand in for the installed services */
    protected function serviceHooks(): ServiceHooks
    {
        return new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
    }

    /** A method so a test can stand in for the mailer instead of sending */
    protected function mailer(): ShopOrderMailer
    {
        return new ShopOrderMailer($this->osmium->config, \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium));
    }

    /**
     * Mark an order dispatched, with carrier and tracking, and email the
     * customer. A failure to send is reported back rather than assumed sent.
     */
    private function markDispatched(int $id, array $input): array
    {
        if (!$id) throw new \InvalidArgumentException('Missing order ID');

        $existing = $this->admin->model->order->getById($id);
        $isTestPayment = $existing !== null && (bool)$existing['is_test_payment'];
        if ($isTestPayment) {
            throw new \RuntimeException('This was a test-mode payment and was never real money — it must not be shipped.');
        }

        $carrier = \trim((string)($input['tracking_carrier'] ?? ''));
        $trackingRef = \trim((string)($input['tracking_ref'] ?? ''));

        $dispatched = $this->admin->model->order->markDispatched(
            id: $id,
            carrier: $carrier ?: null,
            trackingRef: $trackingRef ?: null,
        );

        $nothingChanged = !$dispatched;
        if ($nothingChanged) throw new \RuntimeException('Only a paid order can be marked dispatched.');

        $this->admin->model->changelog->log(
            description: "Marked order {$id} dispatched",
            recordType: 'order',
            recordId: $id,
        );

        $order = $this->admin->model->order->getById($id);
        $mailer = $this->mailer();
        $emailResult = $mailer->sendOrderDispatchedEmail(
            toEmail: $order['customer_email'],
            customerName: $order['customer_name'],
            orderRef: $order['order_ref'],
            carrier: $carrier ?: null,
            trackingRef: $trackingRef ?: null,
        );

        $this->textDispatched(order: $order, carrier: $carrier, trackingRef: $trackingRef);

        $emailSkipped = $emailResult['skipped'] ?? false;

        return [
            'success' => true,
            'action' => 'mark_dispatched',
            'id' => $id,
            'email_sent' => $emailSkipped ? null : $emailResult['success'], // null: Shop settings has dispatch emails switched off
            'email_error' => $emailResult['error'] ?? null,
        ];
    }

    /**
     * The dispatch text, when SMS is ticked for it in Shop settings. Best-effort:
     * a failure is logged by CustomerNotifications and never undoes the dispatch.
     */
    private function textDispatched(array $order, string $carrier, string $trackingRef): void
    {
        try {
            CustomerNotifications::sendSms(
                event: 'order_dispatched',
                order: $order,
                hooks: new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource))),
                extra: ['tracking_carrier' => $carrier, 'tracking_ref' => $trackingRef],
            );
        } catch (\Throwable $e) {
            \error_log("Dispatch text failed for order {$order['order_ref']}: {$e->getMessage()}");
        }
    }

    private function recordRefund(int $id): array
    {
        if (!$id) throw new \InvalidArgumentException('Missing order ID');

        $refunded = $this->admin->model->order->recordRefund($id);
        $nothingChanged = !$refunded;
        if ($nothingChanged) throw new \RuntimeException('Only a paid or dispatched order can be refunded.');

        $this->admin->model->changelog->log(
            description: "Recorded refund against order {$id}",
            recordType: 'order',
            recordId: $id,
        );

        return ['success' => true, 'action' => 'record_refund', 'id' => $id];
    }

    private function addNote(int $id, array $input): array
    {
        if (!$id) throw new \InvalidArgumentException('Missing order ID');

        $note = \trim((string)($input['note'] ?? ''));

        $noteEmpty = $note === '';
        if ($noteEmpty) throw new \InvalidArgumentException('Please enter a note.');

        $user = $this->data['admin']['user']['username'] ?? 'admin';
        $stamped = \date('Y-m-d H:i') . ' (' . $user . '): ' . $note;

        $this->admin->model->order->appendNote(id: $id, note: $stamped);

        return ['success' => true, 'action' => 'add_note', 'id' => $id, 'note' => $stamped];
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    /**
     * Pre-compute display values so templates carry no formatting logic.
     */
    private function formatOrder(array $order): array
    {
        $order['total_formatted'] = $this->money($order['total_inc_tax'] ?? 0);
        $order['subtotal_formatted'] = $this->money($order['subtotal_exc_tax'] ?? 0);
        $order['shipping_formatted'] = $this->money($order['shipping_amount'] ?? 0);
        $order['discount_formatted'] = $this->money($order['discount_amount'] ?? 0);
        $order['tax_formatted'] = $this->money($order['tax_amount'] ?? 0);

        $order['created_at_formatted'] = $this->dateTime($order['created_at'] ?? null);
        $order['paid_at_formatted'] = $this->dateTime($order['paid_at'] ?? null);
        $order['dispatched_at_formatted'] = $this->dateTime($order['dispatched_at'] ?? null);

        $order['status_label'] = \ucfirst((string)($order['status'] ?? ''));
        $order['status_class'] = $this->statusClass((string)($order['status'] ?? ''));
        $order['invoice_display'] = $order['invoice_number'] ? 'INV-' . $order['invoice_number'] : '—';
        $order['provider_label'] = $this->providerLabel((string)($order['payment_provider'] ?? ''));
        $order['is_test_payment'] = (bool)($order['is_test_payment'] ?? false);

        return $order;
    }

    private function formatItem(array $item): array
    {
        $item['unit_price_formatted'] = $this->money($item['unit_price_exc_tax'] ?? 0);
        $item['line_total_formatted'] = $this->money($item['line_total_exc_tax'] ?? 0);
        $item['line_tax_formatted'] = $this->money($item['line_tax_amount'] ?? 0);

        return $item;
    }

    private function formatStats(array $stats): array
    {
        return [
            'total' => (int)($stats['total_count'] ?? 0),
            'pending' => (int)($stats['pending_count'] ?? 0),
            'awaiting_dispatch' => (int)($stats['awaiting_dispatch_count'] ?? 0),
            'dispatched' => (int)($stats['dispatched_count'] ?? 0),
            'refunded' => (int)($stats['refunded_count'] ?? 0),
            'paid_30d' => (int)($stats['paid_30d_count'] ?? 0),
            'paid_30d_value' => $this->money($stats['paid_30d_value'] ?? 0),
        ];
    }

    /**
     * The order row records which provider took the money, so admin says so
     * rather than assuming. Labels come from the provider registry, so an
     * order taken by a provider added later reads correctly without a change
     * here.
     */
    private function providerLabel(string $provider): string
    {
        if ($provider === '') return '—';

        return PaymentProviders::label($provider, PaymentProviders::external($this->osmium));
    }

    /**
     * Bootstrap badge class per status, so the list is scannable at a glance.
     */
    private function statusClass(string $status): string
    {
        return match ($status) {
            'paid' => 'bg-success',
            'dispatched' => 'bg-primary',
            'pending' => 'bg-warning text-dark',
            'refunded' => 'bg-info text-dark',
            'failed', 'cancelled' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    private function money(mixed $amount): string
    {
        return \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium)->format((float)$amount);
    }

    private function dateTime(?string $value): string
    {
        $missing = empty($value);
        if ($missing) return '—';

        $timestamp = \strtotime($value);

        return $timestamp ? \date('d M Y, H:i', $timestamp) : '—';
    }
}
