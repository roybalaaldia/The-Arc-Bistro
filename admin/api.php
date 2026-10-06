<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

auth_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $user = auth_current();
    if (!$user) respond(401, ['ok' => false, 'error' => 'Please log in again.']);

    $routes = [
        'content.get'  => ['GET',  'handle_content_get'],
        'content.save' => ['POST', 'handle_content_save'],
    ];
    $action = (string)($_GET['action'] ?? '');
    if (!isset($routes[$action])) respond(404, ['ok' => false, 'error' => 'Unknown action']);
    [$method, $fn] = $routes[$action];
    if ($_SERVER['REQUEST_METHOD'] !== $method) respond(405, ['ok' => false, 'error' => 'Wrong request method']);
    if ($method === 'POST' && !csrf_valid((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        respond(403, ['ok' => false, 'error' => 'Your session expired. Reload the page.']);
    }
    if ($action === 'upload') {
        $f = $_FILES['photo'] ?? [];
        $tmp = (string)($f['tmp_name'] ?? '');
        $in = ['tmp' => ($tmp !== '' && is_uploaded_file($tmp)) ? $tmp : '', 'err' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE)];
    } else {
        $in = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : $_GET;
        if (!is_array($in)) respond(400, ['ok' => false, 'error' => 'Bad request body']);
    }
    [$status, $body] = $fn($user, $in);
    respond($status, $body);
} catch (Throwable $e) {
    log_line('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    respond(500, ['ok' => false, 'error' => 'Something went wrong. Nothing was saved. Please try again.']);
}
