<?php

use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\InputSanitizer;

require __DIR__ . '/../src/CustomRequest/InputSanitizer.php';

function test_inputsanitizertest(): array
{
    return [
        'known material passes' => InputSanitizer::material('FDM print') === 'FDM print',
        'unknown material -> Unsure' => InputSanitizer::material('haxx') === 'Unsure / other',
        'known budget passes' => InputSanitizer::budget('$500+') === '$500+',
        'unknown budget -> Prefer not' => InputSanitizer::budget('haxx') === 'Prefer not to say / other',
    ];
}
