<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Services\ShopProductPageData;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../modules/shop/services/ShopProductPageData.php';

/**
 * The structured data and links a product page prints, built from stored
 * product content: what search engines and customers see.
 */
final class ShopProductPageDataTest extends TestCase
{
    // ── specProperties() ─────────────────────────────────────────────────────

    public function test_spec_properties_flatten_sections_into_name_value_pairs(): void
    {
        $json = \json_encode([
            ['title' => 'Electrical', 'specs' => [['label' => 'Voltage', 'value' => '23.76v DC'], ['label' => 'Power', 'value' => '300W']]],
            ['title' => 'Physical', 'specs' => [['label' => 'Weight', 'value' => '2kg']]],
        ]);

        $this->assertSame(
            [
                ['name' => 'Voltage', 'value' => '23.76v DC'],
                ['name' => 'Power', 'value' => '300W'],
                ['name' => 'Weight', 'value' => '2kg'],
            ],
            ShopProductPageData::specProperties($json),
        );
    }

    public function test_spec_properties_skip_incomplete_rows_and_trim_whitespace(): void
    {
        $json = \json_encode([['specs' => [
            ['label' => ' Voltage ', 'value' => ' 12v '],
            ['label' => 'No value', 'value' => '  '],
            ['label' => '', 'value' => 'No label'],
            ['value' => 'Missing label key'],
        ]]]);

        $this->assertSame([['name' => 'Voltage', 'value' => '12v']], ShopProductPageData::specProperties($json));
    }

    public function test_spec_properties_tolerate_missing_empty_or_broken_json(): void
    {
        $this->assertSame([], ShopProductPageData::specProperties(null));
        $this->assertSame([], ShopProductPageData::specProperties(''));
        $this->assertSame([], ShopProductPageData::specProperties('{not json'));
        $this->assertSame([], ShopProductPageData::specProperties('"a string"'));
        $this->assertSame([], ShopProductPageData::specProperties(\json_encode([['title' => 'No specs key']])));
    }

    // ── faqPairs() ───────────────────────────────────────────────────────────

    public function test_faq_pairs_match_each_question_with_the_element_after_it(): void
    {
        $html = '<h5 class="accordion">What is it?</h5><div><p>A solar   panel.</p></div>'
            . '<h5 class="accordion open">Does it fit?</h5>' . "\n" . '<p>Yes.</p>';

        $this->assertSame(
            [
                ['question' => 'What is it?', 'answer' => 'A solar panel.'],
                ['question' => 'Does it fit?', 'answer' => 'Yes.'],
            ],
            ShopProductPageData::faqPairs($html),
        );
    }

    public function test_faq_pairs_ignore_headings_without_the_accordion_class_and_questions_without_answers(): void
    {
        $html = '<h5>Not a question</h5><p>Ignored</p><h5 class="accordion">Unanswered?</h5>';

        $this->assertSame([], ShopProductPageData::faqPairs($html));
    }

    public function test_faq_pairs_are_empty_for_no_faq(): void
    {
        $this->assertSame([], ShopProductPageData::faqPairs(null));
        $this->assertSame([], ShopProductPageData::faqPairs(''));
    }

    // ── pdfPathOrNull() ──────────────────────────────────────────────────────

    public function test_a_real_document_path_is_kept(): void
    {
        $this->assertSame('/shop/a/b/datasheet/a-b-datasheet.pdf', ShopProductPageData::pdfPathOrNull('/shop/a/b/datasheet/a-b-datasheet.pdf'));
    }

    public function test_the_missing_file_placeholder_gives_no_link(): void
    {
        $this->assertNull(ShopProductPageData::pdfPathOrNull('/assets/data-error.png'));
    }

    // ── metaDescription() ────────────────────────────────────────────────────

    public function test_meta_description_is_plain_text_with_collapsed_whitespace(): void
    {
        $this->assertSame(
            'A great panel. Fits all roofs.',
            ShopProductPageData::metaDescription("  <p>A great   panel.</p>\n<p>Fits all <b>roofs</b>.</p> "),
        );
    }

    public function test_meta_description_is_cut_to_160_characters(): void
    {
        $result = ShopProductPageData::metaDescription('<p>' . \str_repeat('word ', 100) . '</p>');

        $this->assertSame(160, \mb_strlen($result));
    }

    public function test_meta_description_does_not_split_a_multibyte_character(): void
    {
        $result = ShopProductPageData::metaDescription(\str_repeat('£', 200));

        $this->assertSame(160, \mb_strlen($result));
        $this->assertTrue(\mb_check_encoding($result, 'UTF-8'));
    }

    public function test_meta_description_is_empty_for_no_description(): void
    {
        $this->assertSame('', ShopProductPageData::metaDescription(null));
        $this->assertSame('', ShopProductPageData::metaDescription(''));
    }

    // ── categorySummary() ────────────────────────────────────────────────────

    public function test_category_summary_pluralises_products_and_subcategories(): void
    {
        $this->assertSame('1 product', ShopProductPageData::categorySummary(1, 0));
        $this->assertSame('4 products', ShopProductPageData::categorySummary(4, 0));
        $this->assertSame('1 subcategory', ShopProductPageData::categorySummary(0, 1));
        $this->assertSame('3 subcategories', ShopProductPageData::categorySummary(0, 3));
    }

    public function test_category_summary_joins_both_and_is_empty_for_an_empty_category(): void
    {
        $this->assertSame('1 product and 1 subcategory', ShopProductPageData::categorySummary(1, 1));
        $this->assertSame('5 products and 2 subcategories', ShopProductPageData::categorySummary(5, 2));
        $this->assertSame('', ShopProductPageData::categorySummary(0, 0));
    }

    // ── data added by services (product.page.data) ──────────────────────────

    public function test_service_data_is_added_to_the_product(): void
    {
        $product = ['name' => 'Panel'];
        $results = [['rating' => 4.5, 'ratingCount' => 2], ['badge' => 'New']];

        $merged = ShopProductPageData::withServiceData(product: $product, results: $results);

        $this->assertSame(['name' => 'Panel', 'rating' => 4.5, 'ratingCount' => 2, 'badge' => 'New'], $merged);
    }

    public function test_service_data_never_replaces_what_core_already_set(): void
    {
        $product = ['name' => 'Panel', 'price' => 10];

        $merged = ShopProductPageData::withServiceData(product: $product, results: [['price' => 1, 'extra' => true]]);

        $this->assertSame(10, $merged['price']);
        $this->assertTrue($merged['extra']);
    }

    public function test_the_first_service_wins_a_key_two_services_both_set(): void
    {
        $merged = ShopProductPageData::withServiceData(product: [], results: [['tag' => 'first'], ['tag' => 'second']]);

        $this->assertSame('first', $merged['tag']);
    }

    public function test_bad_service_results_are_ignored(): void
    {
        $results = ['text', 5, null, [0 => 'listed, not named'], ['ok' => 1]];

        $merged = ShopProductPageData::withServiceData(product: ['name' => 'Panel'], results: $results);

        $this->assertSame(['name' => 'Panel', 'ok' => 1], $merged);
    }
}
