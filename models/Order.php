<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Order extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /**
     * One page of orders, newest first.
     *
     * @param string $filterClause Safe WHERE clause — caller must validate against a whitelist before passing.
     * @return array<int, array<string, mixed>>
     */
    public function getList(string $filterClause, int $page, int $perPage): array
    {
        $sql = $this->loadSqlFile('order-list.sql', ['{STATUS_FILTER}' => $filterClause]);
        $this->database->query($sql);
        $this->database->bind(param: ':limit', value: $perPage);
        $this->database->bind(param: ':offset', value: ($page - 1) * $perPage);
        return $this->database->resultset();
    }

    /**
     * @param string $filterClause Safe WHERE clause — caller must validate against a whitelist before passing.
     */
    public function getCount(string $filterClause = ''): int
    {
        $sql = $this->loadSqlFile('order-count.sql', ['{STATUS_FILTER}' => $filterClause]);
        $this->database->query($sql);
        $result = $this->database->single();
        return (int)($result['total'] ?? 0);
    }

    public function getStats(): ?array
    {
        return $this->fetchOne('order-stats.sql');
    }

    /**
     * Counts and values per status for today, 7 days and 30 days, with no customer data.
     *
     * @return array<string, array<string, array{count: int, value: float}>> period => status => totals
     */
    public function getPeriodTotals(): array
    {
        return self::shapePeriodTotals($this->fetchAll('order-period-totals.sql'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows one row per status
     * @return array<string, array<string, array{count: int, value: float}>>
     */
    public static function shapePeriodTotals(array $rows): array
    {
        $periods = ['today' => [], 'last_7_days' => [], 'last_30_days' => []];
        $columns = ['today' => 'today', 'last_7_days' => 'week', 'last_30_days' => 'month'];

        foreach ($rows as $row) {
            foreach ($columns as $period => $prefix) {
                $count = (int) $row["{$prefix}_count"];
                if ($count === 0) {
                    continue;
                }
                $periods[$period][$row['status']] = ['count' => $count, 'value' => (float) $row["{$prefix}_value"]];
            }
        }

        return $periods;
    }

    /**
     * The newest orders with no customer details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAnonymous(int $limit): array
    {
        $sql = $this->loadSqlFile('order-recent-anonymous.sql');
        $this->database->query($sql);
        $this->database->bind(param: ':limit', value: $limit);

        return $this->database->resultset();
    }

    public function getById(int $id): ?array
    {
        return $this->fetchOne('order-get.sql', [':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(int $orderId): array
    {
        return $this->fetchAll('order-items-list.sql', [':order_id' => $orderId]);
    }

    public function markDispatched(int $id, ?string $carrier, ?string $trackingRef): bool
    {
        $sql = $this->loadSqlFile('order-mark-dispatched.sql');
        $this->database->query($sql);
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':tracking_carrier', value: $carrier);
        $this->database->bind(param: ':tracking_ref', value: $trackingRef);
        $this->database->execute();
        return $this->database->rowCount() > 0;
    }

    public function recordRefund(int $id): bool
    {
        $sql = $this->loadSqlFile('order-record-refund.sql');
        $this->database->query($sql);
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
        return $this->database->rowCount() > 0;
    }

    public function appendNote(int $id, string $note): void
    {
        $this->run('order-append-note.sql', [':id' => $id, ':note' => $note]);
    }
}
