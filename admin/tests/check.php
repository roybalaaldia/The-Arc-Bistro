<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) require $file;
done();
