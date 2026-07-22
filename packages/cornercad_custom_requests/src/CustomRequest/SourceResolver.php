<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

/**
 * Maps the ?source= query param to a stored Source label.
 *
 * Deliberately param-driven (not site-detection) so one shared submit
 * single-page serves both multisite front doors without per-site page-tree
 * placement: cornercad.com links with source=individual, cornercadworks.com
 * with source=business.
 */
class SourceResolver
{
    public static function label(?string $param): string
    {
        return strtolower((string) $param) === 'business' ? 'Business' : 'Individual';
    }
}
