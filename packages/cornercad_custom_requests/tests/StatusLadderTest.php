<?php

use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\StatusLadder;

require __DIR__ . '/../src/CustomRequest/StatusLadder.php';

function test_statusladdertest(): array
{
    $opts = StatusLadder::options();

    return [
        'default is New' => StatusLadder::default() === 'New',
        'first option is New' => ($opts[0] ?? null) === 'New',
        'has 10 statuses' => count($opts) === 10,
        'includes Quoted (awaiting approval)' => in_array('Quoted (awaiting approval)', $opts, true),
        'includes Declined / Not a fit' => in_array('Declined / Not a fit', $opts, true),
        'Completed present' => in_array('Completed', $opts, true),
        'isValid true for In design' => StatusLadder::isValid('In design'),
        'isValid false for bogus' => !StatusLadder::isValid('Nope'),
    ];
}
