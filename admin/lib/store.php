<?php
declare(strict_types=1);

class ConflictException extends RuntimeException {}

function store_read(): array
{
    $d = json_read(arc_cfg('content'), null);
    if (!is_array($d)) throw new RuntimeException('content.json is missing or not valid JSON');
    return $d;
}

function store_write(array $content, string $who): int
{
    $file = arc_cfg('content');
    $bdir = arc_cfg('data') . '/backups';
    if (!is_dir($bdir)) mkdir($bdir, 0775, true);
    $prev = is_file($file) ? json_read($file, []) : [];
    if (is_file($file)) copy($file, $bdir . '/content-' . date('Ymd-His') . '-' . sprintf('%04x', (int)($prev['revision'] ?? 0) % 65536) . '.json');
    $content['version'] = 1;
    $content['revision'] = (int)($prev['revision'] ?? 0) + 1;
    $content['updatedAt'] = date('c');
    json_write_atomic($file, $content);
    $old = glob($bdir . '/content-*.json') ?: [];
    rsort($old);
    foreach (array_slice($old, 30) as $f) @unlink($f);
    log_line('activity', "$who saved revision {$content['revision']}");
    return $content['revision'];
}

function store_update(callable $mutate, ?int $baseRevision, string $who): int
{
    $dir = arc_cfg('data');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $h = fopen($dir . '/content.lock', 'c');
    if ($h === false || !flock($h, LOCK_EX)) {
        if ($h !== false) fclose($h);
        throw new RuntimeException('Could not lock the content file');
    }
    try {
        $cur = store_read();
        if ($baseRevision !== null && (int)($cur['revision'] ?? 0) !== $baseRevision) {
            throw new ConflictException('Someone else changed this while you were editing. Reload the page and try again.');
        }
        return store_write($mutate($cur), $who);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function store_versions(): array
{
    $files = glob(arc_cfg('data') . '/backups/content-*.json') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'time' => date('c', (int)filemtime($f)), 'size' => (int)filesize($f)], $files);
}

function store_restore(string $name, string $who): int
{
    if (!preg_match('/^content-\d{8}-\d{6}-[0-9a-f]{4}\.json$/', $name)) throw new InvalidArgumentException('Unknown version');
    $d = json_read(arc_cfg('data') . '/backups/' . $name, null);
    if (!is_array($d)) throw new InvalidArgumentException('That version was not found');
    $errs = validate_content($d);
    if ($errs) throw new InvalidArgumentException('That version is not valid: ' . implode('; ', $errs));
    foreach (SECTIONS as $s) [$d[$s]] = validate_section($s, $d[$s] ?? []);
    return store_update(fn($cur) => $d, null, "$who (restored $name)");
}
