<?php
declare(strict_types=1);

function json_read(string $file, $default = [])
{
    if (!is_file($file)) return $default;
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

function json_write_atomic(string $file, $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Could not write file');
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Could not replace file'); }
}

/** Runs $fn while holding an exclusive lock on data/<name>.lock. */
function with_file_lock(string $name, callable $fn)
{
    $dir = arc_cfg('data');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $h = fopen($dir . '/' . $name . '.lock', 'c');
    if ($h === false || !flock($h, LOCK_EX)) {
        if ($h !== false) fclose($h);
        throw new RuntimeException('Could not lock ' . $name);
    }
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function log_line(string $kind, string $msg): void
{
    $dir = arc_cfg('data') . '/log';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $msg = str_replace(["\r", "\n"], ' ', $msg);
    file_put_contents($dir . '/' . $kind . '.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
