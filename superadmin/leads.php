<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/lead_service.php';

require_role('superadmin');

if (is_post()) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $leadId = (int) ($_POST['lead_id'] ?? 0);

    if ($leadId > 0 && $action === 'assign') {
        $agentIdRaw = $_POST['agent_id'] ?? null;
        $agentId = $agentIdRaw === '' || $agentIdRaw === null ? null : (int) $agentIdRaw;
        assign_lead($leadId, $agentId, (int) current_user()['id']);
    }

    if ($leadId > 0 && $action === 'status') {
        $status = trim((string) ($_POST['status'] ?? ''));
        if (in_array($status, lead_statuses(), true)) {
            update_lead_status($leadId, $status, (int) current_user()['id']);
        }
    }

    redirect('superadmin/leads.php?' . http_build_query($_GET));
}

$projectFilter = (int) ($_GET['project_id'] ?? 0);
$agentFilter = (int) ($_GET['agent_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($projectFilter > 0) {
    $where[] = 'l.project_id = :project_id';
    $params['project_id'] = $projectFilter;
}
if ($agentFilter > 0) {
    $where[] = 'l.assigned_agent_id = :agent_id';
    $params['agent_id'] = $agentFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, lead_statuses(), true)) {
    $where[] = 'l.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(l.name LIKE :search OR l.mobile LIKE :search OR l.email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM leads l {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));

$listStmt = db()->prepare(
    "SELECT l.*, p.name AS project_name, u.name AS agent_name
     FROM leads l
     JOIN projects p ON p.id = l.project_id
     LEFT JOIN users u ON u.id = l.assigned_agent_id
     {$whereSql}
     ORDER BY l.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$listStmt->execute($params);
$leads = $listStmt->fetchAll();

$projects = db()->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
$agents = db()->query("SELECT id, name FROM users WHERE role = 'agent' AND is_active = 1 ORDER BY name")->fetchAll();

panel_header('Lead Management');
?>
<div class="bg-white border rounded-xl p-4">
    <form class="grid md:grid-cols-5 gap-3" method="get">
        <input name="search" value="<?= e($search) ?>" placeholder="Search name/mobile/email" class="border rounded px-3 py-2">
        <select name="project_id" class="border rounded px-3 py-2">
            <option value="0">All Projects</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>" <?= $projectFilter === (int) $project['id'] ? 'selected' : '' ?>><?= e($project['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="agent_id" class="border rounded px-3 py-2">
            <option value="0">All Agents</option>
            <?php foreach ($agents as $agent): ?>
                <option value="<?= (int) $agent['id'] ?>" <?= $agentFilter === (int) $agent['id'] ? 'selected' : '' ?>><?= e($agent['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="border rounded px-3 py-2">
            <option value="">All Status</option>
            <?php foreach (lead_statuses() as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="bg-slate-900 text-white rounded px-3 py-2">Filter</button>
    </form>
</div>

<div class="mt-4 bg-white border rounded-xl overflow-auto">
    <table class="w-full text-sm min-w-[1100px]">
        <thead class="bg-slate-100">
            <tr>
                <th class="px-3 py-2 text-left">Lead</th>
                <th class="px-3 py-2 text-left">Project</th>
                <th class="px-3 py-2 text-left">Agent</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Message</th>
                <th class="px-3 py-2 text-left">Created</th>
                <th class="px-3 py-2 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($leads as $lead): ?>
                <tr class="border-t align-top">
                    <td class="px-3 py-2">
                        <div class="font-semibold"><?= e($lead['name']) ?></div>
                        <div><?= e($lead['mobile']) ?></div>
                        <div class="text-slate-500"><?= e($lead['email']) ?></div>
                        <div class="text-slate-500"><?= e($lead['city']) ?></div>
                    </td>
                    <td class="px-3 py-2"><?= e($lead['project_name']) ?></td>
                    <td class="px-3 py-2">
                        <form method="post" class="flex items-center gap-2">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                            <select name="agent_id" onchange="this.form.submit()" class="border rounded px-2 py-1">
                                <option value="">Unassigned</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?= (int) $agent['id'] ?>" <?= (int) $lead['assigned_agent_id'] === (int) $agent['id'] ? 'selected' : '' ?>><?= e($agent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-1 text-xs rounded <?= e(status_badge_class($lead['status'])) ?>"><?= e($lead['status']) ?></span>
                        <form method="post" class="mt-2 flex items-center gap-2">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                            <select name="status" class="border rounded px-2 py-1 text-xs" onchange="this.form.submit()">
                                <?php foreach (lead_statuses() as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $status === $lead['status'] ? 'selected' : '' ?>><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="px-3 py-2 max-w-[260px]"><?= e($lead['message']) ?></td>
                    <td class="px-3 py-2 whitespace-nowrap"><?= e($lead['created_at']) ?></td>
                    <td class="px-3 py-2">
                        <a class="text-green-700 font-semibold" target="_blank" href="<?= e(whatsapp_link('91' . preg_replace('/\D+/', '', $lead['mobile']), 'Hello ' . $lead['name'] . ', thank you for your enquiry.')) ?>">WhatsApp</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$leads): ?>
                <tr><td colspan="7" class="px-3 py-5 text-center text-slate-500">No leads found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="mt-4 flex items-center gap-2">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="px-3 py-1 rounded border <?= $p === $page ? 'bg-slate-900 text-white' : 'bg-white' ?>" href="<?= e(base_url('superadmin/leads.php?' . http_build_query(array_merge($_GET, ['page' => $p])))) ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php panel_footer();
