<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (!is_file(arc_cfg('data') . '/setup.lock')) { header('Location: setup.php'); exit; }
if (auth_current()) { header('Location: app.php'); exit; }
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));

$error = '';
$notice = isset($_GET['expired']) ? 'You were signed out. Please log in again.' : (isset($_GET['reset']) ? 'Your password was changed. Please log in.' : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Please try again.';
    } else {
        $r = auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '0');
        if ($r['ok']) { auth_begin($r['user']); header('Location: app.php'); exit; }
        $error = $r['error'];
    }
}
page_head('Log in', 'class="auth"');
?>
<main class="auth__box">
  <h1>The ARC Bistro</h1>
  <p class="muted">Website admin</p>
  <?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="field"><span class="field__label">Username</span><input name="username" type="text" autocomplete="username" required autofocus></label>
    <label class="field"><span class="field__label">Password</span><input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn primary" type="submit">Log in</button>
  </form>
  <p><a href="forgot.php">Forgot your password?</a></p>
</main>
<?php page_foot();
