<?php
declare(strict_types=1);

const ROLES = ['owner', 'staff', 'developer'];
const STAFF_SECTIONS = ['featured', 'menu', 'promos'];
const SESSION_IDLE = 1800;
const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 600;
const PASSWORD_MIN = 10;

function users_all(): array { return json_read(arc_cfg('data') . '/users.json', []); }
function users_save(array $u): void { json_write_atomic(arc_cfg('data') . '/users.json', array_values($u)); }

function user_by(string $field, string $value): ?array
{
    if ($value === '') return null; // never match records that merely lack the field
    foreach (users_all() as $u) {
        if (strcasecmp((string)($u[$field] ?? ''), $value) === 0) return $u;
    }
    return null;
}

function user_create(string $username, string $email, string $password, string $role): array
{
    $username = strtolower(trim($username));
    $email = trim($email);
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) throw new InvalidArgumentException('Username must be 3 to 30 letters, numbers, dot, dash or underscore');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address');
    if (strlen($password) < PASSWORD_MIN) throw new InvalidArgumentException('Password must be at least ' . PASSWORD_MIN . ' characters');
    if (!in_array($role, ROLES, true)) throw new InvalidArgumentException('Unknown role');
    if (user_by('username', $username)) throw new InvalidArgumentException('That username is already taken');
    if (user_by('email', $email)) throw new InvalidArgumentException('That email is already used by another account');
    $u = [
        'id' => 'u' . bin2hex(random_bytes(4)), 'username' => $username, 'email' => $email, 'role' => $role,
        'hash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'),
    ];
    $all = users_all();
    $all[] = $u;
    users_save($all);
    return $u;
}

function dummy_hash(): string
{
    static $h = null;
    return $h ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
}

function attempts_key(string $ip, string $username): string { return hash('sha256', $ip . '|' . strtolower(trim($username))); }

function lock_remaining(string $key, int $now): int
{
    $a = json_read(arc_cfg('data') . '/attempts.json', []);
    return max(0, (int)($a[$key]['until'] ?? 0) - $now);
}

function attempt_fail(string $key, int $now): void
{
    $f = arc_cfg('data') . '/attempts.json';
    $a = json_read($f, []);
    $row = $a[$key] ?? ['count' => 0, 'until' => 0];
    if ((int)$row['until'] > 0 && (int)$row['until'] <= $now) $row = ['count' => 0, 'until' => 0];
    $row['count']++;
    if ($row['count'] >= MAX_ATTEMPTS) { $row['until'] = $now + LOCK_SECONDS; $row['count'] = 0; }
    $a[$key] = $row;
    json_write_atomic($f, $a);
}

function attempt_clear(string $key): void
{
    $f = arc_cfg('data') . '/attempts.json';
    $a = json_read($f, []);
    unset($a[$key]);
    json_write_atomic($f, $a);
}

function auth_login(string $username, string $password, string $ip, ?int $now = null): array
{
    $now ??= time();
    $key = attempts_key($ip, $username);
    $wait = lock_remaining($key, $now);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . max(1, (int)ceil($wait / 60)) . ' minute(s).', 'user' => null];
    }
    $u = user_by('username', strtolower(trim($username)));
    $good = password_verify($password, $u['hash'] ?? dummy_hash()) && $u !== null;
    if (!$good) {
        attempt_fail($key, $now);
        log_line('activity', 'failed login ' . substr(hash('sha256', strtolower($username)), 0, 8));
        return ['ok' => false, 'error' => 'Wrong username or password.', 'user' => null];
    }
    attempt_clear($key);
    return ['ok' => true, 'error' => null, 'user' => $u];
}

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('arcadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function auth_begin(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION = ['uid' => $user['id'], 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
}

function auth_current(?int $now = null): ?array
{
    $now ??= time();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    if ($now - (int)($_SESSION['seen'] ?? 0) > SESSION_IDLE) { $_SESSION = []; return null; }
    $u = user_by('id', (string)$uid);
    if (!$u) { $_SESSION = []; return null; }
    $_SESSION['seen'] = $now;
    return $u;
}

function auth_end(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); }
function csrf_valid(string $t): bool { $c = csrf_token(); return $c !== '' && hash_equals($c, $t); }

function can_edit_section(string $role, string $section): bool
{
    if ($role === 'staff') return in_array($section, STAFF_SECTIONS, true);
    return in_array($role, ['owner', 'developer'], true) && in_array($section, SECTIONS, true);
}

function can(string $role, string $cap): bool
{
    return match ($cap) {
        'content' => in_array($role, ROLES, true),
        'accounts', 'history' => in_array($role, ['owner', 'developer'], true),
        'system' => $role === 'developer',
        default => false,
    };
}

function users_visible_to(string $role): array
{
    $all = users_all();
    return $role === 'developer' ? $all : array_values(array_filter($all, fn($u) => $u['role'] !== 'developer'));
}
