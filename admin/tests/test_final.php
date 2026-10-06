<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

const FU_OWNER = ['id' => 'u2', 'username' => 'boss', 'role' => 'owner'];

function fu_breakable(string $how): string
{
    $d = fresh_env();
    store_update(function ($c) { $c['price']['label'] = 'One'; return $c; }, 0, 'a');
    store_update(function ($c) { $c['price']['label'] = 'Two'; return $c; }, 1, 'a');
    if ($how === 'corrupt') file_put_contents($d . '/content.json', '{broken'); else unlink($d . '/content.json');
    return $d;
}

// ---- A: recovery from corrupt or missing content.json
foreach (['corrupt', 'missing'] as $how) {
    t("recovery ($how): history lists versions, restore works, no corrupt backup, save and get fail cleanly", function () use ($how) {
        $d = fu_breakable($how);
        [$st, $b] = handle_history_list(FU_OWNER, []);
        eq($st, 200); eq($b['contentOk'], false); eq($b['revision'], 0); eq(count($b['versions']), 2);
        [$st, $b] = handle_content_get(FU_OWNER, []);
        eq($st, 500); eq($b['contentOk'], false); ok(!str_contains($b['error'], $d));
        [$st] = handle_content_save(FU_OWNER, ['section' => 'price', 'baseRevision' => 0, 'data' => ['level' => 2, 'label' => 'x']]);
        ok($st >= 400, 'normal save must fail');
        $maxBefore = max(array_column(store_versions(), 'revision'));
        [$st, $b] = handle_history_restore(FU_OWNER, ['name' => store_versions()[0]['name']]);
        eq($st, 200, json_encode($b));
        ok($b['revision'] > $maxBefore, 'revision above all backups');
        eq(store_read()['revision'], $b['revision']);
        eq(count(store_versions()), 2, 'unreadable file must not become a backup');
        foreach (glob($d . '/data/backups/*.json') as $f) ok(is_array(json_decode(file_get_contents($f), true)), 'backup valid');
        [$st, $b] = handle_history_list(FU_OWNER, []);
        eq($b['contentOk'], true);
        [$st] = handle_content_save(FU_OWNER, ['section' => 'price', 'baseRevision' => $b['revision'], 'data' => ['level' => 2, 'label' => 'ok']]);
        eq($st, 200);
    });
}
t('recovery: corrupt content and no backups at all gives a clean 422 on restore', function () {
    $d = fresh_env();
    file_put_contents($d . '/content.json', '{broken');
    [$st, $b] = handle_history_list(FU_OWNER, []);
    eq($st, 200); eq($b['versions'], []);
    [$st] = handle_history_restore(FU_OWNER, ['name' => 'content-20260101-000000-0000.json']);
    eq($st, 422);
});

// ---- C: history rows carry their own time and revision
t('store_versions: time is the backup own updatedAt, not its mtime', function () {
    $d = fresh_env();
    store_update(fn($c) => $c, 0, 'a');
    store_update(fn($c) => $c, 1, 'a');
    $v = store_versions()[0];
    eq($v['revision'], 1);
    $f = $d . '/data/backups/' . $v['name'];
    touch($f, 86400 * 365);
    $v = store_versions()[0];
    eq($v['time'], json_read($f)['updatedAt']);
    ok($v['time'] !== date('c', 86400 * 365));
    eq(array_keys($v), ['name', 'time', 'revision', 'size']);
});

