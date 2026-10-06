<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
auth_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid((string)($_POST['csrf'] ?? ''))) auth_end();
header('Location: index.php');
