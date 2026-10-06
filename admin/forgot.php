<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));
$done = false;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid((string)($_POST['csrf'] ?? ''))) {
    $done = true;
    $email = (string)($_POST['email'] ?? '');
    ob_start(); // buffered so the full response can be closed before the mail work starts
}
page_head('Forgot password', 'class="auth"');
?>
<main class="auth__box">
  <h1>Reset your password</h1>
  <?php if ($done): ?>
    <p class="notice">If that email belongs to an account, we have sent a link. It works for 30 minutes.</p>
    <p><a href="index.php">Back to log in</a></p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span class="field__label">Your account email</span><input name="email" type="email" required autofocus></label>
      <button class="btn primary" type="submit">Send reset link</button>
    </form>
    <p><a href="index.php">Back to log in</a></p>
  <?php endif; ?>
</main>
<?php
page_foot();
if ($done) {
    session_write_close();
    // Content-Length + Connection: close lets the browser finish even on servers without fastcgi_finish_request.
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    reset_request($email, $_SERVER['REMOTE_ADDR'] ?? '0');
}
