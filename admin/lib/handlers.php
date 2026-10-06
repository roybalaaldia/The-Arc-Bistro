<?php
declare(strict_types=1);

function api_ok(array $body = [], int $status = 200): array { return [$status, ['ok' => true] + $body]; }
function api_err(string $msg, int $status, array $extra = []): array { return [$status, ['ok' => false, 'error' => $msg] + $extra]; }

function handle_content_get(array $user, array $in): array
{
    try {
        $c = store_read();
    } catch (RuntimeException $e) {
        return api_err('The website content file could not be read. Ask the developer, or restore a previous version from the History tab.', 500, ['contentOk' => false]);
    }
    if ($user['role'] === 'staff') {
        $c = array_intersect_key($c, array_flip(['revision', 'updatedAt', 'featured', 'menu', 'promos']));
    }
    return api_ok(['content' => $c]);
}

function handle_content_save(array $user, array $in): array
{
    $section = (string)($in['section'] ?? '');
    if (!in_array($section, SECTIONS, true)) return api_err('Unknown section', 400);
    if (!isset($in['baseRevision']) || !is_numeric($in['baseRevision'])) return api_err('Missing revision. Reload the page and try again.', 400);
    if (!can_edit_section($user['role'], $section)) {
        log_line('activity', "{$user['username']} was blocked from saving $section");
        return api_err('You do not have permission to change this.', 403);
    }
    [$clean, $errors] = validate_section($section, $in['data'] ?? null);
    if ($errors) return api_err('Please fix the problems below.', 422, ['errors' => $errors]);
    try {
        $rev = store_update(
            function (array $cur) use ($section, $clean) { $cur[$section] = $clean; return $cur; },
            (int)$in['baseRevision'],
            $user['username']
        );
    } catch (ConflictException $e) {
        return api_err($e->getMessage(), 409);
    } catch (RuntimeException $e) {
        log_line('error', 'content save failed: ' . $e->getMessage());
        return api_err('The website content could not be saved. Ask the developer, or restore a previous version from the History tab.', 500);
    }
    return api_ok(['revision' => $rev, 'data' => $clean]);
}

