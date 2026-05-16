<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('superadmin');

$projectStats = db()->query(
    "SELECT p.name AS project_name, COUNT(l.id) AS total_leads,
        SUM(CASE WHEN l.status = 'Closed' THEN 1 ELSE 0 END) AS closed_leads
     FROM projects p
     LEFT JOIN leads l ON l.project_id = p.id
     GROUP BY p.id
     ORDER BY total_leads DESC"
)->fetchAll();

$agentStats = db()->query(
    "SELECT u.name AS agent_name, COUNT(l.id) AS assigned_leads,
        SUM(CASE WHEN l.status = 'Closed' THEN 1 ELSE 0 END) AS closed_leads,
        SUM(CASE WHEN l.status = 'Follow-up' THEN 1 ELSE 0 END) AS follow_ups
     FROM users u
     LEFT JOIN leads l ON l.assigned_agent_id = u.id
     WHERE u.role = 'agent'
     GROUP BY u.id
     ORDER BY assigned_leads DESC"
)->fetchAll();

panel_header('Reports & Performance');
?>
<div class="grid lg:grid-cols-2 gap-5">
    <div class="bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b font-bold">Project Performance</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100"><tr><th class="px-3 py-2 text-left">Project</th><th class="px-3 py-2 text-left">Total Leads</th><th class="px-3 py-2 text-left">Closed</th></tr></thead>
            <tbody>
                <?php foreach ($projectStats as $row): ?>
                    <tr class="border-t"><td class="px-3 py-2"><?= e($row['project_name']) ?></td><td class="px-3 py-2"><?= (int) $row['total_leads'] ?></td><td class="px-3 py-2"><?= (int) $row['closed_leads'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b font-bold">Agent Performance</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100"><tr><th class="px-3 py-2 text-left">Agent</th><th class="px-3 py-2 text-left">Assigned</th><th class="px-3 py-2 text-left">Follow-up</th><th class="px-3 py-2 text-left">Closed</th></tr></thead>
            <tbody>
                <?php foreach ($agentStats as $row): ?>
                    <tr class="border-t">
                        <td class="px-3 py-2"><?= e($row['agent_name']) ?></td>
                        <td class="px-3 py-2"><?= (int) $row['assigned_leads'] ?></td>
                        <td class="px-3 py-2"><?= (int) $row['follow_ups'] ?></td>
                        <td class="px-3 py-2"><?= (int) $row['closed_leads'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php panel_footer();
