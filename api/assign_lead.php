<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lead_service.php';

require_role('superadmin');

if (!is_post()) {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$leadId = (int) ($_POST['lead_id'] ?? 0);
$agentIdRaw = $_POST['agent_id'] ?? null;
$agentId = $agentIdRaw === '' || $agentIdRaw === null ? null : (int) $agentIdRaw;

if ($leadId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid lead'], 422);
}

assign_lead($leadId, $agentId, (int) current_user()['id']);
json_response(['success' => true, 'message' => 'Lead assignment updated']);
