<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valid = reset_lookup($token) !== null;
$error = '';
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Please try again.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'The two passwords must match.';
    } else {
        [$ok, $msg] = reset_consume($token, (string)$_POST['password']);
        if ($ok) { header('Location: index.php?reset=1'); exit; }
        $error = (string)$msg;
        $valid = reset_lookup($token) !== null;
    }
}
page_head('Choose a new password', 'class="auth"');
?>
<main class="auth__box">
  <h1>Choose a new password</h1>
  <?php if (!$valid): ?>
    <p class="error">This link is not valid any more. <a href="forgot.php">Ask for a new one</a>.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label class="field"><span class="field__label">New password (10+ characters)</span><input name="password" type="password" minlength="10" required autocomplete="new-password" autofocus></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="password2" type="password" minlength="10" required autocomplete="new-password"></label>
      <button class="btn primary" type="submit">Save new password</button>
    </form>
  <?php endif; ?>
</main>
<?php page_foot();
