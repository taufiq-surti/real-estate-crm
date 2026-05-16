<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/lead_service.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/google_sheets.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST'], true)) {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data) || $data === []) {
    $data = $_POST;
}

if (!rate_limit_check('lead_form_submit', (int) config('security.lead_rate_limit_seconds', 10))) {
    json_response(['success' => false, 'message' => 'Please wait before submitting another enquiry.'], 429);
}

if (!empty($data['trap'])) {
    json_response(['success' => false, 'message' => 'Spam detected'], 422);
}

$formName = trim((string) ($data['form_name'] ?? ''));
$formToken = (string) ($data['form_token'] ?? '');
if ($formName === '' || !verify_form_token($formName, $formToken)) {
    json_response(['success' => false, 'message' => 'Form session invalid, please refresh and try again.'], 422);
}

$name = trim((string) ($data['name'] ?? ''));
$mobile = trim((string) ($data['mobile'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$city = trim((string) ($data['city'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
$projectId = (int) ($data['project_id'] ?? 0);

if ($projectId <= 0 || $name === '' || $message === '' || !validate_mobile($mobile)) {
    json_response(['success' => false, 'message' => 'Please fill all required fields correctly.'], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
}

$project = get_project_by_id($projectId);
if (!$project) {
    json_response(['success' => false, 'message' => 'Invalid project selected.'], 422);
}

try {
    $leadId = create_lead([
        'project_id' => $projectId,
        'name' => $name,
        'mobile' => $mobile,
        'email' => $email,
        'city' => $city,
        'message' => $message,
    ]);

    $leadStmt = db()->prepare('SELECT * FROM leads WHERE id = :id LIMIT 1');
    $leadStmt->execute(['id' => $leadId]);
    $lead = $leadStmt->fetch();

    if (!$lead) {
        throw new RuntimeException('Lead save failed.');
    }

    $agent = null;
    if (!empty($lead['assigned_agent_id'])) {
        $agentStmt = db()->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $agentStmt->execute(['id' => (int) $lead['assigned_agent_id']]);
        $agent = $agentStmt->fetch() ?: null;
    }

    $mailStatus = send_lead_notification_email($lead, $project, $agent);

    $sheetStatus = append_lead_to_google_sheet([
        'date' => date('Y-m-d H:i:s'),
        'name' => $lead['name'],
        'mobile' => $lead['mobile'],
        'email' => $lead['email'] ?? '',
        'city' => $lead['city'] ?? '',
        'message' => $lead['message'],
        'project' => $project['name'],
        'assigned_agent' => $agent['name'] ?? 'Unassigned',
        'status' => $lead['status'],
    ]);

    if (!$mailStatus['success'] || (!$sheetStatus['success'] && (bool) config('google_sheets.enabled', false))) {
        $log = '[' . date('c') . '] Lead #' . $leadId . ' Mail: ' . $mailStatus['message'] . ' | Sheet: ' . $sheetStatus['message'] . PHP_EOL;
        file_put_contents(__DIR__ . '/../logs/integration.log', $log, FILE_APPEND);
    }

    json_response([
        'success' => true,
        'message' => 'Lead submitted successfully.',
        'lead_id' => $leadId,
    ]);
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/../logs/error.log', '[' . date('c') . '] submit_lead: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    json_response(['success' => false, 'message' => 'Unable to process enquiry right now.'], 500);
}
