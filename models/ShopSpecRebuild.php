<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\TechspecParser;
use Osmium\Modules\Admin\Models\Product;

/**
 * The "Rebuild Spec JSON" button on the admin Migrations page, offered through the migrations.actions hook.
 *
 * techspec_json is derived, so rebuilding is safe at any time: the output depends only on techspec. It exists because
 * there is no SSH on staging or live, and because a parser improvement has to reach products whose JSON is already
 * populated, which a fill-the-NULLs approach would never do.
 */
class ShopSpecRebuild
{
    /**
     * @return array<string, string> the button: action, label, icon and the handler that runs it
     */
    public static function action(array $payload): array
    {
        return [
            'action' => 'rebuild-specs',
            'label' => 'Rebuild Spec JSON',
            'icon' => 'bx-refresh',
            'handler' => self::class . '::rebuild',
        ];
    }

    /**
     * @param array{osmium: object, admin: object} $payload
     * @return array<string, mixed> counts for the screen; 'message' is shown as the result
     */
    public static function rebuild(array $payload): array
    {
        $product = new Product($payload['osmium']->dataSource, 'products');
        $products = $product->listWithTechspec();

        $parser = new TechspecParser();
        $counts = ['success' => true, 'products' => \count($products), 'parsed' => 0, 'empty' => 0, 'specs' => 0];

        foreach ($products as $row) {
            $sections = $parser->parse($row['techspec']);
            $counts = self::tally(counts: $counts, sections: $sections);
            $product->updateTechspecJson(id: (int)$row['id'], json: self::encode($sections));
        }

        $payload['admin']->model->changelog->log(
            description: "Rebuilt product spec JSON: {$counts['parsed']} of {$counts['products']} products, {$counts['specs']} spec rows",
            recordType: 'product',
        );

        $skipped = $counts['empty'] > 0 ? " {$counts['empty']} product(s) had no parseable spec table." : '';
        $counts['message'] = "Spec JSON rebuilt. {$counts['parsed']} of {$counts['products']} product(s), {$counts['specs']} spec rows.{$skipped}";

        return $counts;
    }

    /**
     * A product whose techspec holds no parseable table - placeholder copy, or an empty editor - is written back as
     * NULL rather than an empty array, so the front end sees "nothing here" and falls back to rendering the HTML.
     */
    private static function encode(array $sections): ?string
    {
        $hasSections = $sections !== [];
        if (!$hasSections) return null;

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return \json_encode($sections, $flags) ?: null;
    }

    private static function tally(array $counts, array $sections): array
    {
        $specCount = 0;
        foreach ($sections as $section) {
            $specCount += \count($section['specs']);
        }

        $counts['specs'] += $specCount;
        $parsed = $specCount > 0;

        if ($parsed) $counts['parsed']++;
        if (!$parsed) $counts['empty']++;

        return $counts;
    }
}
