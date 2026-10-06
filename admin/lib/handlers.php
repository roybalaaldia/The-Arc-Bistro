<?php
declare(strict_types=1);

function api_ok(array $body = [], int $status = 200): array { return [$status, ['ok' => true] + $body]; }
function api_err(string $msg, int $status, array $extra = []): array { return [$status, ['ok' => false, 'error' => $msg] + $extra]; }

function handle_content_get(array $user, array $in): array
{
    $c = store_read();
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
