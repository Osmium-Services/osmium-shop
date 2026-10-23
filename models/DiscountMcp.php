<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Changelog;
use Osmium\Modules\Admin\Models\Discount;
use Osmium\Modules\Admin\Models\Product;

/**
 * Core's discount tools for the /mcp endpoint: a list of the shop's discounts with how each one is doing (live,
 * scheduled, expired, disabled or used up), its usage and the number of products it covers, create, and delete and
 * restore. A discount made here is always switched off, so it does nothing until someone enables it in the admin,
 * and it follows the same rules as the admin form. Delete is the bin, never permanent, and takes the discount off
 * sale straight away; restore brings it back with its old settings, so it applies again if it is switched on and
 * inside its dates. The write tools appear only when writes are switched on. There is no edit and no way to switch
 * a discount on: it changes what customers pay, so those stay in the admin. Coupon codes are shown because they are
 * admin data, as on the admin list.
 */
class DiscountMcp
{
    private const RECORD_TYPE = 'discount';
    private const MAX_NAME_LENGTH = 255;
    private const CLEARABLE_FIELDS = ['starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer', 'min_spend_inc_tax'];
    private const UPDATABLE_FIELDS = ['name', 'value_type', 'value', 'free_delivery', 'code', 'product_ids', 'starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer', 'min_spend_inc_tax'];
    private const COUPON_ONLY_FIELDS = ['code', 'usage_limit', 'usage_limit_per_customer', 'min_spend_inc_tax'];

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        $idAndName = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The discount id from discounts_list'],
                'expected_name' => ['type' => 'string', 'description' => "The discount's exact name as listed. The call is refused if it does not match the id"],
            ],
            'required' => ['id', 'expected_name'],
        ];

        return [
            [
                'name' => 'discounts_list',
                'description' => 'Shop discounts (sitewide, per-product and coupon) with id, name, type, coupon code, value, start and end dates, status (live, scheduled, expired, disabled or used up), times used, usage limit, uses allowed per customer and how many products a per-product discount covers and the minimum spend of a coupon (inc. VAT, before the discount and delivery). Newest first. Set deleted to true to list the discounts in the bin instead. Read only.',
                'inputSchema' => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'boolean', 'description' => 'List deleted discounts (the bin)']]],
                'handler' => self::class . '::discountsList',
            ],
            [
                'name' => 'discount_create',
                'description' => 'Create a discount. It is ALWAYS created switched off (disabled) and does nothing until someone enables it in the admin (Shop > Discounts); this tool cannot switch one on. type is sitewide (the whole basket), product (the products in product_ids) or coupon (a code the customer enters, needs code). value_type is percent (value 1 to 100) or fixed (value in pounds). Set free_delivery true to also waive the delivery charge while the discount applies (any type). Coupons can also have usage_limit, usage_limit_per_customer and min_spend_inc_tax (the basket total including VAT, before the discount and delivery). Dates are YYYY-MM-DD or YYYY-MM-DD HH:MM, UK time; a date alone starts at the start of that day and ends at the end of it. The same rules as the admin form apply. Undo with discount_delete.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The discount name, up to ' . self::MAX_NAME_LENGTH . ' characters, one line'],
                        'type' => ['type' => 'string', 'enum' => ['sitewide', 'product', 'coupon']],
                        'value_type' => ['type' => 'string', 'enum' => ['percent', 'fixed']],
                        'value' => ['type' => 'number', 'description' => 'The amount: a percentage (up to 100) or pounds'],
                        'free_delivery' => ['type' => 'boolean', 'description' => 'Also waive the delivery charge while this discount applies'],
                        'code' => ['type' => 'string', 'description' => 'Coupons only: the code, letters, numbers, hyphens and underscores. It is made upper case'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Product discounts only: ids of live products, such as from products_attention'],
                        'starts_at' => ['type' => 'string', 'description' => 'Optional start, YYYY-MM-DD or YYYY-MM-DD HH:MM'],
                        'ends_at' => ['type' => 'string', 'description' => 'Optional end, YYYY-MM-DD or YYYY-MM-DD HH:MM'],
                        'usage_limit' => ['type' => 'integer', 'description' => 'Coupons only: total uses allowed, at least 1'],
                        'usage_limit_per_customer' => ['type' => 'integer', 'description' => 'Coupons only: uses allowed per customer, at least 1'],
                        'min_spend_inc_tax' => ['type' => 'number', 'description' => 'Coupons only: minimum basket total in pounds including VAT'],
                    ],
                    'required' => ['name', 'type', 'value_type'],
                ],
                'write' => true,
                'handler' => self::class . '::create',
            ],
            [
                'name' => 'discount_update',
                'description' => 'Change a discount that is switched OFF (disabled). A discount that is switched on cannot be edited here: switch it off in the admin first. It stays switched off, and this tool cannot switch one on. Give the id and the exact name, then only the fields to change; the rest stay. The type cannot be changed. clear removes an optional field (starts_at, ends_at, usage_limit, usage_limit_per_customer, min_spend_inc_tax). The same rules as create and the admin form apply, and the reply says what each changed field was, to undo it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The discount id from discounts_list'],
                        'expected_name' => ['type' => 'string', 'description' => "The discount's exact current name as listed. The call is refused if it does not match the id"],
                        'name' => ['type' => 'string', 'description' => 'The new name'],
                        'value_type' => ['type' => 'string', 'enum' => ['percent', 'fixed']],
                        'value' => ['type' => 'number', 'description' => 'The new amount: a percentage (up to 100) or pounds'],
                        'free_delivery' => ['type' => 'boolean', 'description' => 'Whether to also waive the delivery charge'],
                        'code' => ['type' => 'string', 'description' => 'Coupons only: the new code'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Product discounts only: the full new list of live product ids'],
                        'starts_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM'],
                        'ends_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM'],
                        'usage_limit' => ['type' => 'integer', 'description' => 'Coupons only: total uses allowed, at least 1'],
                        'usage_limit_per_customer' => ['type' => 'integer', 'description' => 'Coupons only: uses allowed per customer, at least 1'],
                        'min_spend_inc_tax' => ['type' => 'number', 'description' => 'Coupons only: minimum basket total in pounds including VAT'],
                        'clear' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::CLEARABLE_FIELDS], 'description' => 'Optional fields to remove'],
                    ],
                    'required' => ['id', 'expected_name'],
                ],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::update',
            ],
            [
                'name' => 'discount_delete',
                'description' => 'Move a discount to the bin (a soft delete: nothing is lost). It stops applying to orders straight away, and a coupon code stops working. Give the discount id and its exact name. Undo with discount_restore.',
                'inputSchema' => $idAndName,
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::delete',
            ],
            [
                'name' => 'discount_restore',
                'description' => 'Bring a deleted discount back from the bin with its old settings. It applies to orders again straight away if it is switched on and inside its dates: the reply gives its status. Give the discount id and its exact name.',
                'inputSchema' => $idAndName,
                'write' => true,
                'handler' => self::class . '::restore',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function discountsList(array $call): array
    {
        $discounts = new Discount($call['dataSource'], 'discounts');
        $showDeleted = ($call['arguments']['deleted'] ?? false) === true;
        $rows = $showDeleted ? $discounts->listDeleted() : $discounts->listWithCounts();

        return ['total' => \count($rows), 'discounts' => self::shapeList($rows, new \DateTimeImmutable())];
    }

    /**
     * Makes a discount that is switched off, after the same checks as the admin form plus the checks an MCP caller
     * needs (the right kinds of value, and no field that does not belong to the type, rather than ignoring it).
     *
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function create(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        $discounts = new Discount($call['dataSource'], 'discounts');
        $products = new Product($call['dataSource'], 'products');

        $liveProducts = \array_column($products->listForPicker(), 'model_name', 'id');
        $fields = self::createFields($arguments, $liveProducts);
        $error = DiscountRules::validate(
            name: $fields['name'],
            type: $fields['type'],
            code: (string) $fields['code'],
            valueType: $fields['value_type'],
            value: (float) $fields['value'],
            startsAt: $fields['starts_at'],
            endsAt: $fields['ends_at'],
            productIds: $fields['product_ids'],
            codeInUse: fn(string $code): bool => $discounts->codeExistsAnywhere($code), // The bin counts: the database keeps codes unique across it
            minSpendIncTax: $fields['min_spend_inc_tax'],
        );
        if ($error !== null) throw new McpToolException($error);

        $id = $discounts->insert([
            'name' => $fields['name'],
            'type' => $fields['type'],
            'code' => $fields['code'],
            'value_type' => $fields['value_type'],
            'value' => $fields['value'],
            'free_delivery' => (int) $fields['free_delivery'],
            'starts_at' => $fields['starts_at'],
            'ends_at' => $fields['ends_at'],
            'usage_limit' => $fields['usage_limit'],
            'usage_limit_per_customer' => $fields['usage_limit_per_customer'],
            'min_spend_inc_tax' => $fields['min_spend_inc_tax'],
            'active' => 0, // Never switched on from here
        ]);
        if ($fields['type'] === 'product') $discounts->replaceProducts($id, $fields['product_ids']);

        self::changelog($call)->log(description: 'Created discount: ' . $fields['name'], recordType: self::RECORD_TYPE, recordId: $id);

        $created = $discounts->getById($id);

        return [
            'created' => true,
            'id' => $id,
            'name' => $fields['name'],
            'status' => self::status($created, new \DateTimeImmutable()),
            'note' => 'It is switched off and does nothing yet. Enable it in the admin (Shop > Discounts) when it is ready.',
            'products' => \array_map(fn(int $productId): string => (string) ($liveProducts[$productId] ?? $productId), $fields['product_ids']),
            'undo' => "discount_delete with id {$id} and the same name",
        ];
    }

    /**
     * Edits a discount that is switched off. The stored discount and the changes are merged into one set of create
     * arguments and checked as a whole, so every rule create has applies, and the discount stays switched off.
     *
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function update(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expected] = self::idAndName(['id' => $arguments['id'] ?? null, 'expected_name' => $arguments['expected_name'] ?? null]);
        $discounts = new Discount($call['dataSource'], 'discounts');
        $products = new Product($call['dataSource'], 'products');

        $discount = self::requireDiscount($discounts->getById($id), isDeleted: false, expected: $expected, id: $id);
        if ((int) $discount['active'] === 1) {
            throw new McpToolException('This discount is switched on, so it cannot be edited here. Switch it off in the admin (Shop > Discounts) first.');
        }

        $changes = \array_diff_key($arguments, ['id' => 1, 'expected_name' => 1]);
        $clear = self::clearList($changes);
        unset($changes['clear']);
        $unknown = \array_diff(\array_keys($changes), self::UPDATABLE_FIELDS);
        if ($unknown !== []) {
            throw new McpToolException('These cannot be changed here: ' . \implode(', ', $unknown) . '. The type and whether a discount is switched on stay in the admin.');
        }
        $bothSetAndCleared = \array_values(\array_intersect(\array_keys($changes), $clear));
        if ($bothSetAndCleared !== []) throw new McpToolException('A field cannot be both given and cleared: ' . \implode(', ', $bothSetAndCleared) . '.');
        if ($changes === [] && $clear === []) throw new McpToolException('Give at least one thing to change.');

        $liveProducts = \array_column($products->listForPicker(), 'model_name', 'id');
        $currentProductIds = \array_map('intval', $discounts->getProductIds($id));
        $currentArguments = self::argumentsFromRow($discount, $currentProductIds);

        $merged = \array_diff_key($changes + $currentArguments, \array_flip($clear));

        $before = self::createFields($currentArguments, $liveProducts + \array_fill_keys($currentProductIds, ''));
        $productsToAccept = \array_key_exists('product_ids', $changes) ? $liveProducts : $liveProducts + \array_fill_keys($currentProductIds, ''); // Products it already covers stay, even if one has since been deleted
        $after = self::createFields($merged, $productsToAccept);

        $error = DiscountRules::validate(
            name: $after['name'],
            type: $after['type'],
            code: (string) $after['code'],
            valueType: $after['value_type'],
            value: (float) $after['value'],
            startsAt: $after['starts_at'],
            endsAt: $after['ends_at'],
            productIds: $after['product_ids'],
            codeInUse: fn(string $code): bool => $code !== (string) $before['code'] && $discounts->codeExistsAnywhere($code),
            minSpendIncTax: $after['min_spend_inc_tax'],
        );
        if ($error !== null) throw new McpToolException($error);

        $changed = \array_keys(\array_filter($after, fn($value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
        if ($changed === []) throw new McpToolException('Nothing would change: those values are already set.');

        $discounts->update($id, [
            'name' => $after['name'],
            'type' => $after['type'],
            'code' => $after['code'],
            'value_type' => $after['value_type'],
            'value' => $after['value'],
            'free_delivery' => (int) $after['free_delivery'],
            'starts_at' => $after['starts_at'],
            'ends_at' => $after['ends_at'],
            'usage_limit' => $after['usage_limit'],
            'usage_limit_per_customer' => $after['usage_limit_per_customer'],
            'min_spend_inc_tax' => $after['min_spend_inc_tax'],
            'active' => 0, // Never switched on from here
        ]);
        if ($after['type'] === 'product' && \in_array('product_ids', $changed, true)) $discounts->replaceProducts($id, $after['product_ids']);

        self::changelog($call)->log(description: 'Updated discount: ' . $after['name'], recordType: self::RECORD_TYPE, recordId: $id);

        $was = [];
        foreach ($changed as $field) {
            $was[$field] = $before[$field];
        }

        return [
            'updated' => true,
            'id' => $id,
            'name' => $after['name'],
            'status' => 'disabled',
            'changed' => $changed,
            'was' => $was,
            'note' => 'It is still switched off. Enable it in the admin (Shop > Discounts) when it is ready.',
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<int, string> the fields to clear
     */
    private static function clearList(array $changes): array
    {
        if (!\array_key_exists('clear', $changes)) return [];
        $given = $changes['clear'];
        $valid = \is_array($given) && \array_filter($given, fn($field): bool => !\in_array($field, self::CLEARABLE_FIELDS, true)) === [];
        if (!$valid) throw new McpToolException('clear can hold: ' . \implode(', ', self::CLEARABLE_FIELDS) . '.');

        return \array_values(\array_unique($given));
    }

    /**
     * A stored discount as create arguments, so a merged edit can be checked by the same rules as a new one.
     *
     * @param array<string, mixed> $row
     * @param array<int, int> $productIds
     * @return array<string, mixed>
     */
    private static function argumentsFromRow(array $row, array $productIds): array
    {
        $arguments = ['name' => (string) $row['name'], 'type' => (string) $row['type'], 'value_type' => (string) $row['value_type']];
        $arguments['value'] = (float) $row['value'];
        if (!empty($row['free_delivery'])) $arguments['free_delivery'] = true;
        if ($row['type'] === 'coupon') $arguments['code'] = (string) $row['code'];
        if ($row['type'] === 'product') $arguments['product_ids'] = $productIds;
        foreach (['starts_at', 'ends_at'] as $field) {
            if (!empty($row[$field])) $arguments[$field] = (string) $row[$field];
        }
        if ($row['type'] === 'coupon') {
            foreach (['usage_limit', 'usage_limit_per_customer'] as $field) {
                if (isset($row[$field]) && (int) $row[$field] >= 1) $arguments[$field] = (int) $row[$field];
            }
            if (isset($row['min_spend_inc_tax']) && (float) $row['min_spend_inc_tax'] > 0) $arguments['min_spend_inc_tax'] = (float) $row['min_spend_inc_tax'];
        }

        return $arguments;
    }

    /**
     * The arguments as clean values, or a refusal saying what is wrong.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $liveProducts live product names by id
     * @return array<string, mixed>
     */
    public static function createFields(array $arguments, array $liveProducts): array
    {
        $name = $arguments['name'] ?? null;
        if (!\is_string($name) || \trim($name) === '') throw new McpToolException('Give the discount a name.');
        $name = \trim($name);
        if (\mb_strlen($name) > self::MAX_NAME_LENGTH) throw new McpToolException('A name can be up to ' . self::MAX_NAME_LENGTH . ' characters.');
        if (\preg_match('/[\x00-\x1f\x7f]/', $name) === 1) throw new McpToolException('The name must be one line of plain text.');

        $type = $arguments['type'] ?? null;
        if (!\in_array($type, ['sitewide', 'product', 'coupon'], true)) throw new McpToolException('The type must be sitewide, product or coupon.');
        $valueType = $arguments['value_type'] ?? null;
        if (!\in_array($valueType, ['percent', 'fixed'], true)) throw new McpToolException('The value_type must be percent or fixed.');

        if ($type !== 'coupon') {
            foreach (self::COUPON_ONLY_FIELDS as $field) {
                if (\array_key_exists($field, $arguments)) throw new McpToolException("{$field} only applies to a coupon.");
            }
        }
        if ($type !== 'product' && \array_key_exists('product_ids', $arguments)) throw new McpToolException('product_ids only applies to a product discount.');

        $given = $arguments['value'] ?? null;
        if (!\is_int($given) && !\is_float($given)) throw new McpToolException('Give the value as a number.');
        $value = \round((float) $given, 2);

        $freeDelivery = $arguments['free_delivery'] ?? false;
        if (!\is_bool($freeDelivery)) throw new McpToolException('free_delivery must be true or false.');

        $productIds = [];
        if ($type === 'product') {
            $given = $arguments['product_ids'] ?? null;
            if (!\is_array($given) || $given === [] || \array_filter($given, fn($id): bool => !\is_int($id) || $id < 1) !== []) {
                throw new McpToolException('Give product_ids as a list of product ids.');
            }
            $productIds = \array_values(\array_unique($given));
            \sort($productIds); // The order means nothing
            $missing = \array_values(\array_diff($productIds, \array_keys($liveProducts)));
            if ($missing !== []) throw new McpToolException('No live product with id ' . \implode(', ', $missing) . '.');
        }

        $code = null;
        if ($type === 'coupon') {
            $given = $arguments['code'] ?? null;
            if (!\is_string($given) || \trim($given) === '') throw new McpToolException('A coupon needs a code.');
            $code = \strtoupper(\trim($given));
        }

        return [
            'name' => $name,
            'type' => $type,
            'value_type' => $valueType,
            'value' => $value,
            'free_delivery' => $freeDelivery,
            'code' => $code,
            'product_ids' => $productIds,
            'starts_at' => self::when($arguments, 'starts_at', endOfDay: false),
            'ends_at' => self::when($arguments, 'ends_at', endOfDay: true),
            'usage_limit' => self::wholeNumber($arguments, 'usage_limit'),
            'usage_limit_per_customer' => self::wholeNumber($arguments, 'usage_limit_per_customer'),
            'min_spend_inc_tax' => self::money($arguments, 'min_spend_inc_tax'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function when(array $arguments, string $field, bool $endOfDay): ?string
    {
        if (!\array_key_exists($field, $arguments)) return null;
        $given = $arguments[$field];
        if (!\is_string($given)) throw new McpToolException("Give {$field} as YYYY-MM-DD or YYYY-MM-DD HH:MM.");
        $given = \trim($given);
        if ($given === '') return null;

        $isDateOnly = \preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) === 1;
        $format = $isDateOnly ? 'Y-m-d' : (\substr_count($given, ':') === 2 ? 'Y-m-d H:i:s' : 'Y-m-d H:i'); // Seconds are accepted so a stored date can be given back as it is
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $given);
        $exact = $parsed !== false && $parsed->format($format) === $given;
        if (!$exact) throw new McpToolException("Give {$field} as a real date, YYYY-MM-DD or YYYY-MM-DD HH:MM.");

        return $isDateOnly && $endOfDay ? $parsed->format('Y-m-d') . ' 23:59:59' : $parsed->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function wholeNumber(array $arguments, string $field): ?int
    {
        if (!\array_key_exists($field, $arguments)) return null;
        $given = $arguments[$field];
        if (!\is_int($given) || $given < 1) throw new McpToolException("{$field} must be a whole number of at least 1.");

        return $given;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function money(array $arguments, string $field): ?float
    {
        if (!\array_key_exists($field, $arguments)) return null;
        $given = $arguments[$field];
        if (!\is_int($given) && !\is_float($given)) throw new McpToolException("{$field} must be a number of pounds.");
        $amount = \round((float) $given, 2);
        if ($amount <= 0) throw new McpToolException("{$field} must be more than zero. Leave it out for no minimum.");

        return $amount;
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function delete(array $call): array
    {
        [$id, $expected] = self::idAndName($call['arguments'] ?? []);
        $discounts = new Discount($call['dataSource'], 'discounts');
        $discount = self::requireDiscount($discounts->getById($id), isDeleted: false, expected: $expected, id: $id);

        $discounts->softDelete($id);
        self::changelog($call)->log(description: 'Deleted discount: ' . $discount['name'], recordType: self::RECORD_TYPE, recordId: $id);

        return ['deleted' => true, 'id' => $id, 'name' => (string) $discount['name'], 'undo' => "discount_restore with id {$id} and the same name"];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function restore(array $call): array
    {
        [$id, $expected] = self::idAndName($call['arguments'] ?? []);
        $discounts = new Discount($call['dataSource'], 'discounts');
        $discount = self::requireDiscount($discounts->getById($id), isDeleted: true, expected: $expected, id: $id);

        $discounts->restore($id);
        self::changelog($call)->log(description: 'Restored discount: ' . $discount['name'], recordType: self::RECORD_TYPE, recordId: $id);

        return ['restored' => true, 'id' => $id, 'name' => (string) $discount['name'], 'status' => self::status($discount, new \DateTimeImmutable())];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string} the id and the trimmed expected name
     */
    public static function idAndName(array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $name = $arguments['expected_name'] ?? null;
        $valid = \is_int($id) && $id > 0 && \is_string($name) && \trim($name) !== '';
        if (!$valid) throw new McpToolException('Give the discount id (a whole number) and its exact expected_name.');

        return [$id, \trim($name)];
    }

    /**
     * Refuses unless the discount exists, is in the state the tool needs (live to delete, in the bin to restore)
     * and its name is exactly the one given, so a slip of the id cannot change the wrong discount.
     *
     * @param array<string, mixed>|null $discount
     * @return array<string, mixed>
     */
    private static function requireDiscount(?array $discount, bool $isDeleted, string $expected, int $id): array
    {
        $inRightState = $discount !== null && (($discount['deleted_at'] ?? null) !== null) === $isDeleted;
        if (!$inRightState) throw new McpToolException("No matching discount with id {$id}. Check discounts_list.");
        if (\trim((string) $discount['name']) !== $expected) {
            throw new McpToolException("The name does not match discount {$id}. Check discounts_list and try again.");
        }

        return $discount;
    }

    /** @param array<string, mixed> $call */
    private static function changelog(array $call): Changelog
    {
        $changelog = new Changelog($call['dataSource'], 'changelog');
        $changelog->setGetUser(fn(): array => ['username' => $call['actor']]);

        return $changelog;
    }

    /**
     * @param array<int, array<string, mixed>> $rows from discount-list-with-counts.sql or discount-list-deleted.sql
     * @return array<int, array<string, mixed>>
     */
    public static function shapeList(array $rows, \DateTimeImmutable $now): array
    {
        $list = [];
        foreach ($rows as $row) {
            $isBin = isset($row['deleted_at']);
            $entry = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'type' => (string) $row['type']];
            if ($isBin) {
                $list[] = $entry + ['deleted_at' => $row['deleted_at']];
                continue;
            }

            $list[] = $entry + [
                'code' => $row['code'] ?? null,
                'value' => self::valueLabel((string) $row['value_type'], (float) $row['value']),
                'free_delivery' => !empty($row['free_delivery']),
                'starts_at' => $row['starts_at'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
                'status' => self::status($row, $now),
                'times_used' => (int) $row['times_used'],
                'usage_limit' => isset($row['usage_limit']) ? (int) $row['usage_limit'] : null,
                'usage_limit_per_customer' => isset($row['usage_limit_per_customer']) ? (int) $row['usage_limit_per_customer'] : null,
                'min_spend_inc_tax' => isset($row['min_spend_inc_tax']) ? (float) $row['min_spend_inc_tax'] : null,
                'products' => (int) $row['product_count'],
            ];
        }

        return $list;
    }

    private static function valueLabel(string $valueType, float $value): string
    {
        if ($valueType === 'percent') return \rtrim(\rtrim(\number_format($value, 2), '0'), '.') . '%';

        return '£' . \number_format($value, 2);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function status(array $row, \DateTimeImmutable $now): string
    {
        if (!$row['active']) return 'disabled';

        $starts = !empty($row['starts_at']) ? new \DateTimeImmutable((string) $row['starts_at']) : null;
        $ends = !empty($row['ends_at']) ? new \DateTimeImmutable((string) $row['ends_at']) : null;
        if ($starts !== null && $now < $starts) return 'scheduled';
        if ($ends !== null && $now > $ends) return 'expired';

        $limit = $row['usage_limit'] ?? null;
        if ($limit !== null && (int) $row['times_used'] >= (int) $limit) return 'used up';

        return 'live';
    }
}
