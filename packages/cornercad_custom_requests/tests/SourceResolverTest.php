<?php

use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\SourceResolver;

require __DIR__ . '/../src/CustomRequest/SourceResolver.php';

function test_sourceresolvertest(): array
{
    return [
        'business maps' => SourceResolver::label('business') === 'Business',
        'individual maps' => SourceResolver::label('individual') === 'Individual',
        'null defaults individual' => SourceResolver::label(null) === 'Individual',
        'garbage defaults individual' => SourceResolver::label('xyz') === 'Individual',
        'case-insensitive' => SourceResolver::label('BUSINESS') === 'Business',
    ];
}
