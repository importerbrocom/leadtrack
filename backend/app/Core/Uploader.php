<?php

namespace App\Core;

/**
 * Validated file uploads into storage/ (which lives outside public_html).
 */
final class Uploader
{
    /**
     * @param array  $file      entry from $_FILES
     * @param string $subDir    'documents' or 'templates'
     * @return array{file_name:string,stored_name:string,mime_type:string,file_size:int,absolute_path:string}
     */
    public static function store(array $file, string $subDir): array
    {
        self::assertNoUploadError($file);

        $maxBytes = ((int) Config::get('storage.max_upload_mb', 15)) * 1024 * 1024;
        $size     = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw ApiException::badRequest('The uploaded file is empty');
        }

        if ($size > $maxBytes) {
            throw ApiException::badRequest(sprintf(
                'File is too large (%.1f MB). Maximum allowed is %d MB.',
                $size / 1048576,
                Config::get('storage.max_upload_mb', 15)
            ));
        }

        // Trust the file's actual content, never the client-supplied type.
        $mime = self::detectMime($file['tmp_name']);
        $allowed = Config::get('storage.allowed_mime', []);

        if ($allowed !== [] && !in_array($mime, $allowed, true)) {
            throw ApiException::badRequest("Files of type '{$mime}' are not allowed. Upload a PDF, image or Office document.");
        }

        $originalName = Helpers::sanitizeFileName((string) ($file['name'] ?? 'upload'));
        $storedName   = Helpers::storedFileName($originalName);

        $dir = self::directory($subDir);
        $target = $dir . '/' . $storedName;

        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target); // CLI tests

        if (!$moved) {
            throw new ApiException('Could not save the uploaded file. Check storage/ permissions.', 500);
        }

        chmod($target, 0640);

        return [
            'file_name'     => $originalName,
            'stored_name'   => $storedName,
            'mime_type'     => $mime,
            'file_size'     => $size,
            'absolute_path' => $target,
        ];
    }

    public static function directory(string $subDir): string
    {
        $subDir = preg_replace('/[^a-z0-9_\-]/i', '', $subDir) ?: 'misc';
        $base   = rtrim((string) Config::get('storage.path'), '/');
        $dir    = $base . '/uploads/' . $subDir . '/' . date('Y') . '/' . date('m');

        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new ApiException('Storage directory is not writable: ' . $dir, 500);
        }

        return $dir;
    }

    /** Resolve a stored_name back to an absolute path (searches year/month dirs). */
    public static function resolve(string $subDir, string $storedName): ?string
    {
        $storedName = basename($storedName);
        $base = rtrim((string) Config::get('storage.path'), '/') . '/uploads/' . $subDir;

        // stored_name starts with YYYYMMDD so we can jump straight to the folder.
        if (preg_match('/^(\d{4})(\d{2})\d{2}_/', $storedName, $m)) {
            $direct = "{$base}/{$m[1]}/{$m[2]}/{$storedName}";
            if (is_file($direct)) {
                return $direct;
            }
        }

        foreach (glob("{$base}/*/*/" . $storedName) ?: [] as $found) {
            return $found;
        }

        return null;
    }

    public static function deleteStored(string $subDir, string $storedName): void
    {
        $path = self::resolve($subDir, $storedName);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return mime_content_type($path) ?: 'application/octet-stream';
    }

    private static function assertNoUploadError(array $file): void
    {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($code === UPLOAD_ERR_OK) {
            return;
        }

        throw ApiException::badRequest(match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted, please retry',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the file',
            default                                   => 'File upload failed',
        });
    }
}
