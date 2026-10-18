<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Changelog;

/**
 * Core's brand tools for the /mcp endpoint: list brands, create one, change its name or links, and soft delete or
 * restore one. They are handed to the endpoint like a service's tools, so write tools only appear when writes are
 * switched on. A brand's name is part of every product's title, so a rename tells the caller how many live products
 * it reaches. Delete is the bin, never permanent, and is refused while live products use the brand, as in the admin.
 * The trade-discount flag stays in the admin. A name already used by another live brand is refused, which the admin
 * form does not check.
 */
class BrandMcp
{
    private const RECORD_TYPE = 'brand';
    private const MAX_NAME_LENGTH = 50;
    private const MAX_PAGE_URL_LENGTH = 100;
    private const MAX_INFO_URL_LENGTH = 255;

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        $idAndName = [
            'id' => ['type' => 'integer', 'description' => 'The brand id from brands_list'],
            'expected_name' => ['type' => 'string', 'description' => "The brand's exact name as listed. The call is refused if it does not match the id"],
        ];
        $fields = [
            'page_url' => ['type' => 'string', 'description' => 'Optional internal page path such as brands/acme: lower-case letters, digits, hyphens and slashes, up to ' . self::MAX_PAGE_URL_LENGTH . ' characters. Blank clears it on an update'],
            'info_external' => ['type' => 'string', 'description' => 'Optional web link (http or https), up to ' . self::MAX_INFO_URL_LENGTH . ' characters. Blank clears it on an update'],
        ];

