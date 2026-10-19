<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\CheckoutCsrf;
use Osmium\Modules\Checkout\Services\CheckoutOrderPaymentState;
use Osmium\Modules\Checkout\Services\CheckoutPaymentCreated;
use Osmium\Modules\Checkout\Services\CheckoutPendingPayment;
use Osmium\Modules\Checkout\Services\ShopCaptureCheck;
use Osmium\Modules\Checkout\Services\ShopCheckoutValidator;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopPaymentException;
use Osmium\Modules\Checkout\Services\ShopPurchaseEvent;
use Osmium\Modules\Checkout\Services\ShopTotalsService;

require_once __DIR__ . '/../models/ShopTotalsService.php';
require_once __DIR__ . '/../models/CheckoutBasketService.php';
require_once __DIR__ . '/../models/ShopOrderException.php';
require_once __DIR__ . '/../models/CheckoutCsrf.php';
require_once __DIR__ . '/../models/CheckoutOrderPaymentState.php';
require_once __DIR__ . '/../models/CheckoutPaymentCreated.php';
require_once __DIR__ . '/../models/CheckoutPendingPayment.php';
require_once __DIR__ . '/../models/ShopCaptureCheck.php';
require_once __DIR__ . '/../models/ShopCheckoutValidator.php';
require_once __DIR__ . '/../models/ShopPurchaseEvent.php';
require_once __DIR__ . '/../models/ShopOrderService.php';
require_once __DIR__ . '/../models/ShopOrderMailer.php';
require_once __DIR__ . '/../models/ShopPaymentException.php';
require_once __DIR__ . '/../models/CheckoutModel.php';
require_once __DIR__ . '/../models/CheckoutBasket.php';
require_once __DIR__ . '/../models/CheckoutDiscount.php';
require_once __DIR__ . '/../models/CheckoutOrder.php';
require_once __DIR__ . '/../models/ShopDiscountService.php';

$input = $this->osmium->getJsonInput();
$action = $input['action'] ?? null;
$sessionId = \session_id();

$subdomain = (string)($this->osmium->config->site->subdomain ?? '');
$isStaging = $subdomain !== '' && $subdomain !== 'www';

if (!$sessionId) {
    $response = ['success' => false, 'error' => 'Your session has expired. Please reload the page and try again.'];
    if ($isStaging) {
        $response['diagnostic'] = 'No session_id() at all. Cookies received: ' . CheckoutCsrf::cookieNames($_SERVER['HTTP_COOKIE'] ?? '');
    }
    $this->osmium->jsonSuccess($response);
}

$submittedToken = (string)($input['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['token'] ?? '');
$tokenValid = CheckoutCsrf::isValid(submitted: $submittedToken, session: $sessionToken);

if (!$tokenValid) {
    $response = ['success' => false, 'error' => 'Your session has expired. Please reload the page and try again.'];
    if ($isStaging) {
        $sessionFile = \session_save_path() . '/sess_' . $sessionId;
        $response['diagnostic'] = "Cookies received: " . CheckoutCsrf::cookieNames($_SERVER['HTTP_COOKIE'] ?? '')
            . " | submittedTokenLen=" . \strlen($submittedToken)
            . " | sessionTokenLen=" . \strlen($sessionToken)
            . " | sessionKeys=" . \implode(',', \array_keys($_SESSION))
            . " | sessionFileExists=" . (\file_exists($sessionFile) ? 'yes' : 'no');
    }
    $this->osmium->jsonSuccess($response);
}

// Whichever provider admin has selected is the only one that may take money,
// even if another is configured and would otherwise answer.
$external = PaymentProviders::external($this->osmium);
$selectedProvider = PaymentProviders::configured($this->osmium->config->checkout, $external);
$discountsSupported = PaymentProviders::supportsDiscounts($selectedProvider);
$discountService = $discountsSupported
    ? new ShopDiscountService(new CheckoutDiscount($this->osmium->dataSource, 'discounts'))
    : null;

$basketService = new CheckoutBasketService(new CheckoutBasket($this->osmium->dataSource, 'checkout_baskets'));
$finance = \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium);
$totalsService = ShopTotalsService::fromConfig($this->osmium->config->checkout, $finance, discounts: $discountService);
$orderService = new ShopOrderService(
    orders: new CheckoutOrder($this->osmium->dataSource, 'shop_orders'),
    basket: $basketService,
    totals: $totalsService,
    discounts: $discountService,
    finance: $finance,
);
$validator = new ShopCheckoutValidator($totalsService);

// Every provider is a service answering the payment.* hooks. A provider with
// none cannot take money, which is the safe default: a half-built provider
// renders no button rather than a dead one.
$paymentReady = isset($external[$selectedProvider]) && $external[$selectedProvider]['ready'];
$serviceHooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));

