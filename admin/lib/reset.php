<?php
declare(strict_types=1);

const RESET_TTL = 1800;
const RESET_MAX_PER_EMAIL_HOUR = 3;
const RESET_MAX_PER_IP_HOUR = 10;

function reset_file(): string { return arc_cfg('data') . '/resets.json'; }

function reset_request(string $email, string $ip, ?int $now = null): void
{
    $now ??= time();
    $email = trim($email);
    $all = array_values(array_filter(json_read(reset_file(), []), fn($x) => (int)($x['at'] ?? 0) > $now - 86400));
    $ek = hash('sha256', strtolower($email));
    $ik = hash('sha256', $ip);
    $hour = $now - 3600;
    $byEmail = count(array_filter($all, fn($x) => $x['ek'] === $ek && (int)$x['at'] > $hour));
    $byIp = count(array_filter($all, fn($x) => $x['ik'] === $ik && (int)$x['at'] > $hour));
    // Over the per-IP limit: do nothing and store nothing, so resets.json cannot be grown without bound.
    // This depends only on the IP, never on whether the email exists.
    if ($byIp >= RESET_MAX_PER_IP_HOUR) return;
    $user = $email !== '' ? user_by('email', $email) : null;

    // Every request is recorded (known email or not) so rate limits cannot be used to probe accounts.
    $entry = ['ek' => $ek, 'ik' => $ik, 'at' => $now, 'uid' => null, 'hash' => null, 'expires' => 0, 'used' => true];
    $token = null;
    if ($user && $byEmail < RESET_MAX_PER_EMAIL_HOUR) {
        $token = bin2hex(random_bytes(24));
        $entry = ['ek' => $ek, 'ik' => $ik, 'at' => $now, 'uid' => $user['id'], 'hash' => hash('sha256', $token), 'expires' => $now + RESET_TTL, 'used' => false];
    }
    $all[] = $entry;
    json_write_atomic(reset_file(), $all);
    if ($token !== null) reset_mail($user, $token);
}

function reset_mail(array $user, string $token): void
{
    $cfg = json_read(arc_cfg('data') . '/smtp.json', []);
    $base = rtrim((string)($cfg['baseUrl'] ?? ''), '/');
    if ($base === '' && !empty($cfg['host']) && !isset($GLOBALS['ARC_MAILER'])) { // real SMTP needs a baseUrl; with no SMTP configured (local test) mail_send logs a host-less link
        log_line('error', 'reset mail skipped: baseUrl is not set in smtp.json');
        return;
    }
    $link = $base . '/admin/reset.php?token=' . $token;
    $text = "Hello {$user['username']},\n\n"
        . "Someone asked to reset the password for your The ARC Bistro admin account.\n"
        . "Open this link within 30 minutes to choose a new password:\n\n$link\n\n"
        . "If you did not ask for this, ignore this email. Your password stays the same.\n";
    mail_send($user['email'], 'Reset your The ARC Bistro admin password', $text);
}

function reset_lookup(string $token, ?int $now = null): ?array
{
    $now ??= time();
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $h = hash('sha256', $token);
    foreach (json_read(reset_file(), []) as $x) {
        if (!empty($x['hash']) && hash_equals((string)$x['hash'], $h) && empty($x['used']) && (int)$x['expires'] > $now) return $x;
    }
    return null;
}

/** @return array{0: bool, 1: ?string} [ok, error message] */
function reset_consume(string $token, string $newPassword, ?int $now = null): array
{
    $now ??= time();
    if (strlen($newPassword) < PASSWORD_MIN) return [false, 'Password must be at least ' . PASSWORD_MIN . ' characters'];
    $x = reset_lookup($token, $now);
    $gone = [false, 'This link is not valid any more. Please ask for a new one.'];
    if (!$x) return $gone;
    $users = users_all();
    $found = false;
    foreach ($users as &$u) {
        if ($u['id'] === $x['uid']) { $u['hash'] = pw_hash($newPassword); $found = true; }
    }
    unset($u);
    if (!$found) return $gone;
    users_save($users);
    $all = json_read(reset_file(), []);
    foreach ($all as &$r) { if (($r['uid'] ?? null) === $x['uid']) $r['used'] = true; }
    unset($r);
    json_write_atomic(reset_file(), $all);
    log_line('activity', 'password reset for ' . $x['uid']);
    return [true, null];
}
