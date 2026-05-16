<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lead_service.php';
require_once __DIR__ . '/../core/db.php';

require_role(['superadmin', 'agent']);

if (!is_post()) {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$leadId = (int) ($_POST['lead_id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));

if ($leadId <= 0 || !in_array($status, lead_statuses(), true)) {
    json_response(['success' => false, 'message' => 'Invalid data'], 422);
}

$user = current_user();
if ($user['role'] === 'agent') {
    $stmt = db()->prepare('SELECT assigned_agent_id FROM leads WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $leadId]);
    $assignedAgentId = (int) ($stmt->fetchColumn() ?: 0);
    if ($assignedAgentId !== (int) $user['id']) {
        json_response(['success' => false, 'message' => 'Not allowed'], 403);
    }
}

update_lead_status($leadId, $status, (int) $user['id'], $note === '' ? null : $note);
json_response(['success' => true, 'message' => 'Status updated']);
