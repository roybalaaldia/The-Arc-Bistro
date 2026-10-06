<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
$lock = arc_cfg('data') . '/setup.lock';
if (is_file($lock)) { header('Location: index.php'); exit; }
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));

$keyFile = arc_cfg('data') . '/setup.key';
$expected = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
$error = '';
if ($expected !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = $_POST;
    try {
        if (!csrf_valid((string)($p['csrf'] ?? ''))) throw new InvalidArgumentException('Please try again.');
        if (!hash_equals($expected, trim((string)($p['key'] ?? '')))) throw new InvalidArgumentException('The setup key is not correct.');
        if (($p['dev_password'] ?? '') !== ($p['dev_password2'] ?? '') || ($p['own_password'] ?? '') !== ($p['own_password2'] ?? '')) {
            throw new InvalidArgumentException('The two passwords must match.');
        }
        user_create((string)($p['dev_username'] ?? ''), (string)($p['dev_email'] ?? ''), (string)($p['dev_password'] ?? ''), 'developer');
        try {
            user_create((string)($p['own_username'] ?? ''), (string)($p['own_email'] ?? ''), (string)($p['own_password'] ?? ''), 'owner');
        } catch (InvalidArgumentException $ex) {
            users_save([]); // setup is all-or-nothing: do not leave a half-created developer behind
            throw $ex;
        }
        file_put_contents($lock, date('c'));
        @unlink($keyFile);
        header('Location: index.php'); exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}
page_head('Set up', 'class="auth"');
?>
<main class="auth__box auth__box--wide">
  <h1>First-time setup</h1>
  <?php if ($expected === ''): ?>
    <p class="error">Setup is not unlocked. Ask your developer to create the file <code>admin/data/setup.key</code> containing a secret word, then reload this page.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span class="field__label">Setup key</span><input name="key" type="password" required></label>
      <h2>Developer account</h2>
      <label class="field"><span class="field__label">Username</span><input name="dev_username" required></label>
      <label class="field"><span class="field__label">Email</span><input name="dev_email" type="email" required></label>
      <label class="field"><span class="field__label">Password (10+ characters)</span><input name="dev_password" type="password" minlength="10" required></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="dev_password2" type="password" minlength="10" required></label>
      <h2>Owner account</h2>
      <label class="field"><span class="field__label">Username</span><input name="own_username" required></label>
      <label class="field"><span class="field__label">Email</span><input name="own_email" type="email" required></label>
      <label class="field"><span class="field__label">Password (10+ characters)</span><input name="own_password" type="password" minlength="10" required></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="own_password2" type="password" minlength="10" required></label>
      <button class="btn primary" type="submit">Create accounts</button>
    </form>
  <?php endif; ?>
</main>
<?php page_foot();
