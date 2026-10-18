<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Services\BrandMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/Brand.php';

require_once 'app/services/osmium-shop/models/BrandMcp.php';

/**
 * The brand tools run for real against the tables: the live and bin lists, create (unique live name, checked
 * fields), update (only what is given, rename counted against live products), delete (refused while products use
 * it) and restore (refused if the name was taken meanwhile), with every refusal leaving the database as it was.
 */
class BrandMcpHandlersTest extends DatabaseTestCase
{
    private static bool $autoloadRegistered = false;

    private Brand $brands;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$autoloadRegistered) {
            (new Autoload('app/config'))->register();
            self::$autoloadRegistered = true;
        }

        $this->brands = new Brand($this->db, 'productbrands');
        $this->tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
    }

    private function call(array $arguments): array
    {
        return ['dataSource' => $this->db, 'arguments' => $arguments, 'actor' => 'Tester via MCP'];
    }

    private function row(int $id): array
    {
        return $this->db->prepare(sql: "SELECT * FROM {$this->db->tablePrefix()}productbrands WHERE id = :id", bindings: [':id' => $id])->single();
    }

    private function brand(string $suffix = 'Acme', ?string $pageUrl = null, ?string $info = null): int
    {
        return $this->brands->insert("{$this->tag} {$suffix}", $pageUrl, $info);
    }

    private function brandName(string $suffix = 'Acme'): string
    {
        return "{$this->tag} {$suffix}";
    }

    private function changes(int $id): array
    {
        return $this->db->prepare(
            sql: "SELECT description, user_name FROM {$this->db->tablePrefix()}changelog WHERE record_type = 'brand' AND record_id = :id",
            bindings: [':id' => $id],
        )->resultset();
    }

    private function brandCount(): int
    {
        return (int) $this->db->prepare(sql: "SELECT COUNT(*) AS c FROM {$this->db->tablePrefix()}productbrands WHERE name LIKE :n", bindings: [':n' => "{$this->tag}%"])->single()['c'];
    }

    private function refused(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
        } catch (McpToolException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }
        $this->fail("Expected a refusal containing: {$messagePart}");
    }

    private function product(int $brandId): int
    {
        return $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "{$this->tag} Widget " . \uniqid()]);
    }

    // ---- list ---------------------------------------------------------------------

    public function test_the_live_list_shows_each_brand_with_its_page_link_and_live_product_count(): void
    {
        $id = $this->brand('Listed', 'brands/listed', 'https://listed.example/info');
        $this->product($id);
        $this->product($id);
        $gone = $this->brand('Gone');
        $this->brands->softDelete($gone);

        $list = BrandMcp::brandsList($this->call([]));

        $byId = \array_column($list['brands'], null, 'id');
        $this->assertSame(['id' => $id, 'name' => $this->brandName('Listed'), 'page_url' => 'brands/listed', 'info_external' => 'https://listed.example/info', 'live_products' => 2], $byId[$id]);
        $this->assertArrayNotHasKey($gone, $byId);
        $this->assertSame(\count($list['brands']), $list['total']);
    }

    public function test_the_bin_lists_deleted_brands_with_all_their_products_and_when_they_went(): void
    {
        $id = $this->brand('Binned');
        $this->product($id);
        $this->brands->softDelete($id);
        $live = $this->brand('Live');

        $list = BrandMcp::brandsList($this->call(['deleted' => true]));

        $byId = \array_column($list['brands'], null, 'id');
        $this->assertSame(1, $byId[$id]['products_in_total']);
        $this->assertNotNull($byId[$id]['deleted_at']);
        $this->assertArrayNotHasKey('page_url', $byId[$id]);
        $this->assertArrayNotHasKey('live_products', $byId[$id]);
        $this->assertArrayNotHasKey($live, $byId);
    }

    public function test_only_a_true_deleted_flag_shows_the_bin(): void
    {
        $id = $this->brand('Binned');
        $this->brands->softDelete($id);

        foreach (['true', 1, 'yes'] as $notTrue) {
            $this->assertNotContains($id, \array_column(BrandMcp::brandsList($this->call(['deleted' => $notTrue]))['brands'], 'id'));
        }
    }

    // ---- create -------------------------------------------------------------------

    public function test_a_brand_is_created_with_a_clean_page_path_and_logged(): void
    {
        $result = BrandMcp::create($this->call(['name' => "  {$this->brandName('Fresh')}  ", 'page_url' => '/brands/fresh/', 'info_external' => ' https://fresh.example/about ']));

        $id = $result['id'];
        $row = $this->row($id);
        $this->assertSame($this->brandName('Fresh'), $row['name']);
        $this->assertSame('brands/fresh', $row['page_url'], 'slashes at the ends are dropped');
        $this->assertSame('https://fresh.example/about', $row['info_external']);
        $this->assertSame(['created' => true, 'id' => $id, 'name' => $this->brandName('Fresh'), 'undo' => "brand_delete with id {$id} and name {$this->brandName('Fresh')}"], $result);
        $this->assertSame([['description' => "Created brand: {$this->brandName('Fresh')}", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_the_optional_fields_can_be_left_out_or_blank(): void
    {
        $bare = BrandMcp::create($this->call(['name' => $this->brandName('Bare')]));
        $blank = BrandMcp::create($this->call(['name' => $this->brandName('Blank'), 'page_url' => '', 'info_external' => '  ']));

        foreach ([$bare['id'], $blank['id']] as $id) {
            $this->assertNull($this->row($id)['page_url']);
            $this->assertNull($this->row($id)['info_external']);
        }
    }

    public function test_a_name_a_live_brand_already_has_is_refused_whatever_its_case(): void
    {
        $this->brand('Taken');

        $this->refused(fn() => BrandMcp::create($this->call(['name' => \strtoupper($this->brandName('Taken'))])), 'already has this name');
        $this->assertSame(1, $this->brandCount());
    }

    public function test_a_binned_brands_name_can_be_used_again(): void
    {
        $this->brands->softDelete($this->brand('Reused'));

        BrandMcp::create($this->call(['name' => $this->brandName('Reused')]));

        $this->assertSame(2, $this->brandCount());
    }

    public function test_bad_fields_are_refused_with_the_reason_and_nothing_is_created(): void
    {
        $create = fn(array $arguments) => BrandMcp::create($this->call($arguments));
        $name = $this->brandName('Ok');

        $this->refused(fn() => $create([]), 'Give the brand a name.');
        $this->refused(fn() => $create(['name' => 5]), 'The name must be text.');
        $this->refused(fn() => $create(['name' => $name, 'page_url' => ['x']]), 'The page_url must be text.');
        $this->refused(fn() => $create(['name' => "Two\nlines"]), 'The name must be one line of plain text.');
        $this->refused(fn() => $create(['name' => $name, 'info_external' => "a\tb"]), 'The info_external must be one line of plain text.');
        $this->refused(fn() => $create(['name' => '   ']), 'The name cannot be blank.');
        $this->refused(fn() => $create(['name' => \str_repeat('a', 51)]), 'A name can be up to 50 characters.');
        $this->refused(fn() => $create(['name' => $name, 'page_url' => \str_repeat('a', 101)]), 'A page_url can be up to 100 characters.');
        $this->refused(fn() => $create(['name' => $name, 'page_url' => 'Has Capitals']), 'A page_url uses lower-case letters');
        $this->refused(fn() => $create(['name' => $name, 'page_url' => 'under_score']), 'A page_url uses lower-case letters');
        $this->refused(fn() => $create(['name' => $name, 'info_external' => 'ftp://x.example/a']), 'must be a web address');
        $this->refused(fn() => $create(['name' => $name, 'info_external' => 'https://x.example/a b']), 'must be a web address');
        $this->refused(fn() => $create(['name' => $name, 'info_external' => 'https://' . \str_repeat('a', 250) . '.example']), 'can be up to 255 characters');
        $this->assertSame(0, $this->brandCount());
    }

    public function test_the_field_limits_count_characters_and_accept_the_edge(): void
    {
        $result = BrandMcp::create($this->call(['name' => \str_repeat('é', 50), 'page_url' => 'a' . \str_repeat('b', 98) . 'c']));

        $this->assertSame(50, \mb_strlen($this->row($result['id'])['name']));
        $this->assertSame(100, \strlen($this->row($result['id'])['page_url']));
    }

    public function test_the_field_rules_can_be_asked_directly(): void
    {
        $this->assertNull(BrandMcp::fieldsError(['page_url' => 'brands/acme-2', 'info_external' => 'HTTP://Example.com/x'], nameRequired: false));
        $this->assertNull(BrandMcp::fieldsError([], nameRequired: false));
        $this->assertSame('Give the brand a name.', BrandMcp::fieldsError([], nameRequired: true));
        $this->assertNull(BrandMcp::fieldsError(['page_url' => '', 'info_external' => ''], nameRequired: false), 'blank clears the field');
        $this->assertStringContainsString('A page_url uses', (string) BrandMcp::fieldsError(['page_url' => '/'], nameRequired: false), 'a lone slash is not a page');
        $this->assertNull(BrandMcp::blankToNull('  ', false));
        $this->assertSame('a/b', BrandMcp::blankToNull(' /a/b/ ', true));
        $this->assertSame('/a/b/', BrandMcp::blankToNull(' /a/b/ ', false), 'only a page path loses its slashes');
    }

    // ---- update -------------------------------------------------------------------

    public function test_an_update_changes_only_what_is_given_and_reports_what_it_was(): void
    {
        $id = $this->brand('Before', 'brands/before', 'https://before.example/');

        $result = BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Before'), 'page_url' => 'brands/after']));

        $row = $this->row($id);
        $this->assertSame($this->brandName('Before'), $row['name']);
        $this->assertSame('brands/after', $row['page_url']);
        $this->assertSame('https://before.example/', $row['info_external']);
        $this->assertSame(['name' => $this->brandName('Before'), 'page_url' => 'brands/before', 'info_external' => 'https://before.example/'], $result['was']);
        $this->assertSame(0, $result['live_products_affected_by_rename']);
        $this->assertSame([['description' => "Updated brand: {$this->brandName('Before')}", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_a_blank_page_url_or_link_clears_that_field(): void
    {
        $id = $this->brand('Clear', 'brands/clear', 'https://clear.example/');

        $result = BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Clear'), 'page_url' => '', 'info_external' => '']));

        $this->assertNull($this->row($id)['page_url']);
        $this->assertNull($this->row($id)['info_external']);
        $this->assertNull($result['page_url']);
        $this->assertNull($result['info_external']);
    }

    public function test_a_rename_says_how_many_live_products_carry_the_new_name(): void
    {
        $id = $this->brand('Old');
        $this->product($id);
        $this->product($id);

        $result = BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Old'), 'name' => $this->brandName('New')]));

        $this->assertSame($this->brandName('New'), $this->row($id)['name']);
        $this->assertSame(2, $result['live_products_affected_by_rename']);
        $this->assertSame([['description' => "Updated brand: {$this->brandName('New')}", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_a_rename_keeps_the_page_link_and_info_link(): void
    {
        $id = $this->brand('Keeps', 'brands/keeps', 'https://keeps.example/');

        BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Keeps'), 'name' => $this->brandName('Kept')]));

        $this->assertSame(['brands/keeps', 'https://keeps.example/'], [$this->row($id)['page_url'], $this->row($id)['info_external']]);
    }

    public function test_the_longest_allowed_link_is_accepted_and_odd_hosts_are_not(): void
    {
        $long = 'https://x.example/' . \str_repeat('a', 255 - \strlen('https://x.example/'));
        $this->assertSame(255, \strlen($long));
        $result = BrandMcp::create($this->call(['name' => $this->brandName('Long'), 'info_external' => $long]));
        $this->assertSame($long, $this->row($result['id'])['info_external']);

        $this->refused(fn() => BrandMcp::create($this->call(['name' => $this->brandName('Odd'), 'info_external' => 'https://exa_mple.com'])), 'must be a web address');
    }

    public function test_saving_the_same_name_is_not_a_rename_and_never_clashes_with_itself(): void
    {
        $id = $this->brand('Same');
        $this->product($id);

        $result = BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Same'), 'name' => $this->brandName('Same')]));

        $this->assertSame(0, $result['live_products_affected_by_rename']);
    }

    public function test_a_rename_to_another_live_brands_name_is_refused(): void
    {
        $this->brand('Other');
        $id = $this->brand('Mine');

        $this->refused(fn() => BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Mine'), 'name' => $this->brandName('Other')])), 'already has this name');
        $this->assertSame($this->brandName('Mine'), $this->row($id)['name']);
    }

    public function test_an_update_with_nothing_to_change_or_bad_fields_is_refused(): void
    {
        $id = $this->brand('Stay', 'brands/stay');
        $update = fn(array $extra) => BrandMcp::update($this->call(['id' => $id, 'expected_name' => $this->brandName('Stay')] + $extra));

        $this->refused(fn() => $update([]), 'Give a name, a page_url, an info_external');
        $this->refused(fn() => $update(['name' => '']), 'The name cannot be blank.');
        $this->refused(fn() => $update(['page_url' => 'Bad Url']), 'A page_url uses');
        $this->refused(fn() => $update(['info_external' => 'nope']), 'must be a web address');
        $this->assertSame('brands/stay', $this->row($id)['page_url']);
        $this->assertSame([], $this->changes($id));
    }

    public function test_the_id_and_name_must_be_given_properly_and_match(): void
    {
        $id = $this->brand('Target');
        $binned = $this->brand('Binned');
        $this->brands->softDelete($binned);

        foreach ([[], ['id' => '5', 'expected_name' => 'x'], ['id' => 0, 'expected_name' => 'x'], ['id' => 5, 'expected_name' => ' '], ['id' => 5]] as $arguments) {
            $this->refused(fn() => BrandMcp::update($this->call($arguments + ['name' => 'x'])), 'Give the brand id');
            $this->refused(fn() => BrandMcp::delete($this->call($arguments)), 'Give the brand id');
            $this->refused(fn() => BrandMcp::restore($this->call($arguments)), 'Give the brand id');
        }
        $this->refused(fn() => BrandMcp::update($this->call(['id' => $id, 'expected_name' => 'Somebody Else', 'name' => 'x'])), 'does not match brand');
        $this->refused(fn() => BrandMcp::update($this->call(['id' => 999999999, 'expected_name' => 'x', 'name' => 'x'])), 'No matching brand with id 999999999');
        $this->refused(fn() => BrandMcp::update($this->call(['id' => $binned, 'expected_name' => $this->brandName('Binned'), 'name' => 'x'])), 'No matching brand');
        $this->assertSame($this->brandName('Target'), $this->row($id)['name']);
    }

    // ---- delete -------------------------------------------------------------------

    public function test_a_brand_with_no_live_products_goes_to_the_bin_and_is_logged(): void
    {
        $id = $this->brand('Doomed');

        $result = BrandMcp::delete($this->call(['id' => $id, 'expected_name' => $this->brandName('Doomed')]));

        $this->assertNotNull($this->row($id)['deleted_at']);
        $this->assertSame(['deleted' => true, 'id' => $id, 'name' => $this->brandName('Doomed'), 'undo' => "brand_restore with id {$id} and the same name"], $result);
        $this->assertSame([['description' => "Deleted brand: {$this->brandName('Doomed')}", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_a_brand_in_use_cannot_be_deleted_but_binned_products_do_not_count(): void
    {
        $id = $this->brand('Busy');
        $product = $this->product($id);

        $this->refused(fn() => BrandMcp::delete($this->call(['id' => $id, 'expected_name' => $this->brandName('Busy')])), 'Cannot delete: 1 product(s) are using this brand.');
        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertSame([], $this->changes($id));

        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $product])->execute();
        BrandMcp::delete($this->call(['id' => $id, 'expected_name' => $this->brandName('Busy')]));
        $this->assertNotNull($this->row($id)['deleted_at']);
    }

    // ---- restore ------------------------------------------------------------------

    public function test_a_binned_brand_is_restored_and_logged(): void
    {
        $id = $this->brand('Back');
        $this->brands->softDelete($id);

        $result = BrandMcp::restore($this->call(['id' => $id, 'expected_name' => $this->brandName('Back')]));

        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertSame(['restored' => true, 'id' => $id, 'name' => $this->brandName('Back')], $result);
        $this->assertSame([['description' => "Restored brand: {$this->brandName('Back')}", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_a_brand_cannot_be_restored_when_its_name_was_taken_meanwhile(): void
    {
        $id = $this->brand('Contested');
        $this->brands->softDelete($id);
        $this->brand('Contested');

        $this->refused(fn() => BrandMcp::restore($this->call(['id' => $id, 'expected_name' => $this->brandName('Contested')])), 'cannot be restored');
        $this->assertNotNull($this->row($id)['deleted_at']);
        $this->assertSame([], $this->changes($id));
    }

    public function test_restore_refuses_a_live_brand_a_wrong_name_or_a_missing_brand(): void
    {
        $live = $this->brand('Live');
        $binned = $this->brand('Binned');
        $this->brands->softDelete($binned);

        $this->refused(fn() => BrandMcp::restore($this->call(['id' => $live, 'expected_name' => $this->brandName('Live')])), 'No matching brand');
        $this->refused(fn() => BrandMcp::restore($this->call(['id' => $binned, 'expected_name' => 'Wrong'])), 'does not match brand');
        $this->refused(fn() => BrandMcp::restore($this->call(['id' => 999999999, 'expected_name' => 'x'])), 'No matching brand with id 999999999');
        $this->assertNotNull($this->row($binned)['deleted_at']);
    }
}
