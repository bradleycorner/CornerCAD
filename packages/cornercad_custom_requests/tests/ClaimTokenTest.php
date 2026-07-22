<?php

use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\ClaimToken;

require __DIR__ . '/../src/CustomRequest/ClaimToken.php';

function test_claimtokentest(): array
{
    $a = ClaimToken::generate();
    $b = ClaimToken::generate();

    return [
        'length >= 32' => strlen($a) >= 32,
        'unique' => $a !== $b,
        'url-safe' => preg_match('/^[A-Za-z0-9_-]+$/', $a) === 1,
        'matches self' => ClaimToken::matches($a, $a),
        'rejects other' => !ClaimToken::matches($a, $b),
        'rejects empty stored' => !ClaimToken::matches('', ''),
    ];
}
