<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function token_from(string $body): string
{
    preg_match('/token=([0-9a-f]{48})/', $body, $m);
    return $m[1] ?? '';
}

t('reset: a known email gets one mail with a token, and only a hash is stored', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = [$to, $b]; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    eq(count($sent), 1); eq($sent[0][0], 'a@b.co');
    $token = token_from($sent[0][1]);
    eq(strlen($token), 48);
    ok(!str_contains((string)file_get_contents(arc_cfg('data') . '/resets.json'), $token), 'raw token must not be stored');
});
t('reset: an unknown email sends nothing and raises nothing', function () {
    fresh_env();
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $to; return true; };
    reset_request('nobody@b.co', '1.1.1.1', 1000);
    reset_request('', '1.1.1.1', 1000);
    eq($sent, []);
});
t('reset: a token works once, then never again', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    [$ok1] = reset_consume($t, 'brandnewpass1', 1100);
    [$ok2] = reset_consume($t, 'anotherpass22', 1200);
    ok($ok1); ok(!$ok2);
    ok(auth_login('okname', 'brandnewpass1', '1.1.1.1', 1300)['ok']);
    ok(!auth_login('okname', 'longenough1', '1.1.1.1', 1300)['ok']);
});
t('reset: a token expires after 30 minutes', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    ok(reset_lookup($t, 1000 + 1799) !== null);
    ok(reset_lookup($t, 1000 + 1801) === null);
    [$ok] = reset_consume($t, 'brandnewpass1', 1000 + 1801);
    ok(!$ok);
});
t('reset: a weak password is refused and the token stays usable', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    [$ok] = reset_consume($t, 'short', 1100);
    ok(!$ok);
    [$ok] = reset_consume($t, 'brandnewpass1', 1200);
    ok($ok);
});
t('reset: the 4th request within an hour for one email sends nothing', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $n = 0;
    $GLOBALS['ARC_MAILER'] = function () use (&$n) { $n++; return true; };
    foreach ([1000, 1100, 1200, 1300] as $now) reset_request('a@b.co', '1.1.1.1', $now);
    eq($n, 3);
    reset_request('a@b.co', '1.1.1.1', 1000 + 3700);
    eq($n, 4, 'allowed again after an hour');
});
t('reset: garbage tokens are rejected', function () {
    fresh_env();
    ok(reset_lookup('nope') === null);
    ok(reset_lookup(str_repeat('z', 48)) === null);
    ok(reset_lookup('') === null);
});
t('reset: past 10 requests an hour from one IP nothing more is stored', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $n = 0;
    $GLOBALS['ARC_MAILER'] = function () use (&$n) { $n++; return true; };
    for ($i = 0; $i < 15; $i++) reset_request("x$i@b.co", '9.9.9.9', 1000 + $i);
    eq(count(json_read(arc_cfg('data') . '/resets.json', [])), 10);
    reset_request('a@b.co', '9.9.9.9', 1100);
    eq($n, 0, 'blocked IP gets no mail');
});
