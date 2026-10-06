<?php
declare(strict_types=1);

const ROLES = ['owner', 'staff', 'developer'];
const STAFF_SECTIONS = ['featured', 'menu', 'promos'];
const SESSION_IDLE = 1800;
const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 600;
const PASSWORD_MIN = 10;
const PASSWORD_COST = 10; // explicit: must equal DUMMY_HASH's cost on every PHP version
const ATTEMPT_STALE = 3600;

function pw_hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]); }

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
        'hash' => pw_hash($password), 'createdAt' => date('c'),
    ];
    $all = users_all();
    $all[] = $u;
    users_save($all);
    return $u;
}

// Precomputed so unknown usernames cost the same as real ones (no per-request hashing).
const DUMMY_HASH = '$2y$10$VmqVq25y1uQtLDyIOOCUkOf.ghh3PFyG6OAkcYKZtqB/o3hQCRnvO';

function attempts_key(string $ip, string $username): string { return hash('sha256', $ip . '|' . strtolower(trim($username))); }

/** Drops rows whose lock expired or whose last attempt is over an hour old, so attempts.json cannot grow forever. */
function attempts_prune(array $a, int $now): array
{
    return array_filter($a, function ($r) use ($now) {
        $until = (int)($r['until'] ?? 0);
        return $until > 0 ? $until > $now : (int)($r['at'] ?? 0) > $now - ATTEMPT_STALE;
    });
}

/**
 * Reserve-before-verify: under a file lock, report an active lock (seconds left) or record this attempt
 * as a failure right away (locking at MAX_ATTEMPTS). Returns 0 when the caller may go on to verify.
 * attempt_clear() undoes the reservation after a correct password.
 */
function attempt_reserve(string $key, int $now): int
{
    return with_file_lock('attempts', function () use ($key, $now) {
        $f = arc_cfg('data') . '/attempts.json';
        $a = attempts_prune(json_read($f, []), $now);
        $wait = max(0, (int)($a[$key]['until'] ?? 0) - $now);
        if ($wait === 0) {
            $row = $a[$key] ?? ['count' => 0, 'until' => 0];
            $row['count']++;
            if ($row['count'] >= MAX_ATTEMPTS) { $row['until'] = $now + LOCK_SECONDS; $row['count'] = 0; }
            $row['at'] = $now;
            $a[$key] = $row;
        }
        json_write_atomic($f, $a);
        return $wait;
    });
}

function attempt_clear(string $key): void
{
    with_file_lock('attempts', function () use ($key) {
        $f = arc_cfg('data') . '/attempts.json';
        $a = json_read($f, []);
        unset($a[$key]);
        json_write_atomic($f, $a);
    });
}

function lock_message(int $wait): string { return 'Too many attempts. Try again in ' . max(1, (int)ceil($wait / 60)) . ' minute(s).'; }

function auth_login(string $username, string $password, string $ip, ?int $now = null): array
{
    $now ??= time();
    $key = attempts_key($ip, $username);
    $wait = attempt_reserve($key, $now);
    if ($wait > 0) return ['ok' => false, 'error' => lock_message($wait), 'user' => null];
    $u = user_by('username', strtolower(trim($username)));
    $good = password_verify($password, $u['hash'] ?? DUMMY_HASH) && $u !== null;
    if (!$good) {
        log_line('activity', 'failed login ' . substr(hash('sha256', strtolower($username)), 0, 8));
        return ['ok' => false, 'error' => 'Wrong username or password.', 'user' => null];
    }
    attempt_clear($key);
    return ['ok' => true, 'error' => null, 'user' => $u];
}

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE * 4));
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
