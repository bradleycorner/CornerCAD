<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

/**
 * Single source of truth for the custom-request status ladder.
 *
 * Used by the installer (to seed the cr_status select options) and by the
 * My Requests view (to render / validate the customer-visible label). Keep this
 * the ONLY place the status strings are defined.
 */
class StatusLadder
{
    public static function options(): array
    {
        return [
            'New',
            'Reviewing',
            'Quoted (awaiting approval)',
            'Approved',
            'In design',
            'In production',
            'Shipped',
            'Completed',
            'Declined / Not a fit',
            'On hold',
        ];
    }

    public static function default(): string
    {
        return 'New';
    }

    public static function isValid(string $s): bool
    {
        return in_array($s, self::options(), true);
    }
}
