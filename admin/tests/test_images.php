<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function make_jpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 120, 60));
    $f = sys_get_temp_dir() . '/arc-img-' . bin2hex(random_bytes(3)) . '.jpg';
    imagejpeg($im, $f, 90);
    return $f;
}

t('gd is available', function () { ok(function_exists('imagecreatetruecolor'), 'enable the gd extension in php.ini'); });

t('a large jpeg is shrunk to 1600px and given a random name in uploads/', function () {
    fresh_env();
    $path = images_process(make_jpeg(2000, 1000));
    ok(preg_match('#^uploads/[0-9a-f]{16}\.jpg$#', $path) === 1, $path);
    [$w, $h] = getimagesize(arc_cfg('root') . '/' . $path);
    eq($w, 1600); eq($h, 800);
});
t('a small image keeps its size', function () {
    fresh_env();
    $path = images_process(make_jpeg(400, 300));
    [$w] = getimagesize(arc_cfg('root') . '/' . $path);
    eq($w, 400);
});
t('a PHP or text file renamed .jpg is rejected and nothing is written', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/evil-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($f, '<?php echo "pwned";');
    throws(fn() => images_process($f), InvalidArgumentException::class);
    eq(count(glob(arc_cfg('uploads') . '/*') ?: []), 0);
});
t('a gif is rejected (only jpg, png, webp)', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/a-' . bin2hex(random_bytes(3)) . '.gif';
    imagegif(imagecreatetruecolor(10, 10), $f);
    throws(fn() => images_process($f), InvalidArgumentException::class);
});
t('a file over 5 MB is rejected before decoding', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/big-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($f, str_repeat('A', 5 * 1024 * 1024 + 10));
    $e = throws(fn() => images_process($f), InvalidArgumentException::class);
    ok(str_contains($e->getMessage(), '5 MB'));
});
t('a png keeps being a png', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/p-' . bin2hex(random_bytes(3)) . '.png';
    imagepng(imagecreatetruecolor(50, 50), $f);
    ok(str_ends_with(images_process($f), '.png'));
});
t('handle_upload reports a failed upload nicely', function () {
    fresh_env();
    [$st, $b] = handle_upload(['role' => 'staff', 'username' => 's'], ['tmp' => '', 'err' => UPLOAD_ERR_NO_FILE]);
    eq($st, 400); eq($b['ok'], false);
});
t('a missing tmp path is rejected', function () {
    fresh_env();
    throws(fn() => images_process(''), InvalidArgumentException::class);
    throws(fn() => images_process(sys_get_temp_dir() . '/nope-' . bin2hex(random_bytes(3))), InvalidArgumentException::class);
});
t('an image with too many pixels is rejected from the header, before decoding', function () {
    fresh_env();
    // header-only PNG claiming 7000x7000 (49M px); decoding it would blow memory
    $ihdr = pack('NN', 7000, 7000) . "\x08\x02\x00\x00\x00";
    $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    $f = sys_get_temp_dir() . '/px-' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($f, $png);
    $e = throws(fn() => images_process($f), InvalidArgumentException::class);
    ok(str_contains($e->getMessage(), 'pixels'), $e->getMessage());
    eq(count(glob(arc_cfg('uploads') . '/*') ?: []), 0);
});
t('a truncated jpeg that passes the header check but cannot decode is rejected, nothing left behind', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/trunc-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($f, substr((string)file_get_contents(make_jpeg(200, 200)), 0, 40));
    throws(fn() => images_process($f), InvalidArgumentException::class);
    eq(count(glob(arc_cfg('uploads') . '/*') ?: []), 0);
});
t('the extension comes from the real content, not the client name', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/named-' . bin2hex(random_bytes(3)) . '.php';
    imagepng(imagecreatetruecolor(20, 20), $f);
    ok(str_ends_with(images_process($f), '.png'));
});
t('handle_upload returns the path on success and 422 on a bad file', function () {
    fresh_env();
    $u = ['role' => 'staff', 'username' => 's'];
    [$st, $b] = handle_upload($u, ['tmp' => make_jpeg(100, 100), 'err' => UPLOAD_ERR_OK]);
    eq($st, 200); ok(str_starts_with($b['path'], 'uploads/'));
    $f = sys_get_temp_dir() . '/bad-' . bin2hex(random_bytes(3));
    file_put_contents($f, 'not an image');
    [$st, $b] = handle_upload($u, ['tmp' => $f, 'err' => UPLOAD_ERR_OK]);
    eq($st, 422); eq($b['ok'], false);
});
