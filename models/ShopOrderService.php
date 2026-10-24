<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Core\Library\CustomerNotifications;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Library\StoreFinance;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Checkout\Models\CheckoutOrder;

require_once __DIR__ . '/CheckoutGuards.php';

/**
 * ShopOrderService
 *
 * Turns a basket into an order. The order row is written **before** payment is
 * attempted, never after — an order that only exists on success cannot record a
 * payment that succeeded while the customer's browser died, and that is money
 * taken with no record of it.
 *
 * Totals are recomputed here from the database basket. Nothing monetary is ever
 * read from the submitted form, however convenient the form's own summary is.
 */
class ShopOrderService
{
    private const ORDER_REF_BASE = 1000; // First order is <prefix>1001

    public function __construct(
        private CheckoutOrder $orders,
        private CheckoutBasketService $basket,
        private ShopTotalsService $totals,
        private StoreFinance $finance,
        private ?ShopDiscountService $discounts = null,
        private string $orderRefPrefix = 'ORD-',
    ) {}

    /**
     * Create a pending order from a basket.
     *
     * @param int $basketId Basket to convert
     * @param array $customer Validated customer and address fields
     * @param string|null $ipAddress Client IP, stored for fraud questions later
     * @param string|null $userAgent Browser user agent
     * @param string|null $couponCode Coupon code applied on the basket, if any
     * @return array{id: int, order_ref: string, totals: array}
     * @throws ShopOrderException When the basket or the delivery destination
     *                            makes an order impossible, or a coupon has
     *                            already been used the maximum number of
     *                            times by this customer
     */
    public function createPendingOrder(
        int $basketId,
        array $customer,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $couponCode = null,
    ): array {
        $contents = $this->basket->getLines($basketId);
        $lines = $contents['lines'];

        $basketEmpty = empty($lines);
        if ($basketEmpty) throw new ShopOrderException('Your basket is empty.');

        foreach ($lines as $line) {
            $stillForSale = $this->basket->isPurchasable((int)$line['product_id']); // A product can be discontinued after it went into the basket
            if (!$stillForSale) throw new ShopOrderException("{$line['product_title']} is no longer available. Please remove it from your basket.");
        }

        (new CheckoutGuards($this->orders->database))->check('order.check', [
            'basketId' => $basketId,
            'lines' => $this->basket->getLinesWithParts($basketId),
        ]);

        $postcode = (string)($customer['delivery_postcode'] ?? '');
        $totals = $this->totals->calculate(lines: $lines, postcode: $postcode, couponCode: $couponCode); // Recomputed from the database basket, never from the form

        $this->assertDeliverable($totals);
        $this->assertCouponNotExhaustedByCustomer(totals: $totals, customerEmail: (string)($customer['customer_email'] ?? ''));

        $database = $this->orders->database;
        $ownsTransaction = !$database->inTransaction(); // PDO has no nested transactions: respect an open one
        if ($ownsTransaction) $database->beginTransaction();

        try {
            $orderId = $this->insertOrder(
                basketId: $basketId,
                customer: $customer,
                totals: $totals,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $orderRef = $this->orderRefPrefix . (self::ORDER_REF_BASE + $orderId);
            $this->setOrderRef(orderId: $orderId, orderRef: $orderRef);

            foreach ($totals['lines'] as $line) {
                $itemId = $this->insertOrderItem(orderId: $orderId, line: $line);
                $this->insertOrderItemParts(itemId: $itemId, line: $line);
            }

            if ($ownsTransaction) $database->endTransaction();
        } catch (\Throwable $e) {
            if ($ownsTransaction) $database->cancelTransaction();
            \error_log('Order creation failed: ' . $e->getMessage());
            throw new ShopOrderException('Sorry, we could not start your order. Please try again.');
        }

        $this->dispatch('order.created', orderId: $orderId);

        return [
            'id' => $orderId,
            'order_ref' => $orderRef,
            'totals' => $totals,
        ];
    }

    /**
     * Take the next gapless invoice number.
     *
     * Called only when a payment has actually succeeded. Uses the counter row's
     * own lock rather than MAX()+1, which would race two simultaneous captures
     * onto one number.
     *
     * @return int The invoice number now owned by the caller
     */
    public function takeInvoiceNumber(): int
    {
        return $this->orders->takeInvoiceNumber();
    }

    /**
     * Record the payment provider's own reference against a pending order.
     *
     * Written before the customer is sent to pay, so any capture that comes
     * back — through the browser or through a webhook — can be traced to the
     * order it belongs to.
     *
     * @param int $orderId Our order id
     * @param string $provider Provider name, e.g. 'paypal'
     * @param string $paymentOrderRef The provider's order id
     * @param bool $isTestPayment Whether the provider was in sandbox/test mode
     *                            for this order — a warehouse must never
     *                            dispatch against a payment that was never real
     */
    public function setPaymentReference(
        int $orderId,
        string $provider,
        string $paymentOrderRef,
        bool $isTestPayment,
    ): void {
        $this->orders->setPaymentReference($orderId, $provider, $paymentOrderRef, $isTestPayment);
    }

    /**
     * Mark an order paid and assign its invoice number.
     *
     * Idempotent, and deliberately so: a double-clicked button, a retried
     * capture and a webhook arriving after the browser already confirmed must
     * all resolve to one payment, one invoice number and one confirmation
     * email. The status is read and the number taken under the order row's own
     * lock, so a second caller sees 'paid' and takes nothing.
     *
     * @param int $orderId Order to mark
     * @param string $captureRef The provider's capture id
     * @param string|null $payerEmail Email the provider says paid
     * @return int|null The invoice number assigned, or null when the order was
     *                  already paid and this call did nothing
     */
    public function markPaid(int $orderId, string $captureRef, ?string $payerEmail, ?string $paymentMethod = null): ?int
    {
        $ownsTransaction = !$this->orders->database->inTransaction();
        if ($ownsTransaction) $this->orders->database->beginTransaction();

        try {
            $order = $this->orders->lockById($orderId);

            $orderMissing = $order === null;
            if ($orderMissing) throw new ShopOrderException('That order could not be found.');

            $alreadyResolved = $order['status'] !== 'pending';
            if ($alreadyResolved) {
                if ($ownsTransaction) $this->orders->database->endTransaction();
                return null; // Not an error: the first caller already did this work
            }

            $invoiceNumber = $this->takeInvoiceNumber();

            $this->orders->markPaid($orderId, $invoiceNumber, $captureRef, $payerEmail, $paymentMethod);

            // Only a coupon has a code and a usage limit worth tracking —
            // sitewide/product discounts have neither, and were never
            // resolved through this path.
            $orderHasCoupon = !empty($order['discount_code']) && !empty($order['discount_id']);
            if ($orderHasCoupon) {
                $this->discounts?->recordRedemption(
                    discountId: (int)$order['discount_id'],
                    orderId: $orderId,
                    customerEmail: $order['customer_email'] ?: null,
                );
            }

            if ($ownsTransaction) $this->orders->database->endTransaction();

            $this->dispatch('order.paid', orderId: $orderId);
            $this->textCustomer($orderId);

            return $invoiceNumber;
        } catch (\Throwable $e) {
            if ($ownsTransaction) $this->orders->database->cancelTransaction();
            throw $e;
        }
    }

    /**
     * Move an order to a non-payment state: failed, cancelled or refunded.
     *
     * Only legal moves happen: failed and cancelled from pending, refunded
     * from paid or dispatched. An out-of-order event (a 'failed' webhook after
     * the capture succeeded) changes nothing and is not an error.
     *
     * @param int $orderId Order to move
     * @param string $status New status
     * @throws \InvalidArgumentException For any status other than the three above
     */
    public function markStatus(int $orderId, string $status): void
    {
        $this->orders->markStatus($orderId, $status);
    }

    /**
     * Find an order by the payment provider's reference.
     *
     * The webhook knows the provider's ids and nothing about ours.
     *
     * @param string $paymentOrderRef The provider's order id
     * @return array|null The order, or null when it is not ours
     */
    public function findByPaymentReference(string $paymentOrderRef): ?array
    {
        return $this->orders->findByPaymentReference($paymentOrderRef);
    }

    /**
     * Load an order with its line items.
     *
     * @param int $orderId Order to load
     * @return array|null Order with an 'items' key, or null when not found
     */
    public function getOrder(int $orderId): ?array
    {
        $order = $this->orders->getById($orderId);

        $orderMissing = $order === null;
        if ($orderMissing) return null;

        $order['items'] = $this->attachParts($this->orders->getItems($orderId), $orderId);

        return $order;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>> Items, each with a 'parts' list (empty for a plain product)
     */
    private function attachParts(array $items, int $orderId): array
    {
        $partsByItem = [];
        foreach ($this->orders->getItemParts($orderId) as $row) {
            $partsByItem[(int)$row['order_item_id']][] = [
                'product_id' => (int)$row['product_id'],
                'option_value_id' => $row['option_value_id'] === null ? null : (int)$row['option_value_id'],
                'quantity' => (int)$row['quantity'],
            ];
        }

        return \array_map(function (array $item) use ($partsByItem): array {
            $item['parts'] = $partsByItem[(int)$item['id']] ?? [];
            return $item;
        }, $items);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Refuse to create an order we cannot price delivery for.
     *
     * @param array $totals Output of ShopTotalsService::calculate()
     */
    private function assertDeliverable(array $totals): void
    {
        $deliveryUnconfigured = !$totals['delivery_configured'];
        if ($deliveryUnconfigured) throw new ShopOrderException('Sorry, we cannot take orders at the moment. Please contact us and we will help.'); // £0.00 here would mean free delivery by accident

        $cannotDeliver = !$totals['deliverable'];
        if ($cannotDeliver) throw new ShopOrderException("We're not able to deliver to that postcode.");
    }

    /**
     * Refuse to create an order with a coupon this customer has already used
     * up their allowance of.
     *
     * The coupon's overall usage_limit was already checked when it was
     * resolved (ShopDiscountService::activeCouponByCode), before the
     * customer's email was even known. This is the per-customer half of that
     * check, only possible here once the checkout form supplies an email.
     */
    private function assertCouponNotExhaustedByCustomer(array $totals, string $customerEmail): void
    {
        $discount = $totals['discount'];

        $notACoupon = $discount === null || $discount['type'] !== 'coupon';
        if ($notACoupon) return;

        $perCustomerLimit = $discount['usage_limit_per_customer'] ?? null;
        $noLimitSet = $perCustomerLimit === null;
        if ($noLimitSet) return;

        $emailMissing = $customerEmail === '';
        if ($emailMissing) return; // Validator requires an email before this point is reachable in practice

        $usedCount = $this->discounts?->customerRedemptionCount($discount['id'], $customerEmail) ?? 0;

        $exhausted = $usedCount >= $perCustomerLimit;
        if ($exhausted) throw new ShopOrderException('That coupon code has already been used the maximum number of times on this account.');
    }

    /**
     * Tell installed services an order was created or paid. Runs after the
     * order's own transaction, so a handler can never undo it, and every route
     * that pays an order (browser capture, provider webhooks) goes through
     * markPaid(), so one dispatch covers them all. Never throws: ServiceHooks
     * logs and skips a failing handler.
     */
    private function dispatch(string $event, int $orderId): void
    {
        try {
            $order = $this->getOrder($orderId);
            if ($order === null) return;

            (new ServiceHooks(new ServiceLoader(new ServiceModel($this->orders->database))))->dispatch($event, [
                'orderId' => $orderId,
                'order' => $order,
                'dataSource' => $this->orders->database,
            ]);
        } catch (\Throwable $e) {
            \error_log("Order hook '{$event}' failed for order {$orderId}: {$e->getMessage()}");
        }
    }

    /**
     * The customer's "payment received" text, when SMS is ticked for it in
     * Shop settings and an SMS provider is installed. Runs from markPaid(), so
     * it covers every paying route once. Never throws, like dispatch().
     */
    private function textCustomer(int $orderId): void
    {
        try {
            $order = $this->getOrder($orderId);
            if ($order === null) return;

            CustomerNotifications::sendSms(
                event: 'order_paid',
                order: $order,
                hooks: new ServiceHooks(new ServiceLoader(new ServiceModel($this->orders->database))),
            );
        } catch (\Throwable $e) {
            \error_log("Order text failed for order {$orderId}: {$e->getMessage()}");
        }
    }

    private function insertOrder(
        int $basketId,
        array $customer,
        array $totals,
        ?string $ipAddress,
        ?string $userAgent,
    ): int {
        $deliveryName = $this->value($customer, 'delivery_name') ?: $this->value($customer, 'customer_name');

        return $this->orders->insert([
            ':basket_id' => $basketId,
            ':customer_name' => $this->value($customer, 'customer_name'),
            ':customer_email' => $this->value($customer, 'customer_email'),
            ':customer_phone' => $this->value($customer, 'customer_phone') ?: null,

            ':billing_address1' => $this->value($customer, 'billing_address1') ?: $this->value($customer, 'delivery_address1'),
            ':billing_address2' => $this->value($customer, 'billing_address2') ?: $this->value($customer, 'delivery_address2') ?: null,
            ':billing_city' => $this->value($customer, 'billing_city') ?: $this->value($customer, 'delivery_city'),
            ':billing_county' => $this->value($customer, 'billing_county') ?: $this->value($customer, 'delivery_county') ?: null,
            ':billing_postcode' => $this->value($customer, 'billing_postcode') ?: $this->value($customer, 'delivery_postcode'),
            ':billing_country' => ShopCheckoutValidator::COUNTRY,

            ':delivery_name' => $deliveryName,
            ':delivery_address1' => $this->value($customer, 'delivery_address1'),
            ':delivery_address2' => $this->value($customer, 'delivery_address2') ?: null,
            ':delivery_city' => $this->value($customer, 'delivery_city'),
            ':delivery_county' => $this->value($customer, 'delivery_county') ?: null,
            ':delivery_postcode' => \strtoupper($this->value($customer, 'delivery_postcode')),
            ':delivery_country' => ShopCheckoutValidator::COUNTRY,
            ':delivery_zone' => $totals['delivery_zone'],

            ':subtotal_exc_tax' => $totals['subtotal_exc_tax'],
            ':shipping_amount' => $totals['delivery_exc_tax'],
            ':tax_amount' => $totals['tax_amount'],
            ':tax_rate_percent' => $totals['tax_rate_percent'],
            ':total_inc_tax' => $totals['total_inc_tax'],
            ':currency' => $this->finance->currencyCode(),
            ':discount_id' => $totals['discount']['id'] ?? null,
            ':discount_code' => $totals['discount']['code'] ?? null,
            ':discount_amount' => $totals['total_discount_amount'],

            ':terms_accepted_at' => \date('Y-m-d H:i:s'), // The form cannot be submitted without the tick, so reaching here is acceptance
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent ? \substr($userAgent, 0, 255) : null,
        ]);
    }

    private function setOrderRef(int $orderId, string $orderRef): void
    {
        $this->orders->setRef($orderId, $orderRef);
    }

    /** Freeze the products this line takes stock from, so a later edit to an option never rewrites an old order. */
    private function insertOrderItemParts(int $itemId, array $line): void
    {
        $parts = $this->basket->partsForLine(
            productId: (int)$line['product_id'],
            optionValueIds: $line['option_value_ids'] ?? null,
        );

        foreach ($parts as $part) {
            $this->orders->insertItemPart(
                orderItemId: $itemId,
                productId: $part['product_id'],
                optionValueId: $part['option_value_id'],
                quantity: $part['quantity'],
            );
        }
    }

    private function insertOrderItem(int $orderId, array $line): int
    {
        return $this->orders->insertItem([
            ':order_id' => $orderId,
            ':product_id' => (int)$line['product_id'],
            ':product_ref' => (string)$line['product_ref'],
            ':product_title' => (string)$line['product_title'],
            ':product_options' => $line['product_options'],
            ':option_signature' => (string)$line['option_signature'],
            ':quantity' => (int)$line['quantity'],
            ':unit_price_exc_tax' => $line['unit_price_exc_tax'],
            ':line_total_exc_tax' => $line['line_total_exc_tax'],
            ':line_tax_amount' => $line['line_tax_amount'],
            ':tax_rate_percent' => $this->totals->taxRatePercent(),
            ':weight' => $line['weight'],
            ':discount_id' => $line['discount_id'] ?? null,
            ':discount_amount' => $this->lineDiscountAmount($line),
        ]);
    }

    /** Everything the line's discount took off it, before VAT. */
    private function lineDiscountAmount(array $line): float
    {
        $perUnit = (float)($line['unit_price_before_discount_exc_tax'] ?? $line['unit_price_exc_tax']) - (float)$line['unit_price_exc_tax'];

        return \round($perUnit * (int)$line['quantity'], 2);
    }

    private function value(array $input, string $key): string
    {
        return \trim((string)($input[$key] ?? ''));
    }
}
