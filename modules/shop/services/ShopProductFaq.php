<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * A product's FAQ can come from a shared list in the FAQs service instead of its own FAQ text. The shop never calls
 * that service: it asks through the faq.lists and faq.items hooks, and gets nothing back when the service is not
 * installed. A chosen list that is empty or gone leaves the product on its own FAQ text.
 */
final class ShopProductFaq
{
    /**
     * The lists a product can pick, as [slug => name]. Empty when the FAQs service is not installed.
     *
     * @return array<string, string>
     */
    public static function choices(OsmiumPDO $dataSource): array
    {
        $choices = [];
        foreach (self::hooks($dataSource)->collect(event: 'faq.lists', payload: ['dataSource' => $dataSource]) as $result) {
            foreach ($result['lists'] ?? [] as $list) {
                $choices[(string) $list['slug']] = (string) $list['name'];
            }
        }

        return $choices;
    }

    /**
     * The published questions of the chosen list, or null when no list is chosen or it has none.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function items(?string $slug, OsmiumPDO $dataSource): ?array
    {
        if ($slug === null || $slug === '') return null;

        $results = self::hooks($dataSource)->collect(event: 'faq.items', payload: ['slug' => $slug, 'dataSource' => $dataSource]);
        $items = $results[0]['items'] ?? [];

        return $items === [] ? null : $items;
    }

    /**
     * The list's questions in the markup a product's own FAQ text uses (h5.accordion question, then a div.panel
     * answer), so a theme prints either source the same way.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function html(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<h5 class="accordion">' . \htmlspecialchars((string) $item['question']) . '</h5>'
                . '<div class="panel">' . (string) $item['answer_html'] . '</div>' . "\n";
        }

        return $html;
    }

    /**
     * Question/answer pairs as plain text, for FAQPage schema.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{question: string, answer: string}>
     */
    public static function pairs(array $items): array
    {
        $pairs = [];
        foreach ($items as $item) {
            $pairs[] = ['question' => (string) $item['question'], 'answer' => (string) $item['answer']];
        }

        return $pairs;
    }

    private static function hooks(OsmiumPDO $dataSource): ServiceHooks
    {
        return new ServiceHooks(new ServiceLoader(new ServiceModel($dataSource)));
    }
}
