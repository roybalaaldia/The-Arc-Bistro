<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/auth.php';

t('user_create: validates username, email, password length, role', function () {
    fresh_env();
    throws(fn() => user_create('a', 'a@b.co', 'longenough1', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'bad', 'longenough1', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'a@b.co', 'short', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'a@b.co', 'longenough1', 'admin'), InvalidArgumentException::class);
    $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    ok(password_verify('longenough1', $u['hash']) && $u['hash'] !== 'longenough1');
});
t('user_create: duplicate username or email refused', function () {
    fresh_env();
    user_create('okname', 'a@b.co', 'longenough1', 'owner');
    throws(fn() => user_create('OKNAME', 'c@d.co', 'longenough1', 'staff'), InvalidArgumentException::class);
    throws(fn() => user_create('other', 'a@b.co', 'longenough1', 'staff'), InvalidArgumentException::class);
});
t('login: success returns the user', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $r = auth_login('OkName', 'longenough1', '1.1.1.1', 1000);
    ok($r['ok'] && $r['user']['username'] === 'okname');
});
t('login: unknown user and wrong password give the identical message', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $a = auth_login('okname', 'wrongwrongwrong', '1.1.1.1', 1000);
    $b = auth_login('nobody', 'wrongwrongwrong', '1.1.1.1', 1000);
    ok(!$a['ok'] && !$b['ok']); eq($a['error'], $b['error']);
});
t('login: 5 failures lock the account for 10 minutes, then it opens again', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    for ($i = 0; $i < 5; $i++) auth_login('okname', 'bad', '1.1.1.1', 1000);
    $locked = auth_login('okname', 'longenough1', '1.1.1.1', 1001);
    ok(!$locked['ok'] && str_contains($locked['error'], 'Too many'));
    ok(auth_login('okname', 'longenough1', '9.9.9.9', 1001)['ok'], 'lock is per username + IP');
    ok(auth_login('okname', 'longenough1', '1.1.1.1', 1000 + 601)['ok']);
});
t('locking an unknown username behaves the same as a real one', function () {
    fresh_env();
    for ($i = 0; $i < 5; $i++) auth_login('ghost', 'bad', '1.1.1.1', 1000);
    ok(str_contains(auth_login('ghost', 'bad', '1.1.1.1', 1001)['error'], 'Too many'));
});
t('session: idle for more than 30 minutes ends the session', function () {
    fresh_env(); $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $_SESSION = ['uid' => $u['id'], 'seen' => 1000, 'csrf' => 'x'];
    ok(auth_current(1000 + 1799) !== null);
    $_SESSION['seen'] = 1000;
    eq(auth_current(1000 + 1801), null);
});
t('session: a deleted user loses access immediately', function () {
    fresh_env(); $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $_SESSION = ['uid' => $u['id'], 'seen' => time(), 'csrf' => 'x'];
    users_save([]);
    eq(auth_current(), null);
});
t('csrf: token must match and an empty token is never valid', function () {
    $_SESSION = ['csrf' => 'abc123'];
    ok(csrf_valid('abc123')); ok(!csrf_valid('abc124')); ok(!csrf_valid(''));
    $_SESSION = [];
    ok(!csrf_valid(''));
});
t('permissions: staff only menu and promos', function () {
    foreach (['featured', 'menu', 'promos'] as $s) ok(can_edit_section('staff', $s), $s);
    foreach (['contact', 'social', 'hours', 'price'] as $s) ok(!can_edit_section('staff', $s), $s);
    foreach (SECTIONS as $s) { ok(can_edit_section('owner', $s)); ok(can_edit_section('developer', $s)); }
    ok(!can_edit_section('owner', 'users'));
});
t('permissions: capabilities per role', function () {
    ok(can('staff', 'content') && !can('staff', 'accounts') && !can('staff', 'history') && !can('staff', 'system'));
    ok(can('owner', 'accounts') && can('owner', 'history') && !can('owner', 'system'));
    ok(can('developer', 'system'));
});
t('developer accounts are hidden from the owner', function () {
    fresh_env();
    user_create('dev', 'd@b.co', 'longenough1', 'developer');
    user_create('boss', 'o@b.co', 'longenough1', 'owner');
    user_create('helper', 's@b.co', 'longenough1', 'staff');
    eq(count(users_visible_to('owner')), 2);
    eq(count(users_visible_to('developer')), 3);
});
t('user_by: empty value never matches a record that lacks the field', function () {
    fresh_env();
    users_save([['id' => 'u1', 'username' => 'x', 'role' => 'owner', 'hash' => 'h']]);
    eq(user_by('email', ''), null);
    eq(auth_login('', 'whatever-password', '1.1.1.1', 1000)['ok'], false);
});
t('dummy hash: real bcrypt at the same cost as new hashes, never verifies', function () {
    eq(password_get_info(DUMMY_HASH)['options']['cost'], PASSWORD_COST);
    ok(!password_verify('anything', DUMMY_HASH));
});
