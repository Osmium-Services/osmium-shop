<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * The image upload log, app/logs/image-upload.log. Used by the upload handling and the image processor so both write
 * to the one file in the one format.
 */
class ImageUploadLog
{
    public static function write(string $message, string $source = ''): void
    {
        $logDir = \dirname(path: __DIR__, levels: 3) . '/logs';
        $logDirMissing = !\is_dir($logDir);
        if ($logDirMissing) @\mkdir(directory: $logDir, permissions: 0755, recursive: true);

        $label = $source === '' ? '' : "[{$source}] ";
        $entry = '[' . \date('Y-m-d H:i:s') . "] {$label}{$message}\n";

        @\file_put_contents(filename: $logDir . '/image-upload.log', data: $entry, flags: FILE_APPEND);
    }
}
