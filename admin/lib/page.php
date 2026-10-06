<?php
declare(strict_types=1);

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function page_head(string $title, string $bodyAttrs = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' · The ARC Bistro</title>'
        . '<link rel="stylesheet" href="assets/admin.css"></head><body ' . $bodyAttrs . '>';
}

function page_foot(string $scripts = ''): void { echo $scripts . '</body></html>'; }
