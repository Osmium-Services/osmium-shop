<?php

declare(strict_types=1);

namespace Tests\Integration\Shop;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Request;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Models\Model;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Models\Page;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/library/Autoload.php';

/**
 * The AI-facing product record (shop/controllers/ai-product-spec.php), as JSON and as markdown: what is taken from
 * the product row, how descriptions and FAQ answers are cleaned of HTML, the price with VAT, the specification
 * tables, and the not-found answer. Runs the real script over real products (rolled back); the response methods
 * are stood in so the answer is recorded.
 */
class AiProductSpecControllerTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private string $tag;
    private int $brandId;
    private array $savedServer;
    private mixed $savedPagesById;

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;
        (new Autoload('app/config'))->register();

        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->savedServer = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'shop.test';
        $_SERVER['REQUEST_URI'] = '/api/ai/products/x.json';
        $_SERVER['SERVER_PORT'] = '80';
        unset($_SERVER['HTTPS']);

        $this->savedPagesById = (new \ReflectionProperty(Model::class, 'pagesById'))->getValue();
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->tag}"]);
        $this->brandId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->savedPagesById);
        parent::tearDown();
    }

    /** @return array{id: int, slug: string} */
    private function product(array $columns = []): array
    {
        $id = $this->createTestProduct([
            'brand_id' => $this->brandId,
            'status_id' => $this->statusId(true),
            'model_name' => "Model {$this->tag}",
            'reference' => "REF-{$this->tag}",
            'price_exc_tax' => 100,
        ]);
        $prefix = $this->db->tablePrefix();
        $columns += ['barcode' => '', 'descr' => '', 'techspec_json' => null, 'faq' => null];
        $this->db->prepare(
            sql: "UPDATE {$prefix}products SET barcode = :barcode, descr = :descr, techspec_json = :techspec_json, faq = :faq WHERE id = :id",
            bindings: [':id' => $id] + \array_combine(\array_map(fn(string $key) => ":{$key}", \array_keys($columns)), \array_values($columns)),
        )->execute();

        $pages = new Page($this->db, 'pages');
        $pageId = $pages->getNextId();
        $slug = "zq-{$this->tag}-{$id}";
        $pages->insert($pageId, null, $slug, 'Title', null, 'index,follow', \date('Y-m-d H:i:s'));
        $this->db->insert(tableName: $prefix . 'products_pages', data: ['product_id' => $id, 'page_id' => $pageId, 'is_qr' => 0]);

        return ['id' => $id, 'slug' => $slug];
    }

    /** @return array{output: string, error: ?array, finished: bool} */
    private function request(string $slug, string $format): array
    {
        $osmium = new class ($this->db) extends Osmium {
            public ?array $error = null;

            public function __construct(\Osmium\Core\Library\OsmiumPDO $db)
            {
                $this->dataSource = $db;
                $this->request = new Request();
                $this->config = new OsmiumConfig((object) [
                    'site' => (object) ['name' => 'Zq Shop', 'scheme' => 'https', 'subdomain' => '', 'domain' => 'shop.test'],
                ]);
            }

            public function jsonError(string $message, int $code = 200, array $extra = []): void
            {
                $this->error = ['message' => $message, 'code' => $code];
                throw new ResponseSentException();
            }
        };
        $context = new class ($osmium, ['slug' => $slug, 'format' => $format]) {
            public function __construct(public Osmium $osmium, public array $routeParams) {}
        };
        $script = function (): void {
            require 'app/services/osmium-shop/modules/shop/controllers/ai-product-spec.php';
        };

        $finished = false;
        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        \ob_start();
        try {
            \Closure::bind($script, $context, $context)();
        } catch (ResponseSentException) {
            $finished = true;
        } finally {
            \restore_error_handler();
            $output = (string) \ob_get_clean();
        }

        return ['output' => $output, 'error' => $osmium->error, 'finished' => $finished];
    }

    private function json(array $columns = [], ?string $slug = null): array
    {
        $product = $this->product($columns);
        $result = $this->request($slug ?? $product['slug'], 'json');
        $this->assertTrue($result['finished']);

        return \json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function markdown(array $columns = []): string
    {
        $product = $this->product($columns);

        return $this->request($product['slug'], 'md')['output'];
    }

    // ---- not found ----------------------------------------------------------------

    public function test_an_unknown_page_is_a_404_naming_the_slug(): void
    {
        $result = $this->request("zq-{$this->tag}-nothing", 'json');

        $this->assertSame(['message' => "No product at 'zq-{$this->tag}-nothing'", 'code' => 404], $result['error']);
        $this->assertSame('', $result['output']);
    }

    public function test_a_binned_product_is_a_404(): void
    {
        $product = $this->product();
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $product['id']])->execute();

        $this->assertSame(404, $this->request($product['slug'], 'json')['error']['code']);
    }

    // ---- the JSON record ----------------------------------------------------------

    public function test_the_json_record_has_the_product_facts(): void
    {
        $product = $this->product(['barcode' => '5012345678900']);
        $record = \json_decode($this->request($product['slug'], 'json')['output'], true);

        $this->assertSame("REF-{$this->tag}", $record['sku']);
        $this->assertSame('5012345678900', $record['gtin13']);
        $this->assertSame("Model {$this->tag}", $record['name']);
        $this->assertSame("Brand {$this->tag}", $record['brand']);
        $this->assertSame("http://shop.test/{$product['slug']}/", $record['url']);
        $this->assertEquals(100, $record['price_gbp_exc_vat']);
        $this->assertEquals(120, $record['price_gbp_inc_vat']);
        $this->assertNotSame('', $record['availability']);
        $this->assertSame([], $record['specifications']);
        $this->assertSame([], $record['faq']);
        $this->assertNull($record['guidance']);
        $this->assertSame(['sku', 'gtin13', 'name', 'brand', 'url', 'price_gbp_inc_vat', 'price_gbp_exc_vat', 'availability', 'description', 'specifications', 'faq', 'guidance'], \array_keys($record));
    }

    public function test_a_blank_barcode_is_null_and_the_price_with_vat_is_rounded_to_pence(): void
    {
        $record = $this->json();
        $this->assertNull($record['gtin13']);

        $product = $this->product();
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET price_exc_tax = 9.99 WHERE id = :id", bindings: [':id' => $product['id']])->execute();
        $record = \json_decode($this->request($product['slug'], 'json')['output'], true);
        $this->assertSame(9.99, $record['price_gbp_exc_vat']);
        $this->assertSame(11.99, $record['price_gbp_inc_vat']);
    }

    public function test_the_url_uses_https_when_the_visit_is_secure(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $product = $this->product();
        $this->assertSame("https://shop.test/{$product['slug']}/", \json_decode($this->request($product['slug'], 'json')['output'], true)['url']);

        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertSame("https://shop.test/{$product['slug']}/", \json_decode($this->request($product['slug'], 'json')['output'], true)['url']);
    }

    public function test_the_description_is_turned_into_plain_paragraphs(): void
    {
        $record = $this->json(['descr' => "<p>First  paragraph\r\nwrapped here.</p><p>Second &amp; last</p>line<br>after break<div>A <b>bold</b> &pound;5 note</div>"]);

        $this->assertSame("First paragraph wrapped here.\n\nSecond & last\n\nline\n\nafter breakA bold £5 note", $record['description']);
    }

    public function test_windows_line_endings_apostrophe_entities_and_whitespace_are_tidied(): void
    {
        $record = $this->json(['descr' => "One\r\n\r\nTwo &apos;quoted&apos; &quot;too&quot;\n   wrapped   text"]);

        $this->assertSame("One\n\nTwo 'quoted' \"too\" wrapped text", $record['description']);
    }

    public function test_the_raw_json_is_indented_with_slashes_and_symbols_left_readable(): void
    {
        $product = $this->product(['descr' => '<p>Costs £5</p>']);

        $raw = $this->request($product['slug'], 'json')['output'];

        $this->assertStringContainsString("{\n    \"sku\"", $raw);
        $this->assertStringContainsString("http://shop.test/{$product['slug']}/", $raw);
        $this->assertStringContainsString('Costs £5', $raw);
    }

    public function test_question_and_answer_whitespace_is_collapsed(): void
    {
        $faq = "<h5 class=\"accordion\">  Does   it\n fit?  </h5><div class=\"panel\">Line one\n   line   two</div>";

        $this->assertSame([['question' => 'Does it fit?', 'answer' => 'Line one line two']], $this->json(['faq' => $faq])['faq']);
    }

    public function test_an_empty_description_is_an_empty_string(): void
    {
        $this->assertSame('', $this->json(['descr' => ''])['description']);
    }

    public function test_the_specifications_come_from_the_stored_json(): void
    {
        $sections = [['section' => 'Electrical', 'specs' => [['label' => 'Voltage', 'value' => '12V']], 'notes' => ['At 25C']]];

        $this->assertSame($sections, $this->json(['techspec_json' => \json_encode($sections)])['specifications']);
    }

    public function test_broken_or_non_list_specification_json_gives_none(): void
    {
        $this->assertSame([], $this->json(['techspec_json' => '{not json'])['specifications']);
        $this->assertSame([], $this->json(['techspec_json' => '"just a string"'])['specifications']);
        $this->assertSame([], $this->json(['techspec_json' => ''])['specifications']);
    }

    public function test_the_faq_is_read_as_question_and_answer_pairs(): void
    {
        $faq = '<h5 class="accordion">Does it <i>fit</i>?</h5><div class="panel"><p>Yes,</p><p>every time &amp; always.</p></div>'
            . '<h5 class="accordion other">Second?</h5>text node<div class="panel">Maybe.</div>'
            . '<h5 class="plain">Ignored</h5><div>Ignored answer</div>'
            . '<h5 class="accordion">Last question with no answer</h5>';

        $pairs = $this->json(['faq' => $faq])['faq'];

        $this->assertSame([
            ['question' => 'Does it fit?', 'answer' => 'Yes,every time & always.'],
            ['question' => 'Second?', 'answer' => 'Maybe.'],
        ], $pairs);
    }

    public function test_an_empty_faq_gives_no_pairs(): void
    {
        $this->assertSame([], $this->json(['faq' => ''])['faq']);
        $this->assertSame([], $this->json(['faq' => '<p>No questions here</p>'])['faq']);
    }

    // ---- the markdown -------------------------------------------------------------

    public function test_the_markdown_lists_the_facts_and_ends_with_the_guidance_note(): void
    {
        $product = $this->product(['barcode' => '5012345678900', 'descr' => '<p>A fine thing.</p>']);

        $md = $this->request($product['slug'], 'md')['output'];

        $this->assertStringStartsWith("# Brand {$this->tag} Model {$this->tag}\n\n- **SKU:** REF-{$this->tag}\n- **GTIN-13:** 5012345678900\n- **Price:** £120.00 inc VAT (£100.00 ex VAT)\n- **Availability:** ", $md);
        $this->assertStringContainsString("- **URL:** http://shop.test/{$product['slug']}/\n\n## Description\n\nA fine thing.\n\n", $md);
        $this->assertStringEndsWith("---\n\n## Zq Shop guidance\n\n_Not yet available for this product._\n", $md);
        $this->assertStringNotContainsString('## Technical specifications', $md);
        $this->assertStringNotContainsString('## FAQ', $md);
    }

    public function test_markdown_is_the_answer_for_any_format_but_json(): void
    {
        $product = $this->product();

        $this->assertStringStartsWith('# Brand', $this->request($product['slug'], 'md')['output']);
        $this->assertStringStartsWith('# Brand', $this->request($product['slug'], '')['output']);
        $this->assertStringStartsWith('{', $this->request($product['slug'], 'json')['output']);
    }

    public function test_the_markdown_leaves_out_a_blank_barcode_and_description(): void
    {
        $md = $this->markdown();

        $this->assertStringNotContainsString('GTIN-13', $md);
        $this->assertStringNotContainsString('## Description', $md);
    }

    public function test_the_markdown_shows_specification_tables_notes_and_the_faq(): void
    {
        $sections = [
            ['section' => 'Electrical', 'specs' => [['label' => 'Voltage', 'value' => '12V'], ['label' => 'Current', 'value' => '10A']], 'notes' => ['At 25C', 'Typical']],
            ['specs' => [['label' => 'Weight', 'value' => '2kg']]],
        ];
        $faq = '<h5 class="accordion">Does it fit?</h5><div class="panel">Yes.</div>';

        $md = $this->markdown(['techspec_json' => \json_encode($sections), 'faq' => $faq]);

        $this->assertStringContainsString("## Technical specifications\n\n### Electrical\n\n| Specification | Value |\n|---|---|\n| Voltage | 12V |\n| Current | 10A |\n\n_At 25C_\n\n_Typical_\n\n", $md);
        $this->assertStringContainsString("### Specifications\n\n| Specification | Value |\n|---|---|\n| Weight | 2kg |\n\n", $md, 'a section with no name is headed Specifications');
        $this->assertStringContainsString("## FAQ\n\n**Does it fit?**\n\nYes.\n\n", $md);
        $this->assertLessThan(\strpos($md, '## FAQ'), \strpos($md, '## Technical specifications'));
        $this->assertLessThan(\strpos($md, "\n---\n\n## Zq Shop guidance"), \strpos($md, '## FAQ'));
    }
}
