<?php

declare(strict_types=1);

require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/lead_service.php';

$projects = get_active_projects();
$appName = (string) config('app.name');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> | Premium Real Estate Projects</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', sans-serif; }
        .title-font { font-family: 'Playfair Display', serif; }
    </style>
</head>
<body class="bg-slate-950 text-white min-h-screen">
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,#16a34a33,transparent_45%),radial-gradient(circle_at_bottom_left,#0891b233,transparent_45%)]"></div>
        <div class="relative max-w-7xl mx-auto px-6 py-14 lg:py-20">
            <p class="uppercase tracking-[0.2em] text-emerald-300 text-sm">Paradise Group</p>
            <h1 class="title-font text-4xl md:text-6xl leading-tight mt-4">Crafted Spaces for Elevated Living</h1>
            <p class="mt-5 text-slate-200 max-w-2xl">Discover premium residences and villas designed for high-value appreciation, luxury lifestyle, and seamless city connectivity.</p>
        </div>
    </section>

    <section class="max-w-7xl mx-auto px-6 pb-16">
        <div class="grid md:grid-cols-2 gap-6">
            <?php foreach ($projects as $project): ?>
                <article class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl shadow-black/30">
                    <img src="<?= e($project['hero_image']) ?>" alt="<?= e($project['name']) ?>" class="h-64 w-full object-cover">
                    <div class="p-6">
                        <h2 class="title-font text-2xl"><?= e($project['name']) ?></h2>
                        <p class="text-slate-300 mt-2"><?= e($project['location']) ?></p>
                        <p class="text-emerald-300 font-semibold mt-1"><?= e($project['price_range']) ?></p>
                        <a href="<?= e(base_url('project/' . $project['slug'])) ?>" class="inline-flex mt-5 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold px-5 py-3 rounded-lg transition">View Project</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</body>
</html>