function handle_upload(array $user, array $in): array
{
    if ((int)($in['err'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return api_err('The upload did not finish. Please try again.', 400);
    try {
        $path = images_process((string)($in['tmp'] ?? ''));
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    log_line('activity', "{$user['username']} uploaded $path");
    return api_ok(['path' => $path]);
}

function deny(): array { return api_err('You do not have permission to do this.', 403); }

function acct_public(array $u, string $selfId): array
{
    return ['id' => $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'self' => $u['id'] === $selfId];
}

function visible_target(array $user, string $id): ?array
{
    $t = user_by('id', $id);
    return ($t && in_array($t['id'], array_column(users_visible_to($user['role']), 'id'), true)) ? $t : null;
}

function handle_accounts_list(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    return api_ok(['users' => array_map(fn($u) => acct_public($u, $user['id']), users_visible_to($user['role']))]);
}

function handle_accounts_create(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $role = (string)($in['role'] ?? 'staff');
    if ($user['role'] === 'owner' && $role !== 'staff') return api_err('Owners can only add staff accounts.', 403);
    try {
        $u = user_create((string)($in['username'] ?? ''), (string)($in['email'] ?? ''), (string)($in['password'] ?? ''), $role);
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    log_line('activity', "{$user['username']} created {$u['role']} account {$u['username']}");
    return api_ok(['id' => $u['id']]);
}

function handle_accounts_delete(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $t = visible_target($user, (string)($in['id'] ?? ''));
    if (!$t) return api_err('Account not found.', 404);
    if ($t['id'] === $user['id']) return api_err('You cannot delete your own account.', 422);
    if ($user['role'] === 'owner' && $t['role'] !== 'staff') return api_err('Owners can only remove staff accounts.', 403);
    users_save(array_filter(users_all(), fn($u) => $u['id'] !== $t['id']));
    log_line('activity', "{$user['username']} deleted account {$t['username']}");
    return api_ok();
}

function handle_accounts_send_reset(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $t = visible_target($user, (string)($in['id'] ?? ''));
    if (!$t) return api_err('Account not found.', 404);
    if ($user['role'] === 'owner' && $t['role'] !== 'staff') return api_err('Owners can only send reset links to staff accounts.', 403);
    reset_request($t['email'], 'admin:' . $user['id']);
    return api_ok(['message' => 'If the email is set up correctly, a reset link is on its way to ' . $t['email'] . '.']);
}

function handle_account_password(array $user, array $in, ?int $now = null): array
{
    $now ??= time();
    $key = attempts_key('pw', $user['id']);
    $wait = attempt_reserve($key, $now);
    if ($wait > 0) return api_err(lock_message($wait), 429);
    $cur = user_by('id', $user['id']);
    if (!$cur || !password_verify((string)($in['current'] ?? ''), $cur['hash'])) {
        log_line('activity', "{$user['username']} failed a password change check");
        return api_err('Your current password is not correct.', 422);
    }
    attempt_clear($key);
    $new = (string)($in['new'] ?? '');
    if (strlen($new) < PASSWORD_MIN) return api_err('The new password must be at least ' . PASSWORD_MIN . ' characters.', 422);
    $users = users_all();
    foreach ($users as &$u) { if ($u['id'] === $user['id']) $u['hash'] = pw_hash($new); }
    unset($u);
    users_save($users);
    log_line('activity', "{$user['username']} changed their password");
    return api_ok();
}

function handle_history_list(array $user, array $in): array
{
    if (!can($user['role'], 'history')) return deny();
    try {
        $rev = (int)(store_read()['revision'] ?? 0);
        $ok = true;
    } catch (RuntimeException $e) {
        $rev = 0;
        $ok = false;
    }
    return api_ok(['versions' => store_versions(), 'revision' => $rev, 'contentOk' => $ok]);
}

function handle_history_restore(array $user, array $in): array
{
    if (!can($user['role'], 'history')) return deny();
    try {
        $rev = store_restore((string)($in['name'] ?? ''), $user['username']);
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    return api_ok(['revision' => $rev]);
}

function handle_system_logs(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $kind = ($in['kind'] ?? 'activity') === 'error' ? 'error' : 'activity';
    $f = arc_cfg('data') . "/log/$kind.log";
    $lines = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -200) : [];
    return api_ok(['lines' => $lines]);
}

function handle_system_smtp_get(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $c = json_read(arc_cfg('data') . '/smtp.json', []);
    return api_ok([
        'host' => (string)($c['host'] ?? ''), 'port' => (int)($c['port'] ?? 465), 'user' => (string)($c['user'] ?? ''),
        'from' => (string)($c['from'] ?? ''), 'baseUrl' => (string)($c['baseUrl'] ?? ''), 'passSet' => !empty($c['pass']),
    ]);
}

function handle_system_smtp_save(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $old = json_read(arc_cfg('data') . '/smtp.json', []);
    $host = trim((string)($in['host'] ?? ''));
    $port = filter_var($in['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $from = trim((string)($in['from'] ?? ''));
    $base = rtrim(trim((string)($in['baseUrl'] ?? '')), '/');
    $errs = [];
    if (!preg_match('/^[A-Za-z0-9.-]{3,100}$/', $host)) $errs[] = 'Mail server name looks wrong.';
    if ($port === false) $errs[] = 'Port must be a number such as 465 or 587.';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $errs[] = 'The "from" address must be a valid email.';
    if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', $base)) $errs[] = 'Website address must look like https://yourdomain.com (https, no path).';
    if ($errs) return api_err('Please fix the problems below.', 422, ['errors' => $errs]);
    $pass = (string)($in['pass'] ?? '');
    json_write_atomic(arc_cfg('data') . '/smtp.json', [
        'host' => $host, 'port' => $port, 'user' => trim((string)($in['user'] ?? '')),
        'pass' => $pass !== '' ? $pass : (string)($old['pass'] ?? ''), 'from' => $from, 'baseUrl' => $base,
    ]);
    log_line('activity', "{$user['username']} changed the email settings");
    return api_ok();
}

function handle_system_images_unused(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    return api_ok(['images' => images_unused()]);
}

function handle_system_images_delete(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $name = (string)($in['name'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}\.(jpg|png|webp)$/', $name) || !in_array($name, array_column(images_unused(), 'name'), true)) {
        return api_err('That photo is still in use or does not exist.', 422);
    }
    unlink(arc_cfg('uploads') . '/' . $name);
    log_line('activity', "{$user['username']} deleted photo $name");
    return api_ok();
}