$paymentUnavailable = !$paymentReady;

if ($paymentUnavailable) $this->osmium->jsonError('Card payment is not available at the moment. Please contact us and we will help.');

// No separate basket cookie — CheckoutBasketService keys on session_id alone

try {
    switch ($action) {
        case 'create_order':
            $basketId = $basketService->getOrCreateBasket($sessionId);
            $basketEmpty = empty($basketService->getLines($basketId)['lines']);
            if ($basketEmpty) $this->osmium->jsonError('Your basket is empty.');

            $errors = $validator->validate($input); // Revalidated here: the form's own validation is a convenience, and this endpoint is reachable without it
            if ($errors) {
                $this->osmium->jsonSuccess([
                    'success' => false,
                    'errors' => $errors,
                    'error' => 'Please check the details you entered.',
                ]);
            }

            $order = $orderService->createPendingOrder(
                basketId: $basketId,
                customer: $input,
                ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
                couponCode: $_SESSION['applied_coupon_code'] ?? null,
            ); // Writes the order before payment is attempted, and recomputes every figure from the database basket

            $stored = $orderService->getOrder($order['id']);

            // The only provider-specific step. Everything above recomputed the
            // order from the database basket; everything below is the same
            // whoever is taking the money. A provider service (e.g. Stripe)
            // may hand back a client secret alongside its reference; one with
            // no equivalent (PayPal) leaves it out and the frontend ignores it.
            $created = $serviceHooks->handle('payment.create', [
                'provider' => $selectedProvider,
                'order' => $stored,
                'totals' => $order['totals'],
                'osmium' => $this->osmium,
            ]); // A ShopPaymentException from the service is handled below

            $createdPayment = CheckoutPaymentCreated::fromHook(created: $created, provider: $selectedProvider);
            $providerOrderRef = $createdPayment['ref'];
            $clientSecret = $createdPayment['client_secret'];
            $isTestPayment = $createdPayment['test_mode'];

            $orderService->setPaymentReference(
                orderId: $order['id'],
                provider: $selectedProvider,
                paymentOrderRef: $providerOrderRef,
                isTestPayment: $isTestPayment,
            );

            $_SESSION['pending_order_id'] = $order['id'];
            $_SESSION['pending_payment_ref'] = $providerOrderRef; // Confirmation checks against this, so another session cannot confirm someone else's order

            $this->osmium->jsonSuccess([
                'success' => true,
                'provider_order_ref' => $providerOrderRef,
                'client_secret' => $clientSecret,
                'order_ref' => $order['order_ref'],
            ]);

        case 'capture_order':
            $providerOrderRef = (string)($input['provider_order_ref'] ?? '');
            $orderId = (int)($_SESSION['pending_order_id'] ?? 0);

            $pendingError = CheckoutPendingPayment::error(
                sessionOrderId: $orderId,
                sessionRef: $_SESSION['pending_payment_ref'] ?? '',
                submittedRef: $providerOrderRef,
            );
            if ($pendingError === CheckoutPendingPayment::NO_MATCH) {
                \error_log("Confirmation attempted for {$providerOrderRef} but session expects " . ($_SESSION['pending_payment_ref'] ?? ''));
            }
            if ($pendingError) $this->osmium->jsonError($pendingError);

            $stored = $orderService->getOrder($orderId);
            if (!$stored) $this->osmium->jsonError('We could not find your order. Please contact us before trying again.');

            $paymentState = CheckoutOrderPaymentState::of((string)$stored['status']);

            $orderClosed = $paymentState === CheckoutOrderPaymentState::CLOSED;
            if ($orderClosed) $this->osmium->jsonError(CheckoutOrderPaymentState::CLOSED_MESSAGE);

            $alreadyPaid = $paymentState === CheckoutOrderPaymentState::PAID;
            if ($alreadyPaid) {
                $this->osmium->jsonSuccess([
                    'success' => true,
                    'order_ref' => $stored['order_ref'],
                    'already_paid' => true,
                ]); // A double-clicked button lands here rather than capturing twice
            }

            // Ask the provider what actually happened, and flatten its answer
            // into one shape so every check below is provider-agnostic. This is
            // a normalisation at the edge, not a shared interface: PayPal's
            // service captures, Stripe's is asked what the browser already
            // did, and each returns the same shape.
            $payment = $serviceHooks->handle('payment.confirm', [
                'provider' => $selectedProvider,
                'ref' => $providerOrderRef,
                'osmium' => $this->osmium,
            ]);

            $noHandler = $payment === null;
            if ($noHandler) throw new ShopPaymentException("No service confirmed a payment for provider '{$selectedProvider}'");

            $capturedAmount = (float)$payment['amount'];
            $capturedCurrency = (string)$payment['currency'];
            $captureRef = (string)$payment['capture_ref'];

            $outcome = ShopCaptureCheck::outcome(order: $stored, payment: $payment);

            if ($outcome === ShopCaptureCheck::MISMATCH) {
                \error_log("Capture mismatch on order {$stored['order_ref']}: captured {$capturedAmount} {$capturedCurrency}, expected {$stored['total_inc_tax']} {$stored['currency']}");
                $this->osmium->jsonError('There was a problem confirming your payment. Please contact us before trying again — do not pay twice.'); // Order stays pending for a human to reconcile
            }

            // A pending capture is not a failure, and must never be reported as
            // one. Card payments sit in PENDING far more often than PayPal
            // balance payments do — risk review, echeck — and they usually
            // complete moments later, at which point the webhook marks the
            // order paid. Telling the customer to "try again" here would invite
            // a second payment for an order that was about to succeed.
            if ($outcome === ShopCaptureCheck::PENDING) {
                \error_log("Capture for order {$stored['order_ref']} is PENDING — leaving it for the webhook");

                $this->osmium->jsonSuccess([
                    'success' => true,
                    'order_ref' => $stored['order_ref'],
                    'pending' => true, // The order stays pending: the webhook resolves it either way
                ]);
            }

            if ($outcome === ShopCaptureCheck::FAILED) {
                \error_log("Payment for order {$stored['order_ref']} came back as {$payment['status']}");
                $this->osmium->jsonError('Your payment was not completed. Please try again or contact us.'); // Genuinely declined or failed, so retrying is the right advice
            }

            $payerEmail = $payment['payer_email'];

            $invoiceNumber = $orderService->markPaid(
                orderId: $orderId,
                captureRef: $captureRef,
                payerEmail: $payerEmail,
            );

            // Null means another caller (a retried capture, or the webhook)
            // already resolved this order — that caller already sent the
            // confirmation, so this one must not send a second copy.
            $invoiceIssued = $invoiceNumber !== null;
            if ($invoiceIssued) {
                $mailer = new ShopOrderMailer($this->osmium->config, $finance);
                $mailer->sendOrderConfirmation($orderService->getOrder($orderId), $invoiceNumber);
            }

            $basketService->markConverted($sessionId);

            // Handed to the thank-you page once, as plain figures for services that
            // report a sale in the browser (see the osmium:shop-event contract)
            $paidOrder = $orderService->getOrder($orderId);
            $_SESSION['purchase_event'] = ShopPurchaseEvent::fromOrder($paidOrder);

            unset($_SESSION['pending_order_id'], $_SESSION['pending_payment_ref'], $_SESSION['applied_coupon_code']);

            $this->osmium->jsonSuccess([
                'success' => true,
                'order_ref' => $stored['order_ref'],
                'invoice_number' => $invoiceNumber,
            ]);

        default:
            $this->osmium->jsonError('Unknown action');
    }
} catch (ShopOrderException $e) {
    $this->osmium->jsonError($e->getMessage()); // Written for the customer
} catch (ShopPaymentException $e) {
    \error_log('Payment failed: ' . $e->getMessage());

    // Staging has no log access, so the provider's own complaint is returned
    // there to make failures diagnosable. Live never sees this: the customer
    // gets the plain message and the detail stays in the log.
    $response = ['success' => false, 'error' => 'We could not start the payment. Please try again, or contact us if it keeps happening.'];
    if ($isStaging) $response['diagnostic'] = $e->getMessage();

    $this->osmium->jsonSuccess($response);
} catch (\Exception $e) {
    \error_log('Checkout action failed: ' . $e->getMessage());
    $this->osmium->jsonError('Sorry, something went wrong. Please try again.');
}
