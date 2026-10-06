<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function people(): array
{
    fresh_env();
    user_create('dev', 'd@b.co', 'longenough1', 'developer');
    user_create('boss', 'o@b.co', 'longenough1', 'owner');
    user_create('helper', 's@b.co', 'longenough1', 'staff');
    return ['dev' => user_by('username', 'dev'), 'owner' => user_by('username', 'boss'), 'staff' => user_by('username', 'helper')];
}

t('accounts.list: owner does not see the developer; developer sees everyone; staff refused', function () {
    $p = people();
    [$st, $b] = handle_accounts_list($p['owner'], []);
    eq($st, 200); eq(count($b['users']), 2);
    [, $b] = handle_accounts_list($p['dev'], []);
    eq(count($b['users']), 3);
    [$st] = handle_accounts_list($p['staff'], []);
    eq($st, 403);
});
t('accounts.create: owner may add staff only; developer may add owners', function () {
    $p = people();
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new1', 'email' => 'n1@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 200);
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new2', 'email' => 'n2@b.co', 'password' => 'longenough1', 'role' => 'owner']);
    eq($st, 403);
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new3', 'email' => 'n3@b.co', 'password' => 'longenough1', 'role' => 'developer']);
    eq($st, 403);
    [$st] = handle_accounts_create($p['dev'], ['username' => 'new4', 'email' => 'n4@b.co', 'password' => 'longenough1', 'role' => 'owner']);
    eq($st, 200);
    [$st] = handle_accounts_create($p['staff'], ['username' => 'new5', 'email' => 'n5@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 403);
});
t('accounts.create: duplicate username gives 422 with a readable message', function () {
    $p = people();
    [$st, $b] = handle_accounts_create($p['owner'], ['username' => 'helper', 'email' => 'x@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 422); ok(str_contains($b['error'], 'taken'));
});
t('accounts.delete: owner can remove staff, not the developer, not themselves', function () {
    $p = people();
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['dev']['id']]);
    eq($st, 404, 'developer is invisible to the owner');
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['owner']['id']]);
    eq($st, 422);
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['staff']['id']]);
    eq($st, 200);
    ok(user_by('username', 'helper') === null);
});
t('accounts.delete: staff refused', function () {
    $p = people();
    [$st] = handle_accounts_delete($p['staff'], ['id' => $p['owner']['id']]);
    eq($st, 403);
    ok(user_by('username', 'boss') !== null);
});
t('accounts.send_reset: owner can send to staff; staff cannot', function () {
    $p = people();
    $n = 0;
    $GLOBALS['ARC_MAILER'] = function () use (&$n) { $n++; return true; };
    [$st] = handle_accounts_send_reset($p['owner'], ['id' => $p['staff']['id']]);
    eq($st, 200); eq($n, 1);
    [$st] = handle_accounts_send_reset($p['staff'], ['id' => $p['owner']['id']]);
    eq($st, 403); eq($n, 1);
});
t('account.password: needs the current password and a strong new one', function () {
    $p = people();
    [$st] = handle_account_password($p['staff'], ['current' => 'wrong', 'new' => 'brandnewpass1']);
    eq($st, 422);
    [$st] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'short']);
    eq($st, 422);
    [$st] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'brandnewpass1']);
    eq($st, 200);
    ok(auth_login('helper', 'brandnewpass1', '1.1.1.1', 5000)['ok']);
});
t('history: staff refused; owner can list and restore; bad names get 422', function () {
    $p = people();
    store_update(function ($c) { $c['price']['label'] = 'Changed'; return $c; }, 0, 'x');
    [$st] = handle_history_list($p['staff'], []);
    eq($st, 403);
    [$st, $b] = handle_history_list($p['owner'], []);
    eq($st, 200); eq(count($b['versions']), 1);
    [$st] = handle_history_restore($p['staff'], ['name' => $b['versions'][0]['name']]);
    eq($st, 403);
    [$st] = handle_history_restore($p['owner'], ['name' => '../../x']);
    eq($st, 422);
    [$st] = handle_history_restore($p['owner'], ['name' => $b['versions'][0]['name']]);
    eq($st, 200);
    eq(store_read()['price']['label'], 'Inexpensive to moderate');
});
t('system: owner and staff refused; developer reads the last 200 log lines', function () {
    $p = people();
    for ($i = 0; $i < 250; $i++) log_line('activity', "line $i");
    [$st] = handle_system_logs($p['owner'], []);
    eq($st, 403);
    [$st, $b] = handle_system_logs($p['dev'], ['kind' => 'activity']);
    eq($st, 200); eq(count($b['lines']), 200); ok(str_ends_with($b['lines'][199], 'line 249'));
});
t('smtp: blank password keeps the old one; http or path baseUrl rejected', function () {
    $p = people();
    $ok = ['host' => 'smtp.example.com', 'port' => 465, 'user' => 'no-reply@x.com', 'pass' => 'secret', 'from' => 'no-reply@x.com', 'baseUrl' => 'https://x.com'];
    [$st] = handle_system_smtp_save($p['dev'], $ok);
    eq($st, 200);
    [$st] = handle_system_smtp_save($p['dev'], ['pass' => ''] + $ok);
    eq($st, 200);
    eq(json_read(arc_cfg('data') . '/smtp.json')['pass'], 'secret');
    [, $b] = handle_system_smtp_get($p['dev'], []);
    eq($b['passSet'], true); ok(!isset($b['pass']));
    [$st] = handle_system_smtp_save($p['dev'], ['baseUrl' => 'http://x.com'] + $ok);
    eq($st, 422);
    [$st] = handle_system_smtp_save($p['dev'], ['baseUrl' => 'https://x.com/admin'] + $ok);
    eq($st, 422);
    [$st] = handle_system_smtp_save($p['owner'], $ok);
    eq($st, 403);
});
t('images: referenced files are never listed as unused or deletable; path tricks refused', function () {
    $p = people();
    $used = 'aaaaaaaaaaaaaaaa.jpg';
    $spare = 'bbbbbbbbbbbbbbbb.jpg';
    file_put_contents(arc_cfg('uploads') . "/$used", 'x');
    file_put_contents(arc_cfg('uploads') . "/$spare", 'x');
    store_update(function ($c) use ($used) { $c['featured'][0]['image'] = "uploads/$used"; return $c; }, 0, 'x');
    [, $b] = handle_system_images_unused($p['dev'], []);
    eq(array_column($b['images'], 'name'), [$spare]);
    [$st] = handle_system_images_delete($p['dev'], ['name' => $used]);
    eq($st, 422); ok(is_file(arc_cfg('uploads') . "/$used"));
    [$st] = handle_system_images_delete($p['dev'], ['name' => '../content.json']);
    eq($st, 422);
    [$st] = handle_system_images_delete($p['dev'], ['name' => $spare]);
    eq($st, 200); ok(!is_file(arc_cfg('uploads') . "/$spare"));
    [$st] = handle_system_images_delete($p['owner'], ['name' => $spare]);
    eq($st, 403);
});
t('no account handler response ever contains a password hash', function () {
    $p = people();
    $resp = [
        handle_accounts_list($p['owner'], []), handle_accounts_list($p['dev'], []),
        handle_accounts_create($p['dev'], ['username' => 'new9', 'email' => 'n9@b.co', 'password' => 'longenough1', 'role' => 'staff']),
        handle_accounts_delete($p['owner'], ['id' => $p['owner']['id']]),
        handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'brandnewpass1']),
        handle_accounts_list($p['staff'], []),
    ];
    $s = json_encode($resp);
    ok(!str_contains($s, '"hash"') && !str_contains($s, '$2y$'), 'hash leaked');
});
t('system.logs: only activity and error are readable; the mail log (reset links) never is', function () {
    $p = people();
    log_line('mail', 'RESET LINK https://x/reset?token=SECRET');
    log_line('activity', 'visible activity');
    foreach (['mail', '../x', '../log/mail', 'mail.log', ['mail'], null] as $k) {
        [$st, $b] = handle_system_logs($p['dev'], ['kind' => $k]);
        eq($st, 200);
        ok(!str_contains(json_encode($b), 'SECRET'), 'mail log exposed for kind ' . json_encode($k));
    }
    [, $b] = handle_system_logs($p['dev'], ['kind' => 'error']);
    eq($b['lines'], []);
});
t('account.password: guesses are throttled, logged, cleared on success, per user', function () {
    $p = people();
    $now = 1000000;
    for ($i = 0; $i < 5; $i++) {
        [$st] = handle_account_password($p['staff'], ['current' => "wrongguess$i", 'new' => 'brandnewpass1'], $now);
        eq($st, 422);
    }
    [$st, $b] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'brandnewpass1'], $now + 5);
    eq($st, 429); ok(str_contains($b['error'], 'Too many attempts'));
    ok(auth_login('helper', 'longenough1', '1.1.1.1', 5000)['ok'], 'password unchanged while locked');
    [$st] = handle_account_password($p['owner'], ['current' => 'longenough1', 'new' => 'ownernewpass1'], $now + 5);
    eq($st, 200, 'other user unaffected');
    [$st] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'brandnewpass1'], $now + 601);
    eq($st, 200, 'works after the lock expires');
    for ($i = 0; $i < 4; $i++) handle_account_password($p['staff'], ['current' => 'nope', 'new' => 'x'], $now + 700);
    handle_account_password($p['staff'], ['current' => 'brandnewpass1', 'new' => 'anotherpass12'], $now + 700);
    for ($i = 0; $i < 4; $i++) handle_account_password($p['staff'], ['current' => 'nope', 'new' => 'x'], $now + 700);
    [$st] = handle_account_password($p['staff'], ['current' => 'anotherpass12', 'new' => 'thirdpassword1'], $now + 700);
    eq($st, 200, 'success cleared the counter');
    $log = (string)file_get_contents(arc_cfg('data') . '/log/activity.log');
    ok(str_contains($log, 'helper failed a password change check'));
    ok(!str_contains($log, 'wrongguess') && !str_contains($log, 'longenough1') && !str_contains($log, 'brandnewpass1'));
});
