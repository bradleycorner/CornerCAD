<?php

use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\FileValidator;

require __DIR__ . '/../src/CustomRequest/FileValidator.php';

function test_filevalidatortest(): array
{
    $tenMB = 10 * 1024 * 1024;

    return [
        'jpg ok' => FileValidator::check('sketch.jpg', 1000) === null,
        'stl ok' => FileValidator::check('part.STL', 1000) === null,
        'step ok' => FileValidator::check('model.step', 1000) === null,
        'exe rejected' => FileValidator::check('evil.exe', 1000) !== null,
        'no ext rejected' => FileValidator::check('noext', 1000) !== null,
        'oversize rejected' => FileValidator::check('big.pdf', $tenMB + 1) !== null,
        'at limit ok' => FileValidator::check('ok.pdf', $tenMB) === null,
    ];
}
