<?php
declare(strict_types=1);

foreach (['config', 'util', 'validate', 'store', 'auth', 'handlers'] as $lib) {
    require_once __DIR__ . '/' . $lib . '.php';
}