        return [
            [
                'name' => 'brands_list',
                'description' => 'Brands with id, name, page URL, external info link and how many live products use each. Ordered by name. Set deleted to true to list the brands in the bin instead.',
                'inputSchema' => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'boolean', 'description' => 'List deleted brands (the bin)']]],
                'handler' => self::class . '::brandsList',
            ],
            [
                'name' => 'brand_create',
                'description' => 'Add a brand. A brand with no products shows nowhere until a product uses it. The name is up to ' . self::MAX_NAME_LENGTH . ' characters and must not already be used by another live brand. Undo with brand_delete.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'The brand name']] + $fields,
                    'required' => ['name'],
                ],
                'write' => true,
                'handler' => self::class . '::create',
            ],
            [
                'name' => 'brand_update',
                'description' => 'Change a brand\'s name, page URL and/or external info link. The brand\'s name is part of every product title, so a rename changes how all its live products read on the site straight away; the reply says how many. Give the id and its exact current name, and at least one thing to change. Undo by changing it back.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $idAndName + ['name' => ['type' => 'string', 'description' => 'The new name, up to ' . self::MAX_NAME_LENGTH . ' characters. Cannot be blank or used by another live brand']] + $fields,
                    'required' => ['id', 'expected_name'],
                ],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::update',
            ],
            [
                'name' => 'brand_delete',
                'description' => 'Move a brand to the bin (a soft delete). Refused while any live product uses the brand. Undo with brand_restore.',
                'inputSchema' => ['type' => 'object', 'properties' => $idAndName, 'required' => ['id', 'expected_name']],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::delete',
            ],
            [
                'name' => 'brand_restore',
                'description' => 'Bring a deleted brand back from the bin. Refused if another live brand has taken its name.',
                'inputSchema' => ['type' => 'object', 'properties' => $idAndName, 'required' => ['id', 'expected_name']],
                'write' => true,
                'handler' => self::class . '::restore',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function brandsList(array $call): array
    {
        $brands = self::model($call);
        $showDeleted = ($call['arguments']['deleted'] ?? false) === true;
        $rows = $showDeleted ? $brands->listDeleted() : $brands->listWithCounts();

        return ['total' => \count($rows), 'brands' => self::shapeList($rows)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows from brand-list-with-counts.sql or brand-list-deleted.sql
     * @return array<int, array<string, mixed>>
     */
    public static function shapeList(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $isBin = isset($row['deleted_at']);
            $list[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']]
                + ($isBin ? [] : ['page_url' => $row['page_url'] ?? null, 'info_external' => $row['info_external'] ?? null])
                + [$isBin ? 'products_in_total' : 'live_products' => (int) $row['product_count']]
                + ($isBin ? ['deleted_at' => $row['deleted_at']] : []);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function create(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        $error = self::fieldsError($arguments, nameRequired: true);
        if ($error !== null) throw new McpToolException($error);

        $name = \trim((string) $arguments['name']);
        $brands = self::model($call);
        if ($brands->nameTaken($name, 0)) throw new McpToolException('Another live brand already has this name.');

        $id = $brands->insert($name, self::blankToNull($arguments['page_url'] ?? null, true), self::blankToNull($arguments['info_external'] ?? null, false));
        self::changelog($call)->log(description: "Created brand: {$name}", recordType: self::RECORD_TYPE, recordId: $id);

        return ['created' => true, 'id' => $id, 'name' => $name, 'undo' => "brand_delete with id {$id} and name {$name}"];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function update(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expected] = self::idAndName($arguments);
        $brands = self::model($call);
        $brand = self::requireBrand($brands->getById($id), isDeleted: false, expected: $expected, id: $id);

        $touchesSomething = \array_key_exists('name', $arguments) || \array_key_exists('page_url', $arguments) || \array_key_exists('info_external', $arguments);
        if (!$touchesSomething) throw new McpToolException('Give a name, a page_url, an info_external, or any of them.');

        $error = self::fieldsError($arguments, nameRequired: false);
        if ($error !== null) throw new McpToolException($error);

        $name = \array_key_exists('name', $arguments) ? \trim((string) $arguments['name']) : (string) $brand['name'];
        if ($name !== (string) $brand['name'] && $brands->nameTaken($name, $id)) {
            throw new McpToolException('Another live brand already has this name.');
        }

        $pageUrl = \array_key_exists('page_url', $arguments) ? self::blankToNull($arguments['page_url'], true) : $brand['page_url'];
        $info = \array_key_exists('info_external', $arguments) ? self::blankToNull($arguments['info_external'], false) : $brand['info_external'];

        $brands->update($id, $name, $pageUrl, $info);
        self::changelog($call)->log(description: "Updated brand: {$name}", recordType: self::RECORD_TYPE, recordId: $id);

        $renamed = $name !== (string) $brand['name'];

        return [
            'updated' => true,
            'id' => $id,
            'name' => $name,
            'page_url' => $pageUrl,
            'info_external' => $info,
            'was' => ['name' => $brand['name'], 'page_url' => $brand['page_url'], 'info_external' => $brand['info_external']],
            'live_products_affected_by_rename' => $renamed ? $brands->countActiveProducts($id) : 0,
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function delete(array $call): array
    {
        [$id, $expected] = self::idAndName($call['arguments'] ?? []);
        $brands = self::model($call);
        $brand = self::requireBrand($brands->getById($id), isDeleted: false, expected: $expected, id: $id);

        $inUse = $brands->countActiveProducts($id);
        if ($inUse > 0) throw new McpToolException("Cannot delete: {$inUse} product(s) are using this brand.");

        $brands->softDelete($id);
        self::changelog($call)->log(description: 'Deleted brand: ' . $brand['name'], recordType: self::RECORD_TYPE, recordId: $id);

        return ['deleted' => true, 'id' => $id, 'name' => (string) $brand['name'], 'undo' => "brand_restore with id {$id} and the same name"];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function restore(array $call): array
    {
        [$id, $expected] = self::idAndName($call['arguments'] ?? []);
        $brands = self::model($call);
        $brand = self::requireBrand($brands->getById($id), isDeleted: true, expected: $expected, id: $id);

        if ($brands->nameTaken((string) $brand['name'], $id)) {
            throw new McpToolException('Another live brand has this name now, so this one cannot be restored.');
        }

        $brands->restore($id);
        self::changelog($call)->log(description: 'Restored brand: ' . $brand['name'], recordType: self::RECORD_TYPE, recordId: $id);

        return ['restored' => true, 'id' => $id, 'name' => (string) $brand['name']];
    }

    /**
     * What is wrong with the brand fields given, or null when they are fine. Only fields that are present are
     * checked. A blank page_url or info_external is allowed (it clears the field).
     *
     * @param array<string, mixed> $arguments
     */
    public static function fieldsError(array $arguments, bool $nameRequired): ?string
    {
        if ($nameRequired && !\array_key_exists('name', $arguments)) return 'Give the brand a name.';

        foreach (['name', 'page_url', 'info_external'] as $field) {
            if (!\array_key_exists($field, $arguments)) continue;
            if (!\is_string($arguments[$field])) return "The {$field} must be text.";
            if (\preg_match('/[\x00-\x1f\x7f]/', $arguments[$field]) === 1) return "The {$field} must be one line of plain text.";
        }

        if (\array_key_exists('name', $arguments)) {
            $name = \trim($arguments['name']);
            if ($name === '') return 'The name cannot be blank.';
            if (\mb_strlen($name) > self::MAX_NAME_LENGTH) return 'A name can be up to ' . self::MAX_NAME_LENGTH . ' characters.';
        }

        if (\array_key_exists('page_url', $arguments)) {
            $pageUrl = \trim($arguments['page_url']);
            if (\mb_strlen($pageUrl) > self::MAX_PAGE_URL_LENGTH) return 'A page_url can be up to ' . self::MAX_PAGE_URL_LENGTH . ' characters.';
            if ($pageUrl !== '' && \preg_match('#^[a-z0-9]+(?:[a-z0-9/-]*[a-z0-9])?$#', \trim($pageUrl, '/')) !== 1) {
                return 'A page_url uses lower-case letters, digits, hyphens and slashes only, such as brands/acme.';
            }
        }

        if (\array_key_exists('info_external', $arguments)) {
            $link = \trim($arguments['info_external']);
            if (\mb_strlen($link) > self::MAX_INFO_URL_LENGTH) return 'An info_external link can be up to ' . self::MAX_INFO_URL_LENGTH . ' characters.';
            $isWebLink = \preg_match('#^https?://[^\s]+$#i', $link) === 1 && \filter_var($link, FILTER_VALIDATE_URL) !== false;
            if ($link !== '' && !$isWebLink) return 'The info_external link must be a web address starting with http:// or https://.';
        }

        return null;
    }

    /**
     * Blank becomes null (the field is cleared); a page path loses any slashes at its ends.
     */
    public static function blankToNull(mixed $value, bool $isPath): ?string
    {
        $text = \trim((string) $value);
        if ($isPath) $text = \trim($text, '/');

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    public static function idAndName(array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $name = $arguments['expected_name'] ?? null;
        $valid = \is_int($id) && $id > 0 && \is_string($name) && \trim($name) !== '';
        if (!$valid) throw new McpToolException('Give the brand id (a whole number) and its exact expected_name.');

        return [$id, \trim($name)];
    }

    /**
     * Refuses unless the brand exists, is in the state the tool needs (live to change or delete, in the bin to
     * restore) and its name is exactly the one given, so a slip of the id cannot change the wrong brand.
     *
     * @param array<string, mixed>|null $brand
     * @return array<string, mixed>
     */
    private static function requireBrand(?array $brand, bool $isDeleted, string $expected, int $id): array
    {
        $inRightState = $brand !== null && (($brand['deleted_at'] ?? null) !== null) === $isDeleted;
        if (!$inRightState) throw new McpToolException("No matching brand with id {$id}. Check brands_list.");
        if (\trim((string) $brand['name']) !== $expected) {
            throw new McpToolException("The name does not match brand {$id}. Check brands_list and try again.");
        }

        return $brand;
    }

    /** @param array<string, mixed> $call */
    private static function model(array $call): Brand
    {
        return new Brand($call['dataSource'], 'productbrands');
    }

    /** @param array<string, mixed> $call */
    private static function changelog(array $call): Changelog
    {
        $changelog = new Changelog($call['dataSource'], 'changelog');
        $changelog->setGetUser(fn(): array => ['username' => $call['actor']]);

        return $changelog;
    }
}
