<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Models;

class CheckoutOrder extends CheckoutModel
{
    /** @var array<string, list<string>> target status => the statuses an order may move from */
    private const ALLOWED_FROM = [
        'failed' => ['pending'],
        'cancelled' => ['pending'],
        'refunded' => ['paid', 'dispatched'],
    ];

    /** @param array<string, mixed> $bindings every named parameter order-insert.sql expects */
    public function insert(array $bindings): int
    {
        $this->run('order-insert.sql', $bindings);

        return (int)$this->database->lastInsertId();
    }

    public function setRef(int $orderId, string $orderRef): void
    {
        $this->run('order-set-ref.sql', [':id' => $orderId, ':order_ref' => $orderRef]);
    }

    /** @param array<string, mixed> $bindings every named parameter order-item-insert.sql expects */
    public function insertItem(array $bindings): int
    {
        $this->run('order-item-insert.sql', $bindings);

        return (int)$this->database->lastInsertId();
    }

    public function insertItemPart(int $orderItemId, int $productId, ?int $optionValueId, int $quantity): void
    {
        $this->run('order-item-part-insert.sql', [
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':option_value_id' => $optionValueId,
            ':quantity' => $quantity,
        ]);
    }

    /** Returns the pre-increment counter value (LAST_INSERT_ID(expr) in the UPDATE). */
    public function takeInvoiceNumber(): int
    {
        $this->run('invoice-counter-take.sql');

        return (int)$this->database->lastInsertId();
    }

    public function setPaymentReference(
        int $orderId,
        string $provider,
        string $paymentOrderRef,
        bool $isTestPayment,
    ): void {
        $this->run('order-set-payment-ref.sql', [
            ':id' => $orderId,
            ':payment_provider' => $provider,
            ':payment_order_ref' => $paymentOrderRef,
            ':is_test_payment' => $isTestPayment ? 1 : 0,
        ]);
    }

    public function lockById(int $orderId): ?array
    {
        return $this->fetchOne('order-lock-by-id.sql', [':id' => $orderId]);
    }

    public function markPaid(int $orderId, int $invoiceNumber, string $captureRef, ?string $payerEmail): void
    {
        $this->run('order-mark-paid.sql', [
            ':id' => $orderId,
            ':invoice_number' => $invoiceNumber,
            ':payment_capture_ref' => $captureRef,
            ':payment_payer_email' => $payerEmail,
        ]);
    }

    /**
     * Move an order out of a state it is allowed to leave: failed and cancelled
     * only from pending, refunded only from paid or dispatched. Anything else
     * is a no-op, so a late webhook cannot undo a payment. 'paid' is refused
     * outright: it has its own guarded path (markPaid) that issues the invoice.
     *
     * @throws \InvalidArgumentException for a status this method does not set
     */
    public function markStatus(int $orderId, string $status): void
    {
        $allowedFrom = self::ALLOWED_FROM[$status] ?? null;
        $statusNotSettable = $allowedFrom === null;
        if ($statusNotSettable) throw new \InvalidArgumentException("Order status '{$status}' cannot be set this way.");

        $this->run('order-mark-status.sql', [
            ':id' => $orderId,
            ':status' => $status,
            ':from_a' => $allowedFrom[0],
            ':from_b' => $allowedFrom[1] ?? $allowedFrom[0],
        ]);
    }

    public function findByPaymentReference(string $paymentOrderRef): ?array
    {
        return $this->fetchOne('order-find-by-payment-ref.sql', [':payment_order_ref' => $paymentOrderRef]);
    }

    public function getById(int $orderId): ?array
    {
        return $this->fetchOne('order-get-by-id.sql', [':id' => $orderId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(int $orderId): array
    {
        return $this->fetchAll('order-items-get.sql', [':order_id' => $orderId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getItemParts(int $orderId): array
    {
        return $this->fetchAll('order-item-parts-get.sql', [':order_id' => $orderId]);
    }
}
