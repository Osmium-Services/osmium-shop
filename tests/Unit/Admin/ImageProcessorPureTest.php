<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ImageProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ImageProcessor private pure methods
 * Pattern: Constructor bypass + Reflection
 */
final class ImageProcessorPureTest extends TestCase
{
    // ========================================
    // buildBaseName() Tests
    // ========================================

    public function test_buildBaseName_generates_base_name_from_brand_and_model(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildBaseName');

        $baseName = $method->invoke($instance, 'victron', 'multiplus', '');

        $this->assertSame('victron-multiplus', $baseName);
    }

    public function test_buildBaseName_handles_empty_brand_gracefully(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildBaseName');

        $baseName = $method->invoke($instance, '', 'multiplus', '');

        $this->assertSame('-multiplus', $baseName);
    }

    public function test_buildBaseName_strips_special_characters(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildBaseName');

        $baseName = $method->invoke($instance, 'Brand & Co', 'Model/X', '');

        // Should sanitize: '&' and '/' replaced with dashes
        $this->assertStringNotContainsString('&', $baseName);
        $this->assertStringNotContainsString('/', $baseName);
    }

    // ========================================
    // sanitizeForFilename() Tests
    // ========================================

    public function test_sanitizeForFilename_removes_problematic_chars_replaces_slashes(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('sanitizeForFilename');

        $sanitized = $method->invoke($instance, 'My/File:Test');

        // Slashes and colons are replaced with dashes
        $this->assertStringNotContainsString('/', $sanitized);
        $this->assertStringNotContainsString(':', $sanitized);
        $this->assertStringContainsString('-', $sanitized);
    }

    public function test_sanitizeForFilename_collapses_consecutive_hyphens(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('sanitizeForFilename');

        $sanitized = $method->invoke($instance, 'file---name');

        $this->assertSame('file-name', $sanitized);
    }

    public function test_sanitizeForFilename_decodes_html_entities(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('sanitizeForFilename');

        $sanitized = $method->invoke($instance, 'Brand &amp; Co');

        // &amp; becomes & which then gets replaced with -
        $this->assertStringNotContainsString('&amp;', $sanitized);
        $this->assertStringNotContainsString('&', $sanitized);
    }

    // ========================================
    // normalizePath() Tests
    // ========================================

    public function test_normalizePath_accepts_the_slugs_the_shop_generates(): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('normalizePath');

        $this->assertSame('acme-co', $method->invoke($instance, 'acme-co'));
        $this->assertSame('x2', $method->invoke($instance, 'x2'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePaths')]
    public function test_normalizePath_refuses_anything_that_could_leave_the_product_folder(string $path): void
    {
        $ref = new ReflectionClass(ImageProcessor::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('normalizePath');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($instance, $path);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'parent folder' => ['..'],
            'parent in the middle' => ['a/../b'],
            'encoded parent' => ['&period;&period;'],
            'slash' => ['path/to/dir'],
            'backslash' => ['a\\b'],
            'encoded slash' => ['acme&#x2F;co'],
            'upper case' => ['Brand'],
            'space' => ['Brand Co'],
            'leading hyphen' => ['-a'],
            'double hyphen' => ['a--b'],
            'null byte' => ["a\0b"],
        ];
    }
}
