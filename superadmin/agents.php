<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('superadmin');

$error = '';
if (is_post()) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error = 'Provide valid name, email and password (min 6 chars).';
    } else {
        $stmt = db()->prepare('INSERT INTO users (name, email, phone, password_hash, role, is_active, created_at) VALUES (:name, :email, :phone, :hash, :role, 1, NOW())');
        try {
            $stmt->execute([
                'name' => $name,
                'email' => strtolower($email),
                'phone' => $phone,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'agent',
            ]);
            redirect('superadmin/agents.php');
        } catch (Throwable $e) {
            $error = 'Could not create agent. Email may already exist.';
        }
    }
}

$toggleId = (int) ($_GET['toggle'] ?? 0);
if ($toggleId > 0) {
    $stmt = db()->prepare("UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id AND role = 'agent'");
    $stmt->execute(['id' => $toggleId]);
    redirect('superadmin/agents.php');
}

$agents = db()->query("SELECT id, name, email, phone, is_active, created_at FROM users WHERE role = 'agent' ORDER BY id DESC")->fetchAll();

panel_header('Manage Sales Agents');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="bg-white border rounded-xl p-5">
        <h2 class="font-bold text-lg">Create Agent</h2>
        <?php if ($error): ?><div class="mt-3 p-2 text-sm rounded bg-red-100 text-red-700"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="space-y-3 mt-4">
            <input name="name" required placeholder="Full Name" class="w-full border rounded px-3 py-2">
            <input name="email" type="email" required placeholder="Email" class="w-full border rounded px-3 py-2">
            <input name="phone" placeholder="Phone" class="w-full border rounded px-3 py-2">
            <input name="password" type="password" required placeholder="Password" class="w-full border rounded px-3 py-2">
            <button class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-2 rounded">Create Agent</button>
        </form>
    </div>

    <div class="lg:col-span-2 bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b font-bold">All Agents</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-3 py-2 text-left">Name</th>
                        <th class="px-3 py-2 text-left">Email</th>
                        <th class="px-3 py-2 text-left">Phone</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr class="border-t">
                            <td class="px-3 py-2"><?= e($agent['name']) ?></td>
                            <td class="px-3 py-2"><?= e($agent['email']) ?></td>
                            <td class="px-3 py-2"><?= e($agent['phone']) ?></td>
                            <td class="px-3 py-2"><?= (int) $agent['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td class="px-3 py-2">
                                <a class="text-cyan-700 font-semibold" href="<?= e(base_url('superadmin/agents.php?toggle=' . $agent['id'])) ?>">
                                    <?= (int) $agent['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php panel_footer();
