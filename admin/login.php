<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';

if (is_logged_in()) {
    $role = current_user()['role'];
    redirect($role === 'superadmin' ? 'superadmin/dashboard.php' : 'agent/dashboard.php');
}

$error = '';
if (is_post()) {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (login_user($email, $password)) {
        $role = current_user()['role'];
        redirect($role === 'superadmin' ? 'superadmin/dashboard.php' : 'agent/dashboard.php');
    }

    $error = 'Invalid credentials.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | <?= e(config('app.name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white rounded-2xl p-7 shadow-xl">
        <h1 class="text-2xl font-bold">CRM Login</h1>
        <p class="text-slate-500 mt-1">Super Admin / Sales Agent</p>

        <?php if ($error): ?>
            <div class="mt-4 p-3 rounded-md bg-red-100 text-red-700 text-sm"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="mt-6 space-y-4">
            <div>
                <label class="block text-sm mb-1">Email</label>
                <input type="email" name="email" required class="w-full border rounded-md px-3 py-2">
            </div>
            <div>
                <label class="block text-sm mb-1">Password</label>
                <input type="password" name="password" required class="w-full border rounded-md px-3 py-2">
            </div>
            <button class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-2 rounded-md">Login</button>
        </form>

        <!-- <div class="mt-5 text-xs text-slate-500">
            Default super admin after install:<br>
            admin@paradisegroup.local / admin123
        </div> -->
    </div>
</body>
</html>
