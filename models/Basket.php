<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Basket extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** @return array<int, array<string, mixed>> */
    public function listActive(int $abandonedHours): array
    {
        return $this->fetchAll('basket-list-active.sql', [':hours' => $abandonedHours]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listAbandoned(int $abandonedHours): array
    {
        return $this->fetchAll('basket-list-abandoned.sql', [':hours' => $abandonedHours]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listConverted(): array
    {
        return $this->fetchAll('basket-list-converted.sql');
    }

    /** @return array<string, mixed>|null */
    public function stats(int $abandonedHours): ?array
    {
        return $this->fetchOne('basket-stats.sql', [':hours' => $abandonedHours]);
    }

    public function activeValue(): float
    {
        $row = $this->fetchOne('basket-active-value.sql');

        return (float)($row['total_value'] ?? 0);
    }

    public function convertedValue24h(): float
    {
        $row = $this->fetchOne('basket-converted-value.sql');
        return (float)($row['total_value'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(int $basketId): array
    {
        return $this->fetchAll('basket-get-items.sql', [':basket_id' => $basketId]);
    }

    public function delete(int $id): void
    {
        $this->run('basket-delete.sql', [':id' => $id]);
    }

    /**
     * Delete active baskets not touched for the given number of days.
     *
     * @return int Number of baskets deleted
     */
    public function deleteOldAbandoned(int $days): int
    {
        $this->run('basket-delete-old-abandoned.sql', [':days' => $days]);

        return $this->database->rowCount();
    }
}
