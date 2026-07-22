<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

/**
 * One-time, URL-safe token used to claim an unclaimed request to a user account
 * via the emailed claim link. Compared in constant time.
 */
class ClaimToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function matches(string $stored, string $candidate): bool
    {
        if ($stored === '' || $candidate === '') {
            return false;
        }

        return hash_equals($stored, $candidate);
    }
}
