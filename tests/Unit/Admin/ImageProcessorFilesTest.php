<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ImageProcessor;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ImageProcessor.php';

/**
 * ImageProcessor for real: real GD, real files, in a throwaway folder. Product photos go in and the size variants
 * (thumb, medium, large, extra; JPG, WebP and AVIF; normal and @2x) come out, checked by opening them again and
 * reading their dimensions and colours. The processor's own debug log (app/logs/image-upload.log) is put back to
 * the length it had before each test.
 */
class ImageProcessorFilesTest extends TestCase
{
    private const SIZES = ['thumb' => 136, 'medium' => 235, 'large' => 450, 'extra' => 450];

    private string $base;
    private ImageProcessor $processor;
    private string $logFile;
    private int $logSize;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/osmium_images_test_' . \uniqid();
        \mkdir($this->base);
        $this->processor = new ImageProcessor($this->base . '/');

        $this->logFile = \dirname(__DIR__, 6) . '/app/logs/image-upload.log';
        $this->logSize = \is_file($this->logFile) ? (int) \filesize($this->logFile) : -1;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);

        if ($this->logSize === -1) {
            @\unlink($this->logFile);
        } elseif (\is_file($this->logFile)) {
            $handle = \fopen($this->logFile, 'r+');
            \ftruncate($handle, $this->logSize);
            \fclose($handle);
        }
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($path);
    }

    /** A picture of three vertical bands (red, green, blue), saved in the given format. */
    private function picture(string $format, int $width = 300, int $height = 300, string $name = 'source'): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $bands = [[255, 0, 0], [0, 255, 0], [0, 0, 255]];
        foreach ($bands as $i => [$r, $g, $b]) {
            \imagefilledrectangle($image, (int) ($width * $i / 3), 0, (int) ($width * ($i + 1) / 3) - 1, $height, \imagecolorallocate($image, $r, $g, $b));
        }
        $path = "{$this->base}/{$name}.{$format}";
        match ($format) {
            'jpg' => \imagejpeg($image, $path, 100),
            'png' => \imagepng($image, $path),
            'gif' => \imagegif($image, $path),
            'webp' => \imagewebp($image, $path, 100),
        };

        return $path;
    }

    private function shopPath(string $relative): string
    {
        return "{$this->base}/assets/images/shop/{$relative}";
    }

    /** @return array{0: int, 1: int, 2: int} red, green, blue of one pixel */
    private function colourAt(string $file, int $x, int $y): array
    {
        $image = \imagecreatefromstring((string) \file_get_contents($file));
        $rgb = \imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
    }

    private function dimensions(string $file): array
    {
        $info = \getimagesize($file);

        return [$info[0], $info[1]];
    }

    private function expectedFileCount(): int
    {
        $formats = 2 + (\function_exists('imageavif') ? 1 : 0); // JPG, WebP, AVIF if this PHP can write it

        return \count(self::SIZES) * 2 * $formats;
    }

    public function test_a_photo_becomes_every_size_in_every_format_at_both_densities(): void
    {
        $result = $this->processor->processProductImage($this->picture('jpg'), 'victron', 'multiplus');

        $this->assertTrue($result['success']);
        $this->assertCount($this->expectedFileCount(), $result['files']);
        $this->assertSame('/shop/victron/multiplus/large/victron-multiplus.jpg', $result['imagePath']);

        foreach (self::SIZES as $size => $pixels) {
            $this->assertSame([$pixels, $pixels], $this->dimensions($this->shopPath("victron/multiplus/{$size}/victron-multiplus.jpg")), "{$size} jpg");
            $this->assertSame([$pixels * 2, $pixels * 2], $this->dimensions($this->shopPath("victron/multiplus/{$size}/victron-multiplus@2x.jpg")), "{$size} @2x jpg");
            $this->assertSame([$pixels, $pixels], $this->dimensions($this->shopPath("victron/multiplus/{$size}/webp/victron-multiplus.webp")), "{$size} webp");
            $this->assertFileExists($this->shopPath("victron/multiplus/{$size}/webp/victron-multiplus@2x.webp"));
        }
        if (\function_exists('imageavif')) {
            $this->assertFileExists($this->shopPath('victron/multiplus/thumb/avif/victron-multiplus.avif'));
            $this->assertFileExists($this->shopPath('victron/multiplus/thumb/avif/victron-multiplus@2x.avif'));
        }
    }

    public function test_every_listed_file_exists(): void
    {
        $result = $this->processor->processProductImage($this->picture('png'), 'victron', 'multiplus');

        foreach ($result['files'] as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_a_wide_photo_is_cropped_from_the_middle_not_squashed(): void
    {
        $result = $this->processor->processProductImage($this->picture('png', 600, 200), 'a', 'b');

        $thumb = $this->shopPath('a/b/thumb/a-b.jpg');
        $this->assertTrue($result['success']);
        $this->assertSame([0, 255, 0], $this->nearGreen($this->colourAt($thumb, 3, 3)), 'top-left corner is the middle (green) band');
        $this->assertSame([0, 255, 0], $this->nearGreen($this->colourAt($thumb, 132, 132)), 'bottom-right corner too');
    }

    public function test_a_tall_photo_is_cropped_from_the_middle(): void
    {
        $image = \imagecreatetruecolor(200, 600);
        $bands = [[255, 0, 0], [0, 255, 0], [0, 0, 255]];
        foreach ($bands as $i => [$r, $g, $b]) {
            \imagefilledrectangle($image, 0, $i * 200, 199, $i * 200 + 199, \imagecolorallocate($image, $r, $g, $b));
        }
        \imagepng($image, "{$this->base}/tall.png");

        $this->processor->processProductImage("{$this->base}/tall.png", 'a', 'b');

        $thumb = $this->shopPath('a/b/thumb/a-b.jpg');
        $this->assertSame([0, 255, 0], $this->nearGreen($this->colourAt($thumb, 3, 3)));
        $this->assertSame([0, 255, 0], $this->nearGreen($this->colourAt($thumb, 3, 132)));
    }

    /** JPEG blurs colours a little; round each channel to its nearest extreme. */
    private function nearGreen(array $rgb): array
    {
        return \array_map(fn (int $c) => $c > 127 ? 255 : 0, $rgb);
    }

    public function test_photos_in_jpeg_png_gif_and_webp_are_all_accepted(): void
    {
        foreach (['jpg', 'png', 'gif', 'webp'] as $format) {
            $result = $this->processor->processProductImage($this->picture($format, name: "in-{$format}"), 'fmt', $format);

            $this->assertTrue($result['success'], $format);
        }
    }

    public function test_jpegs_are_saved_at_quality_85_and_webp_at_80(): void
    {
        \mt_srand(7);
        $noise = \imagecreatetruecolor(450, 450);
        for ($i = 0; $i < 4000; $i++) {
            \imagefilledrectangle($noise, \mt_rand(0, 449), \mt_rand(0, 449), \mt_rand(0, 449), \mt_rand(0, 449), \imagecolorallocate($noise, \mt_rand(0, 255), \mt_rand(0, 255), \mt_rand(0, 255)));
        }
        \imagepng($noise, "{$this->base}/noise.png");
        \ob_start();
        \imagejpeg($noise, null, 85);
        $expected = \strlen((string) \ob_get_clean());
        \ob_start();
        \imagewebp($noise, null, 80);
        $expectedWebp = \strlen((string) \ob_get_clean());

        $this->processor->processProductImage("{$this->base}/noise.png", 'a', 'b');

        $this->assertSame($expected, \filesize($this->shopPath('a/b/large/a-b.jpg')), 'a 450px source is not resized, so the file matches a plain quality-85 save');
        $this->assertSame($expectedWebp, \filesize($this->shopPath('a/b/large/webp/a-b.webp')));
    }

    public function test_a_variant_that_cannot_be_deleted_is_reported(): void
    {
        $this->processor->processProductImage($this->picture('png'), 'a', 'b');
        $jpg = $this->shopPath('a/b/thumb/a-b.jpg');
        \unlink($jpg);
        \mkdir($jpg); // a folder where the file should be: unlink refuses
        \file_put_contents("{$jpg}/keep", 'x');

        \set_error_handler(fn () => true);
        try {
            $result = $this->processor->deleteImageVariants('a', 'b');
        } finally {
            \restore_error_handler();
        }

        $this->assertFalse($result['success']);
        $this->assertSame(["Failed to delete: {$jpg}"], $result['errors']);
        $this->assertCount($this->expectedFileCount() - 1, $result['deleted'], 'everything else still went');
    }

    public function test_something_that_is_not_an_image_is_refused_and_nothing_is_written(): void
    {
        $text = "{$this->base}/note.jpg";
        \file_put_contents($text, 'not a picture');

        $result = $this->processor->processProductImage($text, 'a', 'b');

        $this->assertSame(['success' => false, 'error' => 'Failed to load image'], $result);
        $this->assertDirectoryDoesNotExist($this->shopPath('a'));
    }

    public function test_an_extra_slot_has_its_own_file_names(): void
    {
        $result = $this->processor->processProductImage($this->picture('png'), 'victron', 'multiplus', 'extra2');

        $this->assertSame('/shop/victron/multiplus/large/victron-multiplus-extra2.jpg', $result['imagePath']);
        $this->assertFileExists($this->shopPath('victron/multiplus/thumb/victron-multiplus-extra2.jpg'));
        $this->assertFileDoesNotExist($this->shopPath('victron/multiplus/thumb/victron-multiplus.jpg'));
    }

    public function test_a_brand_path_that_climbs_out_of_the_shop_folder_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->processor->processProductImage($this->picture('png'), '..', 'y');
    }

    public function test_a_folder_that_cannot_be_made_is_reported_not_thrown(): void
    {
        \file_put_contents("{$this->base}/assets", 'a file where a folder should go');
        $processor = new ImageProcessor($this->base);

        \set_error_handler(fn () => true); // mkdir warns as well as failing
        try {
            $result = $processor->processProductImage($this->picture('png'), 'a', 'b');
        } finally {
            \restore_error_handler();
        }

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Failed to create directory: ', $result['error']);
    }

    public function test_the_image_exists_check_looks_for_the_large_jpg(): void
    {
        $this->assertFalse($this->processor->imageExists('a', 'b'));

        $this->processor->processProductImage($this->picture('png'), 'a', 'b');

        $this->assertTrue($this->processor->imageExists('a', 'b'));
        $this->assertFalse($this->processor->imageExists('a', 'b', 'extra1'));
        $this->assertFalse($this->processor->imageExists('a', 'other'));
    }

    public function test_the_product_image_list_has_one_entry_per_slot(): void
    {
        $this->processor->processProductImage($this->picture('png'), 'a', 'b');
        $this->processor->processProductImage($this->picture('png'), 'a', 'b', 'extra3');

        $images = $this->processor->getProductImages('a', 'b');

        $this->assertSame(['main', 'extra1', 'extra2', 'extra3', 'extra4', 'extra5'], \array_keys($images));
        $this->assertSame('/assets/images/shop/a/b/large/a-b.jpg', $images['main']);
        $this->assertSame('/assets/images/shop/a/b/large/a-b-extra3.jpg', $images['extra3']);
        $this->assertNull($images['extra1']);
    }

    public function test_deleting_removes_every_variant_of_that_slot_only(): void
    {
        $this->processor->processProductImage($this->picture('png'), 'a', 'b');
        $this->processor->processProductImage($this->picture('png'), 'a', 'b', 'extra1');

        $result = $this->processor->deleteImageVariants('a', 'b');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertCount($this->expectedFileCount(), $result['deleted']);
        $this->assertFalse($this->processor->imageExists('a', 'b'));
        $this->assertTrue($this->processor->imageExists('a', 'b', 'extra1'), 'the extra slot is untouched');
    }

    public function test_deleting_what_is_not_there_is_not_an_error(): void
    {
        $this->assertSame(['success' => true, 'deleted' => [], 'errors' => []], $this->processor->deleteImageVariants('no', 'such'));
    }

    public function test_the_large_source_is_found_by_extension_in_order(): void
    {
        $this->assertNull($this->processor->findLargeImage('a', 'b'));

        \mkdir($this->shopPath('a/b/large'), 0755, true);
        \file_put_contents($this->shopPath('a/b/large/a-b.webp'), 'x');
        $this->assertSame($this->shopPath('a/b/large/a-b.webp'), $this->processor->findLargeImage('a', 'b'));

        \file_put_contents($this->shopPath('a/b/large/a-b.png'), 'x');
        $this->assertSame($this->shopPath('a/b/large/a-b.png'), $this->processor->findLargeImage('a', 'b'));

        \file_put_contents($this->shopPath('a/b/large/a-b.jpg'), 'x');
        $this->assertSame($this->shopPath('a/b/large/a-b.jpg'), $this->processor->findLargeImage('a', 'b'));
    }

    public function test_regenerating_rebuilds_thumb_and_medium_from_the_large_and_leaves_the_large_jpg_alone(): void
    {
        $this->processor->processProductImage($this->picture('png'), 'a', 'b');
        $large = $this->shopPath('a/b/large/a-b.jpg');
        $largeBefore = \md5_file($large);
        @\unlink($this->shopPath('a/b/thumb/a-b.jpg'));
        @\unlink($this->shopPath('a/b/medium/webp/a-b@2x.webp'));
        \touch($large, \time() - 1000);

        $result = $this->processor->regenerateFromLarge('a', 'b');

        $this->assertTrue($result['success']);
        $this->assertSame($large, $result['source']);
        $this->assertFileExists($this->shopPath('a/b/thumb/a-b.jpg'));
        $this->assertFileExists($this->shopPath('a/b/medium/webp/a-b@2x.webp'));
        $this->assertSame($largeBefore, \md5_file($large));
        $this->assertLessThan(\time() - 900, \filemtime($large), 'the large JPG was not rewritten');
        $this->assertNotContains($large, $result['files']);
        $this->assertContains($this->shopPath('a/b/large/webp/a-b.webp'), $result['files'], 'but its WebP is');
    }

    public function test_regenerating_says_when_there_is_no_large_image_or_it_cannot_be_read(): void
    {
        $this->assertSame(['success' => false, 'error' => 'Large image not found'], $this->processor->regenerateFromLarge('a', 'b'));

        \mkdir($this->shopPath('a/b/large'), 0755, true);
        \file_put_contents($this->shopPath('a/b/large/a-b.jpg'), 'not a picture');

        $result = $this->processor->regenerateFromLarge('a', 'b');

        $this->assertFalse($result['success']);
        $this->assertSame('Failed to load image: ' . $this->shopPath('a/b/large/a-b.jpg'), $result['error']);
    }
}
