<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Tests\Integration\DatabaseTestCase;

/**
 * Integration tests that need shop rows (a product, a product status). Lives with the Osmium Shop service because
 * core's tests know nothing of the shop; any service whose tests build on the shop uses this class too.
 */
abstract class ShopDatabaseTestCase extends DatabaseTestCase
{
    /**
     * The id of a product status that is (or is not) available, reusing an existing one.
     * productstatus.id is a tinyint and rolled-back inserts still use up auto-increment
     * values, so inserting a status per test eventually runs the table out of ids.
     * An unavailable status skips ids 3 and 4, which product search always hides.
     */
    protected function statusId(bool $available): int
    {
        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(
            sql: "SELECT id FROM {$prefix}productstatus WHERE product_available = :available AND deleted_at IS NULL" . ($available ? '' : ' AND id NOT IN (3, 4)') . " ORDER BY id LIMIT 1",
            bindings: [':available' => $available ? 1 : 0],
        )->single();
        if ($row !== false) return (int) $row['id'];

        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productstatus (descr_short, descr_long, product_available, restocking, schema_ref, list, enabled)
                VALUES ('Test', 'Test status', :available, 0, '', 1, 1)",
            bindings: [':available' => $available ? 1 : 0],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    protected function createTestProduct(array $overrides = []): int
    {
        $defaults = [
            'brand_id' => 1,
            'status_id' => 1,
            'model_name' => 'Test Product ' . uniqid(),
            'reference' => 'TEST-' . uniqid(),
            'price_exc_tax' => 100.00,
            'rrp_exc_tax' => 99.00,
            'descr' => 'Test description',
            'descr2' => 'Test description 2',
            'descr3' => 'Test description 3',
        ];

        $data = array_merge($defaults, $overrides);

        $sql = "INSERT INTO {$this->db->tablePrefix()}products
                (brand_id, status_id, model_name, reference, price_exc_tax, rrp_exc_tax, descr, descr2, descr3)
                VALUES (:brand_id, :status_id, :model_name, :reference, :price_exc_tax, :rrp_exc_tax, :descr, :descr2, :descr3)";

        $this->db->prepare(sql: $sql, bindings: [
            ':brand_id' => $data['brand_id'],
            ':status_id' => $data['status_id'],
            ':model_name' => $data['model_name'],
            ':reference' => $data['reference'],
            ':price_exc_tax' => $data['price_exc_tax'],
            ':rrp_exc_tax' => $data['rrp_exc_tax'],
            ':descr' => $data['descr'],
            ':descr2' => $data['descr2'],
            ':descr3' => $data['descr3'],
        ])->execute();

        return (int)$this->db->lastInsertId();
    }
}
