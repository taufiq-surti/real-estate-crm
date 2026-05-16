<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function panel_links(string $role): array
{
    if ($role === 'superadmin') {
        return [
            ['label' => 'Dashboard', 'path' => 'superadmin/dashboard.php'],
            ['label' => 'Projects', 'path' => 'superadmin/projects.php'],
            ['label' => 'Leads', 'path' => 'superadmin/leads.php'],
            ['label' => 'Agents', 'path' => 'superadmin/agents.php'],
            ['label' => 'Reports', 'path' => 'superadmin/reports.php'],
        ];
    }

    return [
        ['label' => 'Dashboard', 'path' => 'agent/dashboard.php'],
        ['label' => 'Leads', 'path' => 'agent/leads.php'],
    ];
}

function panel_header(string $title): void
{
    $user = current_user();
    $role = $user['role'] ?? 'agent';
    $links = panel_links($role);
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet">';
    echo '<style>body{font-family:Manrope,sans-serif;}</style>';
    echo '</head>';
    echo '<body class="bg-slate-100 text-slate-900">';
    echo '<div class="min-h-screen flex">';
    echo '<aside class="w-64 bg-slate-900 text-white hidden lg:block">';
    echo '<div class="p-6 border-b border-slate-800">';
    echo '<h2 class="text-xl font-bold">Paradise CRM</h2>';
    echo '<p class="text-xs uppercase mt-1 tracking-wide text-slate-300">' . e(strtoupper($role)) . '</p>';
    echo '</div>';
    echo '<nav class="p-4 space-y-1">';

    foreach ($links as $item) {
        $url = base_url($item['path']);
        $active = str_contains($current, '/' . $item['path']);
        $cls = $active ? 'bg-emerald-500 text-slate-950 font-bold' : 'hover:bg-slate-800';
        echo '<a class="block px-3 py-2 rounded-md ' . $cls . '" href="' . e($url) . '">' . e($item['label']) . '</a>';
    }

    echo '<a class="block px-3 py-2 rounded-md hover:bg-slate-800" href="' . e(base_url('admin/logout.php')) . '">Logout</a>';
    echo '</nav>';
    echo '</aside>';
    echo '<main class="flex-1">';
    echo '<header class="bg-white border-b px-5 py-4 flex items-center justify-between">';
    echo '<h1 class="text-xl font-bold">' . e($title) . '</h1>';
    echo '<div class="text-sm text-slate-600">' . e($user['name'] ?? '') . '</div>';
    echo '</header>';
    echo '<div class="p-5">';
}

function panel_footer(): void
{
    echo '</div>';
    echo '</main>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
}
