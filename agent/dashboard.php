<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('agent');
$user = current_user();
$userId = (int) $user['id'];

$totalAssigned = (int) db()->query("SELECT COUNT(*) FROM leads WHERE assigned_agent_id = {$userId}")->fetchColumn();
$newLeads = (int) db()->query("SELECT COUNT(*) FROM leads WHERE assigned_agent_id = {$userId} AND status = 'New'")->fetchColumn();
$followUps = (int) db()->query("SELECT COUNT(*) FROM leads WHERE assigned_agent_id = {$userId} AND status = 'Follow-up'")->fetchColumn();
$closed = (int) db()->query("SELECT COUNT(*) FROM leads WHERE assigned_agent_id = {$userId} AND status = 'Closed'")->fetchColumn();

$recent = db()->query(
    "SELECT l.id, l.name, l.mobile, l.status, l.created_at, p.name AS project_name
     FROM leads l
     JOIN projects p ON p.id = l.project_id
     WHERE l.assigned_agent_id = {$userId}
     ORDER BY l.id DESC
     LIMIT 10"
)->fetchAll();

panel_header('Agent Dashboard');
?>
<div class="grid md:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Assigned Leads</p><p class="text-3xl font-bold"><?= $totalAssigned ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">New</p><p class="text-3xl font-bold text-amber-600"><?= $newLeads ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Follow-up</p><p class="text-3xl font-bold text-cyan-600"><?= $followUps ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Closed</p><p class="text-3xl font-bold text-emerald-600"><?= $closed ?></p></div>
</div>

<div class="mt-6 bg-white border rounded-xl overflow-hidden">
    <div class="px-4 py-3 border-b font-bold">Recent Assigned Leads</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-100"><tr><th class="px-3 py-2 text-left">Lead</th><th class="px-3 py-2 text-left">Project</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Date</th></tr></thead>
        <tbody>
            <?php foreach ($recent as $lead): ?>
                <tr class="border-t">
                    <td class="px-3 py-2"><?= e($lead['name']) ?><br><span class="text-xs text-slate-500"><?= e($lead['mobile']) ?></span></td>
                    <td class="px-3 py-2"><?= e($lead['project_name']) ?></td>
                    <td class="px-3 py-2"><span class="px-2 py-1 text-xs rounded <?= e(status_badge_class($lead['status'])) ?>"><?= e($lead['status']) ?></span></td>
                    <td class="px-3 py-2"><?= e($lead['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php panel_footer();
