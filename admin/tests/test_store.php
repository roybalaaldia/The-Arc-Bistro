<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/store.php';

t('contact: valid input passes and is returned clean', function () {
    [$c, $e] = validate_section('contact', GOOD_CONTACT);
    eq($e, []); eq($c['phone'], '+63 995 109 1503');
});
t('contact: bad phone and bad email are rejected', function () {
    [, $e] = validate_section('contact', ['phone' => 'call me', 'email' => 'nope'] + GOOD_CONTACT);
    ok(count($e) === 2, 'expected 2 errors, got ' . count($e));
});
t('hours: closing time must be after opening time', function () {
    $w = week(); $w[1]['to'] = '09:00';
    [, $e] = validate_section('hours', $w);
    ok(count($e) === 1 && str_contains($e[0], 'closing time'));
});
t('hours: all days closed is valid', function () {
    [$c, $e] = validate_section('hours', week('closed'));
    eq($e, []); eq(count($c), 7);
});
t('hours: a missing day is rejected', function () {
    $w = week(); array_pop($w);
    [, $e] = validate_section('hours', $w);
    ok(count($e) === 1);
});
t('featured: too-long name and bad image path are rejected', function () {
    [, $e] = validate_section('featured', [['name' => str_repeat('x', 41), 'image' => '../secret.jpg']]);
    ok(count($e) === 2, 'got ' . json_encode($e));
});
t('featured: encoded space in image path is accepted', function () {
    [$c, $e] = validate_section('featured', [['name' => 'Bisque', 'image' => 'assets/Shrimp%20Bisque.jpg']]);
    eq($e, []); eq($c[0]['image'], 'assets/Shrimp%20Bisque.jpg'); ok(strlen($c[0]['id']) >= 3);
});
t('featured: more than 8 dishes rejected', function () {
    [, $e] = validate_section('featured', array_fill(0, 9, ['name' => 'A']));
    ok(count($e) >= 1);
});
t('promos: end before start rejected, same-day window accepted', function () {
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-10-10', 'end' => '2026-10-09', 'active' => true]]);
    ok(count($e) === 1);
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-10-10', 'end' => '2026-10-10', 'active' => true]]);
    eq($e, []);
});
t('promos: impossible date rejected', function () {
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-02-31']]);
    ok(count($e) === 1);
});
t('menu: price must be numeric; empty price becomes null', function () {
    [, $e] = validate_section('menu', [['name' => 'Pasta', 'items' => [['name' => 'A', 'price' => 'abc']]]]);
    ok(count($e) === 1);
    [$c, $e] = validate_section('menu', [['name' => 'Pasta', 'items' => [['name' => 'A', 'price' => '']]]]);
    eq($e, []); eq($c[0]['items'][0]['price'], null);
});
t('social: only https links', function () {
    [, $e] = validate_section('social', [['label' => 'Site', 'url' => 'javascript:alert(1)']]);
    ok(count($e) === 1);
    [, $e] = validate_section('social', [['label' => 'Instagram', 'url' => 'https://instagram.com/thearc']]);
    eq($e, []);
});
t('price: level 1..3 only', function () {
    [, $e] = validate_section('price', ['level' => 4, 'label' => 'x']);
    ok(count($e) === 1);
});
t('unknown section rejected', function () {
    [, $e] = validate_section('users', []);
    ok(count($e) === 1);
});
t('store: update makes a backup, bumps revision, keeps valid JSON', function () {
    fresh_env();
    $rev = store_update(function ($c) { $c['price']['label'] = 'Changed'; return $c; }, 0, 'tester');
    eq($rev, 1);
    eq(store_read()['price']['label'], 'Changed');
    eq(count(store_versions()), 1);
});
t('store: stale revision is refused and nothing changes', function () {
    fresh_env();
    store_update(fn($c) => $c, 0, 'a');
    throws(fn() => store_update(function ($c) { $c['price']['label'] = 'Lost'; return $c; }, 0, 'b'), ConflictException::class);
    ok(store_read()['price']['label'] !== 'Lost');
});
t('store: restore rejects path-like names', function () {
    fresh_env();
    throws(fn() => store_restore('../../etc/passwd', 'x'), InvalidArgumentException::class);
});
t('store: restore brings an older version back', function () {
    fresh_env();
    store_update(function ($c) { $c['price']['label'] = 'Second'; return $c; }, 0, 'a');
    $name = store_versions()[0]['name'];
    store_restore($name, 'a');
    eq(store_read()['price']['label'], 'Inexpensive to moderate');
});
t('store: invalid JSON in the content file raises instead of returning junk', function () {
    $d = fresh_env();
    file_put_contents($d . '/content.json', '{nope');
    throws(fn() => store_read(), RuntimeException::class);
});
