<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Services\ShopProductFaq;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../modules/shop/services/ShopProductFaq.php';

/**
 * A shared FAQs list reaches the product page in the markup and pairs the product's own FAQ text produces.
 */
final class ShopProductFaqTest extends TestCase
{
    private const ITEMS = [
        ['question' => 'Does it fit <any> van?', 'answer' => 'Yes, mostly.', 'answer_html' => '<p>Yes, <strong>mostly</strong>.</p>'],
        ['question' => 'Warranty?', 'answer' => 'Five years.', 'answer_html' => '<p>Five years.</p>'],
    ];

    public function test_html_uses_the_accordion_markup_with_the_question_escaped(): void
    {
        $this->assertSame(
            "<h5 class=\"accordion\">Does it fit &lt;any&gt; van?</h5><div class=\"panel\"><p>Yes, <strong>mostly</strong>.</p></div>\n"
            . "<h5 class=\"accordion\">Warranty?</h5><div class=\"panel\"><p>Five years.</p></div>\n",
            ShopProductFaq::html(self::ITEMS),
        );
    }

    public function test_the_product_page_parser_reads_the_markup_back_into_the_same_pairs(): void
    {
        require_once __DIR__ . '/../../../../modules/shop/services/ShopProductPageData.php';

        $this->assertSame(
            [
                ['question' => 'Does it fit <any> van?', 'answer' => 'Yes, mostly.'],
                ['question' => 'Warranty?', 'answer' => 'Five years.'],
            ],
            \Osmium\Modules\Shop\Services\ShopProductPageData::faqPairs(ShopProductFaq::html(self::ITEMS)),
        );
    }

    public function test_pairs_are_plain_text_question_and_answer(): void
    {
        $this->assertSame(
            [
                ['question' => 'Does it fit <any> van?', 'answer' => 'Yes, mostly.'],
                ['question' => 'Warranty?', 'answer' => 'Five years.'],
            ],
            ShopProductFaq::pairs(self::ITEMS),
        );
    }
}
