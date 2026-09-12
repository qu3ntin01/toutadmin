<?php

/**
 * Lance toute la suite : php tests/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);
foreach ($files as $file) {
    echo "\n" . basename($file, '.php') . "\n";
    require $file;
}

exit(Tests::summary());
