<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

/**
 * Clamps free-form posted select values to the known option sets, so a tampered
 * form POST can't inject arbitrary strings into the stored request. Pure /
 * framework-free so it is unit-testable outside the CMS.
 */
class InputSanitizer
{
    public static function material(string $v): string
    {
        $ok = ['FDM print', 'Resin print', 'Laser engraving', 'Unsure / other'];

        return in_array($v, $ok, true) ? $v : 'Unsure / other';
    }

    public static function budget(string $v): string
    {
        $ok = ['Under $50', '$50–$150', '$150–$500', '$500+', 'Prefer not to say / other'];

        return in_array($v, $ok, true) ? $v : 'Prefer not to say / other';
    }
}
