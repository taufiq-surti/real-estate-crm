<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$mailerAvailable = false;
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    $mailerAvailable = true;
} else {
    $phpMailerSrc = __DIR__ . '/../lib/PHPMailer/src/';
    if (file_exists($phpMailerSrc . 'PHPMailer.php')) {
        require_once $phpMailerSrc . 'Exception.php';
        require_once $phpMailerSrc . 'PHPMailer.php';
        require_once $phpMailerSrc . 'SMTP.php';
        $mailerAvailable = true;
    }
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function send_lead_notification_email(array $lead, array $project, ?array $agent = null): array
{
    global $mailerAvailable;

    if (!$mailerAvailable) {
        return ['success' => false, 'message' => 'PHPMailer not installed'];
    }

    $mail = new PHPMailer(true);
    $smtp = config('smtp');

    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['secure'];

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress((string) config('contact.admin_email'));
        $mail->isHTML(true);
        $mail->Subject = 'New Real Estate Lead - ' . ($project['name'] ?? 'Project');

        $agentText = $agent ? $agent['name'] . ' (' . $agent['email'] . ')' : 'Unassigned';

        $mail->Body = '
            <h3>New Real Estate Lead Received</h3>
            <table cellpadding="8" border="1" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;">
                <tr><td><strong>Date</strong></td><td>' . e((string) date('Y-m-d H:i:s')) . '</td></tr>
                <tr><td><strong>Project</strong></td><td>' . e($project['name'] ?? '') . '</td></tr>
                <tr><td><strong>Name</strong></td><td>' . e($lead['name'] ?? '') . '</td></tr>
                <tr><td><strong>Mobile</strong></td><td>' . e($lead['mobile'] ?? '') . '</td></tr>
                <tr><td><strong>Email</strong></td><td>' . e($lead['email'] ?? '') . '</td></tr>
                <tr><td><strong>City</strong></td><td>' . e($lead['city'] ?? '') . '</td></tr>
                <tr><td><strong>Message</strong></td><td>' . nl2br(e($lead['message'] ?? '')) . '</td></tr>
                <tr><td><strong>Assigned Agent</strong></td><td>' . e($agentText) . '</td></tr>
                <tr><td><strong>Status</strong></td><td>' . e($lead['status'] ?? 'New') . '</td></tr>
            </table>
        ';

        $mail->send();
        return ['success' => true, 'message' => 'Email sent'];
    } catch (Exception $ex) {
        return ['success' => false, 'message' => $ex->getMessage()];
    }
}
