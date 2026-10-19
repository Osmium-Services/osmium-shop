<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\AdminShop;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests pure private methods in AdminShop service.
 *
 * Pattern: Constructor bypass + Reflection + $_SERVER save/restore
 * - Constructor reads $_SERVER['DOCUMENT_ROOT']
 * - Test pure transformation methods in isolation
 */
class AdminShopPureTest extends TestCase
{
    private ReflectionClass $reflection;
    private ?string $originalDocRoot;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(AdminShop::class);
        $this->originalDocRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = '/var/www/html'; // Set known value for tests
    }

    protected function tearDown(): void
    {
        if ($this->originalDocRoot !== null) {
            $_SERVER['DOCUMENT_ROOT'] = $this->originalDocRoot;
        } else {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
    }

    private function callMethod(string $methodName, array $args): mixed
    {
        $instance = $this->reflection->newInstanceWithoutConstructor();
        $method = $this->reflection->getMethod($methodName);
        return $method->invoke($instance, ...$args);
    }

    // normalizePath() tests

    public function test_normalize_path_accepts_a_generated_slug(): void
    {
        $this->assertEquals('brand-model', $this->callMethod('normalizePath', ['brand-model']));
    }

    public function test_normalize_path_decodes_html_entities_before_checking(): void
    {
        $this->assertEquals('brand', $this->callMethod('normalizePath', ['brand']));
        $this->expectException(\InvalidArgumentException::class);
        $this->callMethod('normalizePath', ['brand&#x2F;model']);
    }

    public function test_normalize_path_refuses_an_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callMethod('normalizePath', ['']);
    }

    public function test_normalize_path_refuses_slashes_and_parent_segments(): void
    {
        foreach (['a/b/c', 'brand/model', '..', 'a/../b'] as $path) {
            try {
                $this->callMethod('normalizePath', [$path]);
                $this->fail("{$path} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // duplicateGenerateUrlStub() tests

    public function test_duplicate_generate_url_stub_generates_hyphenated_lowercase_url(): void
    {
        $result = $this->callMethod('duplicateGenerateUrlStub', ['Victron', 'MultiPlus 2']);

        $this->assertEquals('victron-multiplus-2', $result);
    }

    public function test_duplicate_generate_url_stub_removes_parentheses(): void
    {
        $result = $this->callMethod('duplicateGenerateUrlStub', ['Brand', 'Model (Copy)']);

        $this->assertEquals('brand-model-copy', $result);
    }

    public function test_duplicate_generate_url_stub_collapses_consecutive_hyphens(): void
    {
        $result = $this->callMethod('duplicateGenerateUrlStub', ['Brand  Name', 'Model  Name']);

        $this->assertEquals('brand-name-model-name', $result);
    }

    public function test_duplicate_generate_url_stub_trims_edge_hyphens(): void
    {
        $result = $this->callMethod('duplicateGenerateUrlStub', [' Brand ', ' Model ']);

        $this->assertEquals('brand-model', $result);
    }
}
