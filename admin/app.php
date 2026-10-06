<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
$user = auth_current();
if (!$user) { header('Location: index.php'); exit; }

$tabs = [['menu', 'Menu'], ['promos', 'Promos']];
if ($user['role'] !== 'staff') $tabs[] = ['hours', 'Hours & Contact'];
if (can($user['role'], 'history')) $tabs[] = ['history', 'History'];
if (can($user['role'], 'accounts')) $tabs[] = ['accounts', 'Accounts'];
if (can($user['role'], 'system')) $tabs[] = ['system', 'System'];

page_head('Dashboard', 'data-csrf="' . e(csrf_token()) . '" data-role="' . e($user['role']) . '" data-user="' . e($user['username']) . '" data-tabs="' . e(json_encode($tabs)) . '"');
?>
<header class="top">
  <strong>The ARC Bistro admin</strong>
  <span class="top__spacer"></span>
  <a class="btn" href="../" target="_blank" rel="noopener">View site</a>
  <span class="muted"><?= e($user['username']) ?> (<?= e($user['role']) ?>)</span>
  <form method="post" action="logout.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn" type="submit">Log out</button>
  </form>
</header>
<div class="layout">
  <nav id="nav" aria-label="Sections"></nav>
  <main id="main"><p class="muted">Loading…</p></main>
</div>
<div id="toast" role="status" aria-live="polite"></div>
<?php page_foot(
    '<script src="assets/app.js"></script>'
    . '<script src="assets/tab-menu.js"></script><script src="assets/tab-promos.js"></script><script src="assets/tab-hours.js"></script>'
    . '<script src="assets/tab-history.js"></script><script src="assets/tab-accounts.js"></script><script src="assets/tab-system.js"></script>'
);
