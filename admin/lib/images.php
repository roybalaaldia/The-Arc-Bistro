<?php
declare(strict_types=1);

const IMG_MAX_BYTES = 5 * 1024 * 1024;
const IMG_MAX_EDGE = 1600;
const IMG_MAX_PIXELS = 40000000;

function images_memory_limit_bytes(?string $ini = null): int
{
    $v = trim($ini ?? (string)ini_get('memory_limit'));
    if ($v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

function images_process(string $tmpPath): string
{
    if ($tmpPath === '' || !is_file($tmpPath)) throw new InvalidArgumentException('No photo was received');
    if (filesize($tmpPath) > IMG_MAX_BYTES) throw new InvalidArgumentException('That photo is larger than 5 MB. Please choose a smaller one.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    $info = $ext === null ? false : @getimagesize($tmpPath);
    if ($info === false) throw new InvalidArgumentException('Please upload a JPG, PNG or WebP photo.');
    if ($info[0] * $info[1] > IMG_MAX_PIXELS) throw new InvalidArgumentException('That photo has too many pixels. Please resize it first.');
    if ($ext === 'webp' && !function_exists('imagewebp')) throw new InvalidArgumentException('WebP photos are not supported on this server. Please use JPG or PNG.');

    // GD truecolor = 4 bytes/pixel; allow for source + rotated/scaled copies. A memory fatal is uncatchable, so refuse first.
    if ($info[0] * $info[1] * 4 * 3 + memory_get_usage() > images_memory_limit_bytes()) {
        throw new InvalidArgumentException('That photo is too large to process. Please choose a smaller photo (about 12 megapixels or less works everywhere).');
    }

    $src = @imagecreatefromstring((string)file_get_contents($tmpPath));
    if ($src === false) throw new InvalidArgumentException('That photo could not be read.');

    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $orient = (int)(@exif_read_data($tmpPath)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
        if ($deg) $src = imagerotate($src, $deg, 0) ?: $src;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, IMG_MAX_EDGE / max($w, $h));
    $dst = $scale < 1 ? imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale))) : $src;
    if ($dst === false) throw new InvalidArgumentException('That photo could not be resized.');
    if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }

    $dir = arc_cfg('uploads');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new InvalidArgumentException('The photo could not be saved.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $ok = match ($ext) {
        'jpg' => @imagejpeg($dst, "$dir/$name", 82),
        'png' => @imagepng($dst, "$dir/$name", 6),
        'webp' => @imagewebp($dst, "$dir/$name", 82),
    };
    if (!$ok) {
        @unlink("$dir/$name"); // never leave a partial file behind
        throw new InvalidArgumentException('The photo could not be saved.');
    }
    return 'uploads/' . $name;
}

/** Names (not paths) of uploaded photos that the current content or any saved version still uses. */
function images_referenced(): array
{
    $files = [arc_cfg('content')];
    foreach (glob(arc_cfg('data') . '/backups/content-*.json') ?: [] as $f) $files[] = $f;
    $names = [];
    foreach ($files as $f) {
        if (is_file($f) && preg_match_all('#uploads/([0-9a-f]{16}\.(?:jpg|png|webp))#', (string)file_get_contents($f), $m)) {
            foreach ($m[1] as $n) $names[$n] = true;
        }
    }
    return $names;
}

function images_unused(): array
{
    $used = images_referenced();
    $out = [];
    foreach (glob(arc_cfg('uploads') . '/*') ?: [] as $f) {
        $n = basename($f);
        if (preg_match('/^[0-9a-f]{16}\.(jpg|png|webp)$/', $n) && !isset($used[$n])) $out[] = ['name' => $n, 'size' => (int)filesize($f)];
    }
    return $out;
}
