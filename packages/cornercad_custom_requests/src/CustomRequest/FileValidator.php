<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

/**
 * Attachment allow-list + size cap for guest-submittable files.
 *
 * Guests can upload (fill-first requires it), so this is a real abuse surface:
 * restrict to sketch/photo/model types and cap size. Turnstile guards the form
 * itself; this guards the payload.
 */
class FileValidator
{
    public const MAX_BYTES = 10485760; // 10 MB

    public static function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'pdf', 'step', 'stp', 'stl'];
    }

    /**
     * @return string|null error message, or null when the file is acceptable
     */
    public static function check(string $filename, int $bytes): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::allowedExtensions(), true)) {
            return t('File type not allowed: %s', $filename);
        }
        if ($bytes > self::MAX_BYTES) {
            return t('File too large (max 10 MB): %s', $filename);
        }

        return null;
    }
}
