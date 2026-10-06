<?php
declare(strict_types=1);

function mail_send(string $to, string $subject, string $text): bool
{
    if (isset($GLOBALS['ARC_MAILER']) && is_callable($GLOBALS['ARC_MAILER'])) return (bool)($GLOBALS['ARC_MAILER'])($to, $subject, $text);

    $cfg = json_read(arc_cfg('data') . '/smtp.json', []);
    if (!$cfg || empty($cfg['host']) || empty($cfg['from'])) {
        // Not configured yet (for example on a local test machine): keep the message in the protected log instead.
        log_line('error', 'SMTP not configured; mail to ' . $to . ' was not sent');
        log_line('mail', "TO: $to | SUBJECT: $subject | $text");
        return false;
    }
    $dir = __DIR__ . '/vendor/PHPMailer';
    require_once $dir . '/Exception.php';
    require_once $dir . '/PHPMailer.php';
    require_once $dir . '/SMTP.php';
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string)$cfg['host'];
        $m->SMTPAuth = true;
        $m->Username = (string)($cfg['user'] ?? '');
        $m->Password = (string)($cfg['pass'] ?? '');
        $port = (int)($cfg['port'] ?? 465);
        $m->Port = $port;
        $m->SMTPSecure = $port === 587 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet = 'UTF-8';
        $m->setFrom((string)$cfg['from'], 'The ARC Bistro');
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->Body = $text;
        $m->send();
        return true;
    } catch (Throwable $e) {
        log_line('error', 'mail failed: ' . $e->getMessage());
        return false;
    }
}
