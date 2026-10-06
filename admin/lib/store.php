<?php
declare(strict_types=1);

class ConflictException extends RuntimeException {}

function store_read(): array
{
    $d = json_read(arc_cfg('content'), null);
    if (!is_array($d)) throw new RuntimeException('content.json is missing or not valid JSON');
    return $d;
}

function store_write(array $content, string $who, ?int $unreadableBase = null): int
{
    $file = arc_cfg('content');
    $bdir = arc_cfg('data') . '/backups';
    if (!is_dir($bdir)) mkdir($bdir, 0775, true);
    if ($unreadableBase !== null) {
        $rev = $unreadableBase + 1; // unreadable file: no backup of it, and stay above every existing backup
    } else {
        $prev = is_file($file) ? json_read($file, []) : [];
        if (is_file($file)) copy($file, $bdir . '/content-' . date('Ymd-His') . '-' . sprintf('%04x', (int)($prev['revision'] ?? 0) % 65536) . '.json');
        $rev = (int)($prev['revision'] ?? 0) + 1;
    }
    $content['version'] = 1;
    $content['revision'] = $rev;
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
    return with_file_lock('content', function () use ($mutate, $baseRevision, $who) {
        try {
            $cur = store_read();
            $unreadable = null;
        } catch (RuntimeException $e) {
            if ($baseRevision !== null) throw $e; // a normal save must not proceed on unreadable content
            $unreadable = 0;
            foreach (glob(arc_cfg('data') . '/backups/content-*.json') ?: [] as $f) $unreadable = max($unreadable, (int)(json_read($f, [])['revision'] ?? 0));
            $cur = ['revision' => $unreadable];
        }
        if ($baseRevision !== null && (int)($cur['revision'] ?? 0) !== $baseRevision) {
            throw new ConflictException('Someone else saved changes while you were editing. Copy anything you typed, reload the page, and apply it again.');
        }
        return store_write($mutate($cur), $who, $unreadable);
    });
}

function store_versions(): array
{
    $files = glob(arc_cfg('data') . '/backups/content-*.json') ?: [];
    rsort($files);
    return array_map(function ($f) {
        $d = json_read($f, []);
        return ['name' => basename($f), 'time' => (string)($d['updatedAt'] ?? date('c', (int)filemtime($f))), 'revision' => (int)($d['revision'] ?? 0), 'size' => (int)filesize($f)];
    }, $files);
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
