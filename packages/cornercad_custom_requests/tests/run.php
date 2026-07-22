<?php

/**
 * Framework-free test runner for the pure-logic classes.
 *
 * Run on the host (PHP 8.3) or anywhere with PHP:
 *     php packages/cornercad_custom_requests/tests/run.php
 *
 * Each *Test.php defines one function test_<lowercasefilename>() returning
 * ['check name' => bool, ...]. Exit code is non-zero if any check fails.
 */

// Concrete's t() translation helper is unavailable outside the CMS; shim it so
// classes that call t() (e.g. FileValidator) work under the bare runner.
if (!function_exists('t')) {
    function t($s, ...$a)
    {
        return $a ? vsprintf($s, $a) : $s;
    }
}

$dir = __DIR__;
$failures = 0;
$total = 0;

foreach (glob($dir . '/*Test.php') as $file) {
    require $file;
    $fn = 'test_' . strtolower(pathinfo($file, PATHINFO_FILENAME));
    if (!function_exists($fn)) {
        echo "NO ENTRYPOINT: $file\n";
        $failures++;
        continue;
    }
    foreach ($fn() as $name => $ok) {
        $total++;
        echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
        if (!$ok) {
            $failures++;
        }
    }
}

echo "\n$total checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
