<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\AdminShopImages;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests pure private methods in AdminShopImages service.
 *
 * Pattern: Constructor bypass + Reflection
 * - AdminShopImages has complex dependencies
 * - Test pure transformation methods in isolation
 */
class AdminShopImagesPureTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(AdminShopImages::class);
    }

    private function callMethod(string $methodName, array $args): mixed
    {
        $instance = $this->reflection->newInstanceWithoutConstructor();
        $method = $this->reflection->getMethod($methodName);
        return $method->invoke($instance, ...$args);
    }

    // validateUploadError() tests

    public function test_validate_upload_error_passes_when_error_is_upload_err_ok(): void
    {
        $file = ['error' => UPLOAD_ERR_OK];

        $this->callMethod('validateUploadError', [$file]);

        $this->assertTrue(true); // No exception means pass
    }

    public function test_validate_upload_error_throws_for_upload_err_ini_size(): void
    {
        $file = ['error' => UPLOAD_ERR_INI_SIZE];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File exceeds server upload limit');
        $this->callMethod('validateUploadError', [$file]);
    }

    public function test_validate_upload_error_throws_for_upload_err_no_file(): void
    {
        $file = ['error' => UPLOAD_ERR_NO_FILE];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No file was uploaded');
        $this->callMethod('validateUploadError', [$file]);
    }

    public function test_validate_upload_error_throws_for_upload_err_partial(): void
    {
        $file = ['error' => UPLOAD_ERR_PARTIAL];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File was only partially uploaded');
        $this->callMethod('validateUploadError', [$file]);
    }

    public function test_validate_upload_error_throws_when_error_key_missing(): void
    {
        $file = [];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No file was uploaded');
        $this->callMethod('validateUploadError', [$file]);
    }

    // validateFileSize() tests

    public function test_validate_file_size_passes_when_size_under_limit(): void
    {
        $file = ['size' => OsmiumAdmin::MAX_UPLOAD_SIZE - 1];

        $this->callMethod('validateFileSize', [$file]);

        $this->assertTrue(true); // No exception means pass
    }

    public function test_validate_file_size_throws_when_size_exceeds_max_upload_size(): void
    {
        $file = ['size' => OsmiumAdmin::MAX_UPLOAD_SIZE + 1];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File too large. Maximum 10MB allowed');
        $this->callMethod('validateFileSize', [$file]);
    }

    // generateBrandPath() tests

    public function test_generate_brand_path_lowercases_and_hyphenates(): void
    {
        $result = $this->callMethod('generateBrandPath', ['Solar Brand']);

        $this->assertEquals('solar-brand', $result);
    }

    public function test_generate_brand_path_strips_slashes_and_ampersands(): void
    {
        $result = $this->callMethod('generateBrandPath', ['Brand/& Co']);

        $this->assertEquals('brand-co', $result);
    }

    public function test_generate_brand_path_collapses_consecutive_hyphens(): void
    {
        $result = $this->callMethod('generateBrandPath', ['Brand   Name']);

        $this->assertEquals('brand-name', $result);
    }

    // generateModelPath() tests

    public function test_generate_model_path_lowercases_and_hyphenates(): void
    {
        $result = $this->callMethod('generateModelPath', ['MultiPlus 2']);

        $this->assertEquals('multiplus-2', $result);
    }

    public function test_generate_model_path_strips_special_chars(): void
    {
        $result = $this->callMethod('generateModelPath', ['Model/X & Y']);

        $this->assertEquals('modelx-y', $result);
    }

    // getImageTypeFromSlot() tests

    public function test_get_image_type_from_slot_returns_empty_string_for_main_slot(): void
    {
        $result = $this->callMethod('getImageTypeFromSlot', ['main']);

        $this->assertEquals('', $result);
    }

    public function test_get_image_type_from_slot_returns_extra3_for_extra3_slot(): void
    {
        $result = $this->callMethod('getImageTypeFromSlot', ['extra3']);

        $this->assertEquals('extra3', $result);
    }

    public function test_get_image_type_from_slot_returns_descr_for_descr_image_slot(): void
    {
        $result = $this->callMethod('getImageTypeFromSlot', ['descr_image']);

        $this->assertEquals('descr', $result);
    }
}
