<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function get_active_projects(): array
{
    $stmt = db()->query('SELECT * FROM projects WHERE status = 1 ORDER BY id DESC');
    $projects = $stmt->fetchAll();

    if (!empty($projects)) {
        return $projects;
    }

    $legacy = db()->query('SELECT * FROM projects ORDER BY id DESC');
    return $legacy->fetchAll();
}

function get_project_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE slug = :slug AND status = 1 LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $project = $stmt->fetch();

    if ($project) {
        return $project;
    }

    $legacy = db()->prepare('SELECT * FROM projects WHERE slug = :slug LIMIT 1');
    $legacy->execute(['slug' => $slug]);
    $project = $legacy->fetch();

    return $project ?: null;
}

function get_project_by_id(int $projectId): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

    return $project ?: null;
}

function get_agents(): array
{
    $stmt = db()->query("SELECT id, name FROM users WHERE role = 'agent' AND is_active = 1 ORDER BY id ASC");
    return $stmt->fetchAll();
}

function get_next_round_robin_agent_id(): ?int
{
    $agents = get_agents();
    if (!$agents) {
        return null;
    }

    $settingStmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $settingStmt->execute(['key' => 'last_assigned_agent_id']);
    $lastAssigned = (int) ($settingStmt->fetchColumn() ?: 0);

    $nextAgentId = null;
    foreach ($agents as $agent) {
        if ((int) $agent['id'] > $lastAssigned) {
            $nextAgentId = (int) $agent['id'];
            break;
        }
    }

    if ($nextAgentId === null) {
        $nextAgentId = (int) $agents[0]['id'];
    }

    $upsert = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $upsert->execute([
        'key' => 'last_assigned_agent_id',
        'value' => (string) $nextAgentId,
    ]);

    return $nextAgentId;
}

function create_lead(array $data): int
{
    $status = (string) config('crm.default_lead_status', 'New');
    $assignedAgentId = null;

    if ((bool) config('crm.auto_assign_leads', true)) {
        $assignedAgentId = get_next_round_robin_agent_id();
    }

    $stmt = db()->prepare(
        'INSERT INTO leads (project_id, name, mobile, email, city, message, status, assigned_agent_id, source, created_at, updated_at)
         VALUES (:project_id, :name, :mobile, :email, :city, :message, :status, :assigned_agent_id, :source, NOW(), NOW())'
    );

    $stmt->execute([
        'project_id' => $data['project_id'],
        'name' => trim($data['name']),
        'mobile' => trim($data['mobile']),
        'email' => trim((string) ($data['email'] ?? '')),
        'city' => trim((string) ($data['city'] ?? '')),
        'message' => trim($data['message']),
        'status' => $status,
        'assigned_agent_id' => $assignedAgentId,
        'source' => 'Website',
    ]);

    return (int) db()->lastInsertId();
}

function assign_lead(int $leadId, ?int $agentId, int $byUserId): void
{
    $stmt = db()->prepare('UPDATE leads SET assigned_agent_id = :agent_id, updated_at = NOW() WHERE id = :lead_id');
    $stmt->execute([
        'agent_id' => $agentId,
        'lead_id' => $leadId,
    ]);

    $note = $agentId ? 'Lead assigned to agent ID ' . $agentId : 'Lead unassigned';
    $log = db()->prepare('INSERT INTO lead_logs (lead_id, user_id, note, status, created_at) VALUES (:lead_id, :user_id, :note, NULL, NOW())');
    $log->execute([
        'lead_id' => $leadId,
        'user_id' => $byUserId,
        'note' => $note,
    ]);
}

function update_lead_status(int $leadId, string $status, int $userId, ?string $note = null): void
{
    $stmt = db()->prepare('UPDATE leads SET status = :status, updated_at = NOW() WHERE id = :lead_id');
    $stmt->execute([
        'status' => $status,
        'lead_id' => $leadId,
    ]);

    $log = db()->prepare('INSERT INTO lead_logs (lead_id, user_id, note, status, created_at) VALUES (:lead_id, :user_id, :note, :status, NOW())');
    $log->execute([
        'lead_id' => $leadId,
        'user_id' => $userId,
        'note' => $note,
        'status' => $status,
    ]);
}