// ---- B: lockout reservation, pruning, throttle
t('attempts: pruning drops expired and stale rows, keeps active locks and fresh counts', function () {
    $now = 100000;
    $a = attempts_prune([
        'expired' => ['count' => 0, 'until' => $now - 1, 'at' => $now - 10],
        'stale' => ['count' => 2, 'until' => 0, 'at' => $now - 3601],
        'noat' => ['count' => 2, 'until' => 0],
        'locked' => ['count' => 0, 'until' => $now + 5, 'at' => $now - 5000],
        'fresh' => ['count' => 2, 'until' => 0, 'at' => $now - 10],
    ], $now);
    eq(array_keys($a), ['locked', 'fresh']);
});
t('attempts: reservations alone (no verification) lock after MAX_ATTEMPTS, like parallel guesses', function () {
    fresh_env();
    for ($i = 0; $i < MAX_ATTEMPTS; $i++) eq(attempt_reserve('k', 1000), 0, "reserve $i");
    ok(attempt_reserve('k', 1000) > 0, 'next reservation is locked');
    eq(attempt_reserve('k', 1000 + LOCK_SECONDS), 0, 'lock expires');
});
t('login: correct password on the 5th attempt succeeds and leaves no row', function () {
    fresh_env();
    user_create('okname', 'k@b.co', 'longenough1', 'staff');
    for ($i = 0; $i < MAX_ATTEMPTS - 1; $i++) ok(!auth_login('okname', 'wrong-pass-1', '1.1.1.1', 1000)['ok']);
    ok(auth_login('okname', 'longenough1', '1.1.1.1', 1000)['ok']);
    eq(json_read(arc_cfg('data') . '/attempts.json', ['x']), []);
});
t('login: sequential semantics unchanged (5 wrong, 6th correct one is locked)', function () {
    fresh_env();
    user_create('okname', 'k@b.co', 'longenough1', 'staff');
    for ($i = 0; $i < MAX_ATTEMPTS; $i++) auth_login('okname', 'wrong-pass-1', '1.1.1.1', 1000);
    $r = auth_login('okname', 'longenough1', '1.1.1.1', 1001);
    ok(!$r['ok'] && str_contains($r['error'], 'Too many'));
});
t('password change: throttle locks after 5 wrong, correct on 5th clears the row', function () {
    fresh_env();
    user_create('okname', 'k@b.co', 'longenough1', 'staff');
    $u = user_by('username', 'okname');
    for ($i = 0; $i < MAX_ATTEMPTS - 1; $i++) eq(handle_account_password($u, ['current' => 'nope', 'new' => 'brandnewpass1'], 1000)[0], 422);
    eq(handle_account_password($u, ['current' => 'longenough1', 'new' => 'brandnewpass1'], 1000)[0], 200);
    eq(json_read(arc_cfg('data') . '/attempts.json', ['x']), []);
    $u = user_by('username', 'okname');
    for ($i = 0; $i < MAX_ATTEMPTS; $i++) handle_account_password($u, ['current' => 'nope', 'new' => 'x'], 2000);
    eq(handle_account_password($u, ['current' => 'brandnewpass1', 'new' => 'anotherpass12'], 2001)[0], 429);
    eq(handle_account_password($u, ['current' => 'brandnewpass1', 'new' => 'anotherpass12'], 2000 + LOCK_SECONDS)[0], 200);
});
t('password change throttle: reservations alone lock', function () {
    fresh_env();
    $key = attempts_key('pw', 'u1');
    for ($i = 0; $i < MAX_ATTEMPTS; $i++) attempt_reserve($key, 5);
    eq(handle_account_password(['id' => 'u1', 'username' => 'x', 'role' => 'staff'], ['current' => 'a', 'new' => 'b'], 5)[0], 429);
});

// ---- D: explicit bcrypt cost everywhere
t('bcrypt cost is PASSWORD_COST for the dummy hash, new users, password change and reset', function () {
    fresh_env();
    eq(password_get_info(DUMMY_HASH)['options']['cost'], PASSWORD_COST);
    $u = user_create('okname', 'k@b.co', 'longenough1', 'staff');
    eq(password_get_info($u['hash'])['options']['cost'], PASSWORD_COST);
    handle_account_password($u, ['current' => 'longenough1', 'new' => 'brandnewpass1']);
    eq(password_get_info(user_by('id', $u['id'])['hash'])['options']['cost'], PASSWORD_COST);
    $tok = null;
    $GLOBALS['ARC_MAILER'] = function ($to, $subj, $text) use (&$tok) { preg_match('/token=([0-9a-f]{48})/', $text, $m); $tok = $m[1] ?? null; return true; };
    reset_request('k@b.co', '3.3.3.4');
    ok($tok !== null, 'token captured');
    [$done] = reset_consume($tok, 'resetpass-123');
    ok($done);
    eq(password_get_info(user_by('id', $u['id'])['hash'])['options']['cost'], PASSWORD_COST);
});

// ---- F.10: owner may only send reset links to staff
t('send_reset: owner to owner 403, owner to staff 200, developer to owner 200', function () {
    fresh_env();
    $dev = user_create('dev', 'd@b.co', 'longenough1', 'developer');
    $o1 = user_create('boss', 'o@b.co', 'longenough1', 'owner');
    $o2 = user_create('boss2', 'o2@b.co', 'longenough1', 'owner');
    $st = user_create('helper', 's@b.co', 'longenough1', 'staff');
    $GLOBALS['ARC_MAILER'] = fn() => true;
    eq(handle_accounts_send_reset($o1, ['id' => $o2['id']])[0], 403);
    eq(handle_accounts_send_reset($o1, ['id' => $st['id']])[0], 200);
    eq(handle_accounts_send_reset($dev, ['id' => $o1['id']])[0], 200);
});
