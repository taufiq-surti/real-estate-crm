<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/lead_service.php';

require_role('agent');
$userId = (int) current_user()['id'];

if (is_post()) {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $status = trim((string) ($_POST['status'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($leadId > 0 && in_array($status, lead_statuses(), true)) {
        $check = db()->prepare('SELECT assigned_agent_id FROM leads WHERE id = :id LIMIT 1');
        $check->execute(['id' => $leadId]);
        $assignedAgentId = (int) ($check->fetchColumn() ?: 0);

        if ($assignedAgentId === $userId) {
            update_lead_status($leadId, $status, $userId, $note === '' ? null : $note);
        }
    }

    redirect('agent/leads.php?' . http_build_query($_GET));
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = ['l.assigned_agent_id = :agent_id'];
$params = ['agent_id' => $userId];

if ($statusFilter !== '' && in_array($statusFilter, lead_statuses(), true)) {
    $where[] = 'l.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(l.name LIKE :search OR l.mobile LIKE :search OR l.email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM leads l {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));

$stmt = db()->prepare(
    "SELECT l.*, p.name AS project_name
     FROM leads l
     JOIN projects p ON p.id = l.project_id
     {$whereSql}
     ORDER BY l.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$leads = $stmt->fetchAll();

panel_header('My Leads');
?>
<div class="bg-white border rounded-xl p-4">
    <form class="grid md:grid-cols-3 gap-3" method="get">
        <input name="search" value="<?= e($search) ?>" placeholder="Search name/mobile/email" class="border rounded px-3 py-2">
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
    <table class="w-full text-sm min-w-[900px]">
        <thead class="bg-slate-100">
            <tr>
                <th class="px-3 py-2 text-left">Lead</th>
                <th class="px-3 py-2 text-left">Project</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Message</th>
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
                        <span class="px-2 py-1 text-xs rounded <?= e(status_badge_class($lead['status'])) ?>"><?= e($lead['status']) ?></span>
                        <form method="post" class="mt-2 space-y-2">
                            <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                            <select name="status" class="border rounded px-2 py-1 text-xs w-full">
                                <?php foreach (lead_statuses() as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $status === $lead['status'] ? 'selected' : '' ?>><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="note" placeholder="Follow-up note" class="border rounded px-2 py-1 text-xs w-full">
                            <button class="bg-cyan-600 text-white text-xs px-3 py-1 rounded">Update</button>
                        </form>
                    </td>
                    <td class="px-3 py-2 max-w-[250px] text-slate-700"><?= e($lead['message']) ?></td>
                    <td class="px-3 py-2">
                        <a target="_blank" class="text-green-700 font-semibold" href="<?= e(whatsapp_link('91' . preg_replace('/\D+/', '', $lead['mobile']), 'Hello ' . $lead['name'] . ', this is your sales advisor from Paradise Group.')) ?>">WhatsApp</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$leads): ?>
                <tr><td colspan="5" class="px-3 py-5 text-center text-slate-500">No assigned leads found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="mt-4 flex items-center gap-2">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="px-3 py-1 rounded border <?= $p === $page ? 'bg-slate-900 text-white' : 'bg-white' ?>" href="<?= e(base_url('agent/leads.php?' . http_build_query(array_merge($_GET, ['page' => $p])))) ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php panel_footer();
