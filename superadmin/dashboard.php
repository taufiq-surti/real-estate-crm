<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('superadmin');

$totalLeads = (int) db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$newLeads = (int) db()->query("SELECT COUNT(*) FROM leads WHERE status = 'New'")->fetchColumn();
$activeProjects = (int) db()->query('SELECT COUNT(*) FROM projects WHERE status = 1')->fetchColumn();
$totalAgents = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'agent' AND is_active = 1")->fetchColumn();

$recentLeads = db()->query(
    "SELECT l.id, l.name, l.mobile, l.status, l.created_at, p.name AS project_name, u.name AS agent_name
     FROM leads l
     JOIN projects p ON p.id = l.project_id
     LEFT JOIN users u ON u.id = l.assigned_agent_id
     ORDER BY l.id DESC
     LIMIT 10"
)->fetchAll();

panel_header('Super Admin Dashboard');
?>
<div class="grid md:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Total Leads</p><p class="text-3xl font-bold mt-1"><?= $totalLeads ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">New Leads</p><p class="text-3xl font-bold mt-1 text-amber-600"><?= $newLeads ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Active Projects</p><p class="text-3xl font-bold mt-1 text-emerald-600"><?= $activeProjects ?></p></div>
    <div class="bg-white rounded-xl border p-5"><p class="text-sm text-slate-500">Active Agents</p><p class="text-3xl font-bold mt-1 text-cyan-600"><?= $totalAgents ?></p></div>
</div>

<div class="mt-6 bg-white border rounded-xl overflow-hidden">
    <div class="px-4 py-3 border-b font-bold">Recent Leads</div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-3 py-2 text-left">Lead</th>
                    <th class="px-3 py-2 text-left">Project</th>
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLeads as $lead): ?>
                    <tr class="border-t">
                        <td class="px-3 py-2"><?= e($lead['name']) ?><br><span class="text-xs text-slate-500"><?= e($lead['mobile']) ?></span></td>
                        <td class="px-3 py-2"><?= e($lead['project_name']) ?></td>
                        <td class="px-3 py-2"><?= e($lead['agent_name'] ?: 'Unassigned') ?></td>
                        <td class="px-3 py-2"><span class="px-2 py-1 text-xs rounded <?= e(status_badge_class($lead['status'])) ?>"><?= e($lead['status']) ?></span></td>
                        <td class="px-3 py-2"><?= e($lead['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php panel_footer();
