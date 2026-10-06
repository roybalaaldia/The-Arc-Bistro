<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

function arc_cfg(string $key): string
{
    static $defaults = null;
    if ($defaults === null) {
        $root = dirname(__DIR__, 2);
        $defaults = [
            'root' => $root,
            'content' => $root . '/content.json',
            'uploads' => $root . '/uploads',
            'data' => dirname(__DIR__) . '/data',
        ];
    }
    return $GLOBALS['ARC_CFG'][$key] ?? $defaults[$key];
}
