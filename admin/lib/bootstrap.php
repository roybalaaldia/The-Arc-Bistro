<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { ini_set('display_errors', '0'); } // warnings must never corrupt JSON/HTML; they still reach the error log

foreach (['config', 'util', 'validate', 'store', 'auth', 'images', 'mail', 'reset', 'handlers'] as $lib) {
    require_once __DIR__ . '/' . $lib . '.php';
}
