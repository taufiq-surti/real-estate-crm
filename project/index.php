<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/lead_service.php';

function clean_media_token(string $value): string
{
    return trim($value, " \t\n\r\0\x0B\"'[]");
}

function split_media_values(array $items): array
{
    $media = [];

    foreach ($items as $item) {
        $value = clean_media_token((string) $item);
        if ($value === '') {
            continue;
        }

        $parts = array_map('clean_media_token', explode(',', $value));
        foreach ($parts as $part) {
            if ($part !== '') {
                $media[] = $part;
            }
        }
    }

    return array_values($media);
}

function to_embed_url(string $url): string
{
    $url = clean_media_token($url);

    if (preg_match('/youtube\.com\/embed\//i', $url) || preg_match('/player\.vimeo\.com\/video\//i', $url)) {
        return $url;
    }

    if (preg_match('/youtube\.com\/watch\?v=([^&]+)/i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }

    if (preg_match('/youtu\.be\/([^?&]+)/i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }

    if (preg_match('/vimeo\.com\/(\d+)/i', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }

    return $url;
}

function detect_gallery_type(string $url): string
{
    if (preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|vimeo\.com\/|player\.vimeo\.com\/video\/)/i', $url)) {
        return 'embed';
    }

    if (preg_match('/\.(mp4|webm|ogg)$/i', $url)) {
        return 'video';
    }

    return 'image';
}

function parse_gallery_media_items(?string $value, ?string $fallback = null): array
{
    $items = [];

    if ($value) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry) && isset($entry['url'])) {
                    $url = clean_media_token((string) $entry['url']);
                    if ($url === '') {
                        continue;
                    }

                    $type = trim((string) ($entry['type'] ?? ''));
                    if ($type === '') {
                        $type = detect_gallery_type($url);
                    }

                    if ($type === 'embed') {
                        $url = to_embed_url($url);
                    }

                    $items[] = ['type' => $type, 'url' => $url];
                    continue;
                }

                if (is_string($entry)) {
                    $parts = array_values(array_filter(array_map('clean_media_token', explode(',', $entry))));
                    foreach ($parts as $part) {
                        $type = detect_gallery_type($part);
                        $items[] = ['type' => $type, 'url' => $type === 'embed' ? to_embed_url($part) : $part];
                    }
                }
            }
        } else {
            $parts = split_media_values(parse_text_list($value));
            foreach ($parts as $part) {
                $type = detect_gallery_type($part);
                $items[] = ['type' => $type, 'url' => $type === 'embed' ? to_embed_url($part) : $part];
            }
        }
    }

    if (empty($items) && $fallback) {
        $items[] = ['type' => 'image', 'url' => trim($fallback)];
    }

    return array_values($items);
}

function to_public_media_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }

    if (preg_match('/^https?:\/\//i', $url) || str_starts_with($url, '//')) {
        return $url;
    }

    return base_url(ltrim($url, '/'));
}

function parse_amenities_with_media(?string $value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['title'])) {
                $title = trim((string) $entry['title']);
                $mediaSource = $entry['media'] ?? [];
                $media = is_array($mediaSource)
                    ? split_media_values(array_map('strval', $mediaSource))
                    : split_media_values([(string) $mediaSource]);

                if ($title !== '') {
                    $items[] = ['title' => $title, 'media' => $media];
                }
                continue;
            }

            if (is_string($entry)) {
                $line = trim($entry);
                if ($line === '') {
                    continue;
                }

                $title = $line;
                $media = [];

                if (str_contains($line, '|')) {
                    [$title, $rawMedia] = array_pad(explode('|', $line, 2), 2, '');
                    $title = trim($title);
                    $media = split_media_values([$rawMedia]);
                }

                // If a JSON string entry itself contains comma-separated amenities,
                // split into one card per amenity item.
                if (!str_contains($line, '|') && str_contains($title, ',')) {
                    $titles = array_values(array_filter(array_map('trim', explode(',', $title))));
                    foreach ($titles as $singleTitle) {
                        $items[] = ['title' => $singleTitle, 'media' => []];
                    }
                    continue;
                }

                if ($title !== '') {
                    $items[] = ['title' => $title, 'media' => $media];
                }
            }
        }

        return $items;
    }

    $rows = preg_split('/\r\n|\r|\n/', $value) ?: [];
    if (count($rows) <= 1 && str_contains((string) $value, ',')) {
        $rows = array_map('trim', explode(',', (string) $value));
    }

    $items = [];
    foreach ($rows as $row) {
        $line = trim((string) $row);
        if ($line === '') {
            continue;
        }

        $title = $line;
        $media = [];

        if (str_contains($line, '|')) {
            [$title, $rawMedia] = array_pad(explode('|', $line, 2), 2, '');
            $title = trim($title);
            $media = split_media_values([$rawMedia]);
        }

        // Support rows like "Gym, Parking, CCTV" -> separate article per item.
        if (!str_contains($line, '|') && str_contains($title, ',')) {
            $titles = array_values(array_filter(array_map('trim', explode(',', $title))));
            foreach ($titles as $singleTitle) {
                $items[] = ['title' => $singleTitle, 'media' => []];
            }
            continue;
        }

        if ($title !== '') {
            $items[] = ['title' => $title, 'media' => $media];
        }
    }

    return $items;
}

function parse_structured_cards(?string $raw): array
{
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $configuration = trim((string) ($entry['configuration'] ?? ''));
        $area = trim((string) ($entry['area'] ?? ''));
        $price = trim((string) ($entry['price'] ?? ''));
        $image = trim((string) ($entry['image'] ?? ''));
        if ($title === '' && $description === '' && $configuration === '' && $area === '' && $price === '' && $image === '') {
            continue;
        }

        $items[] = [
            'title' => $title,
            'description' => $description,
            'configuration' => $configuration,
            'area' => $area,
            'price' => $price,
            'image' => $image,
        ];
    }

    return $items;
}

function default_project_theme(): array
{
    return [
        'primary' => '#059669',
        'secondary' => '#0f766e',
        'background' => '#f8fafc',
        'text' => '#0f172a',
        'navbar' => '#ffffff',
        'footer' => '#020617',
        'border' => '#cbd5e1',
        'card' => '#ffffff',
        'footer_color' => '#94a3b8',
    ];
}

function normalize_theme_color(string $value, string $fallback): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }

    return strtolower($fallback);
}

function parse_project_theme(?string $raw): array
{
    $defaults = default_project_theme();
    if (!$raw) {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return $defaults;
    }

    $theme = $defaults;
    foreach ($defaults as $key => $fallback) {
        $theme[$key] = normalize_theme_color((string) ($decoded[$key] ?? ''), $fallback);
    }

    return $theme;
}

$projects = get_active_projects();
$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    if (!empty($projects)) {
        redirect('project/' . $projects[0]['slug']);
    }

    http_response_code(404);
    echo 'No projects available';
    exit;
}

$project = get_project_by_slug($slug);
if (!$project) {
    http_response_code(404);
    echo 'Project not found';
    exit;
}

$highlights = parse_text_list($project['highlights']);
$amenityItems = parse_amenities_with_media($project['amenities']);
$locationAdvantages = parse_text_list($project['location_advantages']);
$formToken = generate_form_token('lead_form_' . $project['id']);

$phone = $project['phone_number'] ?: config('contact.default_phone');
$whatsapp = $project['whatsapp_number'] ?: config('contact.default_whatsapp');
$whatsappUrl = whatsapp_link((string) $whatsapp, 'Hello, I am interested in ' . $project['name']);
$appName = (string) config('app.name');
$formSubmitUrl = base_url('api/submit_lead.php');
$phoneHref = preg_replace('/\D+/', '', (string) $phone) ?: '';
$galleryItems = parse_gallery_media_items($project['gallery'] ?? '', $project['hero_image'] ?? '');
$heroImageUrl = to_public_media_url((string) ($project['hero_image'] ?? ''));
$aboutImageUrl = to_public_media_url((string) ($project['about_image'] ?? ''));
$floorPlans = parse_structured_cards((string) ($project['floor_plans'] ?? ''));
$visualExperiences = parse_structured_cards((string) ($project['visual_experiences'] ?? ''));
$projectTheme = parse_project_theme((string) ($project['theme_settings'] ?? ''));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($project['name']) ?> | <?= e($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Cormorant+Garamond:wght@500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css"
        integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        html,
        body {
            width: 100%;
            overflow-x: clip;
        }
        body {
            font-family: 'Manrope', sans-serif;
        }

        .display-font {
            font-family: 'Cormorant Garamond', serif;
        }

        .section-label {
            letter-spacing: 0.25em;
        }

        :root {
            --theme-primary:
                <?= e($projectTheme['primary']) ?>
            ;
            --theme-secondary:
                <?= e($projectTheme['secondary']) ?>
            ;
            --theme-background:
                <?= e($projectTheme['background']) ?>
            ;
            --theme-text:
                <?= e($projectTheme['text']) ?>
            ;
            --theme-navbar:
                <?= e($projectTheme['navbar']) ?>
            ;
            --theme-footer:
                <?= e($projectTheme['footer']) ?>
            ;
            --theme-border:
                <?= e($projectTheme['border']) ?>
            ;
            --theme-card:
                <?= e($projectTheme['card']) ?>
            ;
            --theme-footer-color:
                <?= e($projectTheme['footer_color']) ?>
            ;
        }

        body {
            background: var(--theme-background);
            color: var(--theme-text);
            transition: background-color .25s ease, color .25s ease;
        }

        header {
            background: var(--theme-navbar) !important;
            border-color: var(--theme-border) !important;
        }

        footer,
        section#contact {
            background: var(--theme-footer) !important;
            color: #ffffff !important;
            border-color: var(--theme-primary) !important;
        }

        .border-theme {
            border-color: var(--theme-border) !important;
        }

        .bg-card {
            background: var(--theme-card) !important;
        }

        h1,
        h2,
        h3,
        h4,
        .display-font {
            color: var(--theme-secondary);
        }

        p,
        span,
        li,
        td,
        th,
        label {
            color: var(--theme-text);
        }

        .section-label,
        .text-primary {
            color: var(--theme-primary) !important;
        }

        a,
        .nav-list:hover {
            color: var(--theme-primary);
            transition: color .2s ease;
        }

        button,
        [type='submit'],
        .bg-primary {
            background: var(--theme-primary) !important;
            border-color: var(--theme-primary) !important;
            color: #ffffff !important;
            transition: all .25s ease;
        }

        button:hover,
        [type='submit']:hover,
        .bg-primary:hover,
        .hover\:bg-primary:hover {
            background: var(--theme-secondary) !important;
            border-color: var(--theme-secondary) !important;
        }

        .border-primary {
            border-color: var(--theme-primary) !important;
        }

        input,
        textarea,
        select,
        .rounded-xl,
        .rounded-lg,
        .rounded-md {
            transition: all .25s ease;
        }

        input,
        textarea,
        select {
            color: var(--theme-text) !important;
            border-color: var(--theme-border) !important;
        }

        .banner-font {
            color: var(--theme-card) !important;
        }

        .text-slate-400 {
            color: var(--theme-footer-color) !important;
        }

        .img-wrap-animation {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            height: 380px;
        }

        .pulse {
            animation: pulse-animation 2s infinite;
        }

        .pulse-zoom {
            animation: pulse-zoom-animation 5s infinite;
        }

        @keyframes pulse-animation {
            0% {
                box-shadow: 0 0 0 0px rgba(2, 132, 199, 0.5);
            }

            100% {
                box-shadow: 0 0 0 20px rgba(0, 0, 0, 0);
            }
        }

        @-webkit-keyframes pulse-zoom-animation {
            0% {
                -moz-transform: scale(0.9);
                -ms-transform: scale(0.9);
                -webkit-transform: scale(0.9);
                transform: scale(0.9);
            }

            8% {
                -moz-transform: scale(1);
                -ms-transform: scale(1);
                -webkit-transform: scale(1);
                transform: scale(1);
            }

            16% {
                -moz-transform: scale(0.9);
                -ms-transform: scale(0.9);
                -webkit-transform: scale(0.9);
                transform: scale(0.9);
            }

            32% {
                -moz-transform: scale(1);
                -ms-transform: scale(1);
                -webkit-transform: scale(1);
                transform: scale(1);
            }

            100% {
                -moz-transform: scale(1);
                -ms-transform: scale(1);
                -webkit-transform: scale(1);
                transform: scale(1);
            }
        }
    </style>
</head>

<body>
    <header class="fixed w-full top-0 z-50 border-b border-primary backdrop-blur-sm">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
            <a href="<?= e(base_url()) ?>" class="font-extrabold tracking-wide text-lg">
                <img src="../assets/uploads/projects/logo.webp" alt="Logo" class="h-14 w-auto">
                <!-- < ?= e($appName) ?> -->
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-semibold">
                <a href="#home" class="nav-list">HOME</a>
                <a href="#overview" class="nav-list">OVERVIEW</a>
                <a href="#amenities" class="nav-list">AMENITIES</a>
                <a href="#plans" class="nav-list">FLOOR PLANS</a>
                <a href="#location" class="nav-list">LOCATION</a>
                <a href="#contact" class="nav-list">ENQUIRE NOW</a>
            </nav>
            <div class="flex items-center gap-2">
                <a href="#contact"
                    class="downloadBrochureBtn hidden sm:inline-flex bg-primary px-4 py-2 rounded-md text-sm font-bold shadow-lg">Download
                    Brochure</a>
                <a href="tel:<?= e($phoneHref) ?>"
                    class="inline-flex items-center gap-2 border border-primary px-3 py-2 rounded-md text-sm font-bold whitespace-nowrap shadow-lg">
                    <i class="fa-solid fa-phone text-primary"></i>
                    <span><?= e($phone) ?></span>
                </a>
            </div>
        </div>
    </header>
    <!-- Brochure Modal -->
    <div id="brochureModal" class="hidden fixed inset-0 z-[200] bg-black/50 flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-white rounded-xl p-6 text-center relative">
            <button id="closeBrochureModal"
                class="absolute top-3 right-3 text-2xl font-bold rounded-full w-8">&times;</button>
            <div class="mb-4">
                <img src="../assets/uploads/projects/logo.webp" alt="Logo" class="mx-auto mb-2"
                    style="max-height:48px;">
                <h2 class="text-xl font-bold mb-1">Download Brochure</h2>
                <p class="text-sm">Register Here And Avail The <span class="text-primary font-semibold">Best
                        Offers!</span></p>
            </div>
            <form id="brochureForm" class="space-y-4">
                <input type="text" name="name" placeholder="Full Name *" required class="w-full border-b px-3 py-2">
                <input type="text" name="mobile" placeholder="Mobile (10 digits) *" maxlength="10" required
                    class="w-full border-b px-3 py-2">
                <input type="email" name="email" placeholder="Email (optional)" class="w-full border-b px-3 py-2">
                <input type="text" name="city" placeholder="City (optional)" class="w-full border-b px-3 py-2">
                <textarea name="message" rows="3" required placeholder="Tell us your requirement *"
                    class="w-full border-b px-3 py-2"></textarea>
                <div class="flex items-center text-xs text-slate-500">
                    <input type="checkbox" checked class="mr-2"> By submitting I accept <a href="#"
                        class="text-primary underline ml-1">Privacy Policy</a>.
                </div>
                <button type="submit" class="w-full bg-primary font-bold py-2.5 rounded-md">Download
                    now</button>
                <p id="brochureFormStatus" class="text-xs text-center"></p>
            </form>
        </div>
    </div>
    <section id="home" class="relative min-h-screen py-16 bg-cover bg-center flex items-center"
        style="background-image: linear-gradient(to right, rgba(2,6,23,0.95), rgba(2,6,23,0.35)), url('<?= e($heroImageUrl) ?>');">
        <div class="max-w-7xl mx-auto px-6 pt-20 grid lg:grid-cols-[1.1fr_0.9fr] gap-10 items-center">
            <div class="text-white">
                <p class="section-label text-xs text-primary font-bold ban-sub-text">PREMIUM LIVING DESTINATION</p>
                <h1 class="display-font banner-font text-5xl md:text-6xl leading-none mt-3 font-semibold ban-sub-text">
                    <?= e($project['name']) ?>
                </h1>
                <p class="mt-4 text-xl text-slate-200"><?= e($project['location']) ?></p>
                <p class="mt-2 text-2xl text-primary font-bold" data-aos="zoom-out"><?= e($project['price_range']) ?>
                </p>
                <p class="mt-5 max-w-2xl text-slate-100/90">Strategic living meets confident investment with
                    thoughtfully planned residences, lifestyle amenities, and seamless city connectivity.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="#contact" class="downloadBrochureBtn bg-primary px-6 py-3 rounded-md font-bold">Download
                        Brochure</a>
                    <a href="tel:<?= e(preg_replace('/\D+/', '', (string) $phone)) ?>"
                        class="border border-primary px-6 py-3 rounded-md font-bold" data-aos="zoom-out"
                        data-aos-easing="ease-in-back" data-aos-offset="0">CALL
                        NOW</a>
                </div>
            </div>

            <div id="formsection"
                class="bg-slate-900/60 rounded-xl p-5 shadow-2xl border border-primary max-w-lg lg:ml-auto">
                <h2 class="text-xl font-extrabold text-primary">Register Interest</h2>
                <p class="text-sm text-slate-100/90 mt-1">Get latest offers, floor plans and site visit details.</p>
                <form id="projectLeadForm" class="mt-1 space-y-4" data-project-id="<?= (int) $project['id'] ?>"
                    data-token="<?= e($formToken) ?>" data-submit-url="<?= e($formSubmitUrl) ?>">
                    <input type="hidden" name="trap" value="">
                    <input type="hidden" name="form_name" value="lead_form_<?= (int) $project['id'] ?>">
                    <input type="text" name="name" placeholder="Full Name *" required
                        class="w-full border rounded-md px-3 py-2">
                    <input type="text" name="mobile" placeholder="Mobile (10 digits) *" maxlength="10" required
                        class="w-full border rounded-md px-3 py-2">
                    <input type="email" name="email" placeholder="Email (optional)"
                        class="w-full border rounded-md px-3 py-2">
                    <input type="text" name="city" placeholder="City (optional)"
                        class="w-full border rounded-md px-3 py-2">
                    <textarea name="message" rows="3" required placeholder="Tell us your requirement *"
                        class="w-full border rounded-md px-3 py-2"></textarea>
                    <button type="submit" class="w-full bg-primary font-bold py-2.5 rounded-md">GET
                        PRICING DETAILS</button>
                    <p id="formStatus" class="text-xs text-center"></p>
                </form>
            </div>
        </div>
    </section>

    <section id="overview" class="py-20 bg-card">
        <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <p class="section-label text-xs text-primary font-bold" data-aos="fade-up">STRATEGIC VALUE</p>
                <h2 class="display-font text-5xl font-semibold mt-2" data-aos="fade-up">Why This Project</h2>
                <p class="mt-4 leading-7" data-aos="fade-up"><?= nl2br(e((string) $project['about_text'])) ?></p>
                <div class="mt-6 grid sm:grid-cols-2 gap-3">
                    <?php foreach (array_slice($highlights, 0, 6) as $index => $item): ?>
                        <div class="flex gap-2 items-start" data-aos="fade-up"
                            data-aos-duration="<?= 1000 + ($index * 200) ?>">
                            <i class="fa-solid fa-check text-primary mt-1"></i>
                            <span><?= e($item) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="img-wrap-animation">
                    <img src="<?= e($aboutImageUrl) ?>" alt="<?= e($project['name']) ?>"
                        class="parallax-img1 h-[380px] w-full object-cover">
                </div>
                <p class="text-xs mt-2">Artistic impression</p>
            </div>
        </div>
    </section>

    <section id="amenities" class="py-20">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-primary font-bold text-center">CURATED EXPERIENCES</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">World-Class Amenities</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 justify-center gap-5 mt-12">
                <?php foreach ($amenityItems as $index => $item): ?>
                    <article class="bg-white rounded-lg overflow-hidden shadow-md border border-salte-300"
                        data-aos="fade-up" data-aos-duration="<?= 600 + ($index * 200) ?>">
                        <?php $media = $item['media']; ?>
                        <?php if (!empty($media)): ?>
                            <div class="grid grid-cols-2 gap-1 p-1 bg-card">
                                <img src="../<?= e($media[0]) ?>" alt="<?= e($item['title']) ?>"
                                    class="w-full h-36 object-cover rounded-sm col-span-2 transform transition-transform duration-500 ease-out will-change-transform hover:scale-105"">
                                <?php if (!empty($media[1])): ?>
                                    <img src=" ../<?= e($media[1]) ?>" alt="<?= e($item['title']) ?>"
                                        class="w-full h-16 object-cover rounded-sm">
                                <?php endif; ?>
                                <?php if (!empty($media[2])): ?>
                                    <img src="../<?= e($media[2]) ?>" alt="<?= e($item['title']) ?>"
                                        class="w-full h-16 object-cover rounded-sm">
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="h-24 bg-card flex items-center justify-center text-sm">No amenity
                                media added</div>
                        <?php endif; ?>
                        <div class="p-4">
                            <h3 class="font-bold"><?= e($item['title']) ?></h3>
                            <p class="text-sm text-slate-500 mt-1">Designed for everyday comfort and premium urban living.
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="plans" class="py-20 bg-card">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-primary font-bold text-center">SPATIAL GRANDEUR</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">Floor Plans & Layouts</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5 mt-12">
                <?php foreach (!empty($floorPlans) ? $floorPlans : range(1, 4) as $i => $item): ?>
                    <article class="bg-white rounded-lg overflow-hidden shadow-md border border-slate-300"
                        data-aos="fade-up" data-aos-duration="<?= 600 + ($i * 200) ?>">
                        <?php
                        $fallbackMode = !is_array($item);
                        $cardTitle = $fallbackMode ? ('Tower Layout ' . ((int) $i + 1)) : (trim((string) ($item['title'] ?? '')) ?: ('Tower Layout ' . ((int) $i + 1)));
                        $cardDesc = $fallbackMode ? 'View smartly designed layout options built for modern family living.' : (trim((string) ($item['description'] ?? '')) ?: 'View smartly designed layout options built for modern family living.');
                        $cardConfig = $fallbackMode ? '' : trim((string) ($item['configuration'] ?? ''));
                        $cardArea = $fallbackMode ? '' : trim((string) ($item['area'] ?? ''));
                        $cardPrice = $fallbackMode ? '' : trim((string) ($item['price'] ?? ''));
                        $cardImage = $fallbackMode ? 'https://images.unsplash.com/photo-1516455590571-18256e5bb9ff?auto=format&fit=crop&w=700&q=80' : to_public_media_url((string) ($item['image'] ?? ''));
                        ?>
                        <img src="<?= e($cardImage) ?>" alt="<?= e($cardTitle) ?>"
                            class="w-full h-44 object-cover transform transition-transform duration-500 ease-out will-change-transform hover:scale-105">
                        <div class="p-4">
                            <h3 class="font-bold"><?= e($cardTitle) ?></h3>
                            <?php if ($cardConfig !== '' || $cardArea !== '' || $cardPrice !== ''): ?>
                                <p class="text-xs text-slate-500 mt-1">
                                    <?= e($cardConfig !== '' ? $cardConfig : 'Configuration N/A') ?>
                                    <?= e($cardArea !== '' ? ' | ' . $cardArea : '') ?>
                                    <?= e($cardPrice !== '' ? ' | ' . $cardPrice : '') ?>
                                </p>
                            <?php endif; ?>
                            <p class="text-sm text-slate-500 mt-1"><?= e($cardDesc) ?></p>
                            <button
                                class="downloadBrochureBtn mt-3 w-full border border-primary rounded-md py-2 font-semibold">View
                                Plan</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-20">
        <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <p class="section-label text-xs text-primary font-bold" data-aos="fade-up">VISUAL EXPERIENCE</p>
                <h2 class="display-font text-5xl font-semibold mt-2" data-aos="fade-up">Project Walkthrough</h2>
                <p class="mt-4 leading-7" data-aos="fade-up">A planned neighborhood with future-ready infrastructure,
                    elegant architecture and strong rental and appreciation potential.</p>
                <a href="#formsection" class="goToHomeBtn inline-flex mt-6 bg-primary px-6 py-3 rounded-md font-bold"
                    data-aos="fade-up">SCHEDULE
                    A WALKTHROUGH</a>
            </div>
            <?php
            $primaryVisual = !empty($visualExperiences)
                ? $visualExperiences[0]
                : ['title' => 'Project Walkthrough', 'description' => '', 'image' => '/assets/uploads/projects/20260518131324_73e83a96a8.jpg'];
            $primaryVisualImage = to_public_media_url((string) ($primaryVisual['image'] ?? ''));
            ?>
            <div class="img-wrap-animation">
                <img src="<?= e($primaryVisualImage) ?>"
                    alt="<?= e((string) ($primaryVisual['title'] ?? 'Walkthrough')) ?>"
                    class="parallax-img w-full rounded-lg h-[380px] object-cover shadow-xl">

            </div>
        </div>
        <?php if (!empty($visualExperiences)): ?>
            <div class="max-w-7xl mx-auto px-6 mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php foreach ($visualExperiences as $index => $item): ?>
                    <?php
                    $itemTitle = trim((string) ($item['title'] ?? ''));
                    $itemDesc = trim((string) ($item['description'] ?? ''));
                    $itemImage = to_public_media_url((string) ($item['image'] ?? ''));
                    ?>
                    <article class="bg-white rounded-lg overflow-hidden shadow-md border border-slate-300" data-aos="fade-up"
                        data-aos-duration="<?= 600 + ($index * 200) ?>">
                        <img src="<?= e($itemImage) ?>" alt="<?= e($itemTitle ?: 'Visual Experience') ?>"
                            class="w-full h-44 object-cover transform transition-transform duration-500 ease-out will-change-transform hover:scale-105">
                        <div class="p-4">
                            <h3 class="font-bold"><?= e($itemTitle ?: 'Visual Experience') ?></h3>
                            <p class="text-sm text-slate-500 mt-1">
                                <?= e($itemDesc ?: 'Explore the project ambience and key lifestyle highlights.') ?>
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="location" class="py-20 bg-card">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-primary font-bold text-center" data-aos="fade-up">THE CENTER OF
                EVERYTHING</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2" data-aos="fade-up">Unmatched Connectivity
            </h2>
            <p class="text-center max-w-3xl mx-auto mt-4">Located in a high-growth corridor with quick access to
                transport, work hubs, education and healthcare.</p>

            <div class="lg:flex lg:items-start lg:gap-10 mt-12">
                <div class="w-full lg:w-1/2">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m23!1m12!1m3!1d120604.56697641523!2d72.92190719999999!3d19.1561728!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!4m8!3e6!4m0!4m5!1s0x3be7eb7a0b1d0043%3A0x6bf5e90b18e94110!2sSai%20Sun%20City%20-%20by%20Paradise%20Group%2C%20Ghot%2C%20Taloja%2C%20Panvel%2C%20Maharashtra%20410208!3m2!1d19.0873864!2d73.1107624!5e0!3m2!1sen!2sin!4v1779863289413!5m2!1sen!2sin"
                        style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                        class="w-full h-64 lg:h-96 rounded-xl"></iframe>
                </div>
                <div class="grid md:grid-cols-2 gap-6 mt-12">
                    <div class="bg-white rounded-lg border border-slate-300 p-5" data-aos="fade-right">
                        <h3 class="font-bold mb-3">Connectivity</h3>
                        <ul class="space-y-2 text-sm">
                            <?php foreach (array_slice($locationAdvantages, 0, 4) as $item): ?>
                                <li>• <?= e($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="bg-white rounded-lg border border-slate-300 p-5" data-aos="fade-left">
                        <h3 class="font-bold mb-3">Business Hubs</h3>
                        <ul class="space-y-2 text-sm">
                            <li>• IT parks and startup hubs nearby</li>
                            <li>• Easy access to office corridors</li>
                            <li>• Direct route to CBD zones</li>
                        </ul>
                    </div>
                    <div class="bg-white rounded-lg border border-slate-300 p-5" data-aos="fade-right">
                        <h3 class="font-bold mb-3">Education</h3>
                        <ul class="space-y-2 text-sm">
                            <li>• Reputed schools in close radius</li>
                            <li>• Colleges and institutes nearby</li>
                            <li>• Coaching and learning centers</li>
                        </ul>
                    </div>
                    <div class="bg-white rounded-lg border border-slate-300 p-5" data-aos="fade-left">
                        <h3 class="font-bold mb-3">Health & Retail</h3>
                        <ul class="space-y-2 text-sm">
                            <li>• Multi-speciality hospitals</li>
                            <li>• Daily convenience stores</li>
                            <li>• Malls and high-street retail</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-primary font-bold text-center">GALLERY</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">Visual Experience</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-12">
                <?php foreach ($galleryItems as $index => $media): ?>
                    <?php $mediaUrl = to_public_media_url((string) $media['url']); ?>
                    <?php if ($media['type'] === 'video'): ?>
                        <video controls
                            class="w-full h-56 object-cover rounded-lg shadow-sm hover:shadow-lg transition bg-black"
                            data-aos="fade-up" data-aos-duration="<?= 600 + ($index * 200) ?>">
                            <source src="<?= e($mediaUrl) ?>">
                        </video>
                    <?php elseif ($media['type'] === 'embed'): ?>
                        <iframe src="<?= e($mediaUrl) ?>" title="Project video"
                            class="w-full h-56 rounded-lg shadow-sm hover:shadow-lg transition" loading="lazy"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen data-aos="fade-up" data-aos-duration="<?= 600 + ($index * 200) ?>"></iframe>
                    <?php else: ?>
                        <img src="<?= e($mediaUrl) ?>" alt="Project gallery image"
                            class="w-full h-56 object-cover rounded-lg shadow-sm hover:shadow-lg transition" data-aos="fade-up"
                            data-aos-duration="<?= 600 + ($index * 200) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="contact" class="py-20">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid lg:grid-cols-2 gap-10 items-center">
                <div>
                    <p class="section-label text-xs text-primary font-bold" data-aos="fade-up">ENQUIRE NOW</p>
                    <h2 class="display-font banner-font text-5xl font-semibold mt-2" data-aos="fade-up">Talk To Our
                        Property Advisor</h2>
                    <p class="mt-4 text-slate-400" data-aos="fade-up">Get brochure, payment plans and site visit
                        schedule instantly.</p>
                </div>
                <div class="bg-white rounded-xl p-6" data-aos="fade-down">
                    <form id="projectLeadFormSecondary" class="space-y-3"
                        onsubmit="document.getElementById('projectLeadForm').dispatchEvent(new Event('submit', {cancelable:true})); return false;">
                        <p class="text-sm text-slate-500">Use the top form in hero section to submit enquiry instantly.
                        </p>
                        <a href="#formsection"
                            class="goToHomeBtn inline-flex mt-2 bg-primary px-5 py-2.5 rounded-md font-bold">GO TO
                            ENQUIRY FORM</a>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <footer class="border-t">
        <div class="max-w-7xl mx-auto px-6 py-12 grid md:grid-cols-4 gap-8">
            <div>
                <h3 class="font-bold banner-font"><?= e($appName) ?></h3>
                <p class="text-sm text-slate-400 mt-2">Elevating urban lifestyles with design-led residential
                    communities.</p>
            </div>
            <div>
                <h4 class="font-semibold mb-3 banner-font">Quick Links</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#home" class="text-slate-400 nav-list">Home</a></li>
                    <li><a href="#overview" class="text-slate-400 nav-list">Overview</a></li>
                    <li><a href="#amenities" class="text-slate-400 nav-list">Amenities</a></li>
                    <li><a href="#plans" class="text-slate-400 nav-list">Floor Plans</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold mb-3 banner-font">Get In Touch</h4>
                <p class="text-sm text-slate-400"><?= e($project['location']) ?></p>
                <p class="text-sm mt-2"><a href="tel:<?= e(preg_replace('/\D+/', '', (string) $phone)) ?>"
                        class="text-slate-400 nav-list"><?= e($phone) ?></a></p>
            </div>
            <div>
                <h4 class="font-semibold mb-3 banner-font">Compliance</h4>
                <p class="text-xs text-slate-400">Visuals are illustrative only. Pricing and specifications may change
                    without prior notice.</p>
            </div>
        </div>
        <div class="border-t border-primary py-4 px-6 text-center text-sm banner-font">© 2026 <?= e($appName) ?>.
            All rights reserved.</div>
    </footer>

    <a href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener"
        class="fixed right-4 bottom-20 z-50 bg-green-500 hover:bg-green-600 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-xl"
        aria-label="WhatsApp">
        <i class="fa-brands fa-whatsapp text-2xl"></i>
    </a>
    <a href="tel:<?= e($phoneHref) ?>"
        class="fixed right-4 bottom-5 z-50 bg-sky-600 hover:bg-primary text-white rounded-full w-12 h-12 flex items-center justify-center shadow-xl pulse"
        aria-label="Call">
        <i class="fa-solid fa-phone"></i>
    </a>

    <div id="successModal" class="hidden fixed inset-0 z-[100] bg-black/50 p-4 items-center justify-center">
        <div class="max-w-md w-full bg-white rounded-xl p-6 text-center">
            <h3 class="text-2xl font-bold">Thank You</h3>
            <p class="mt-2">Your enquiry has been submitted successfully.</p>
            <button id="closeModal" class="mt-5 w-full py-2 rounded-md">Close</button>
        </div>
    </div>

    <script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
    <script>
        // Smooth scroll for GO TO ENQUIRY FORM button
        document.querySelectorAll('.goToHomeBtn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                const target = document.getElementById('formsection');
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                }
            });
        });
    </script>
    <script>
        // Modal logic for all Download Brochure buttons
        const brochureBtns = document.querySelectorAll('.downloadBrochureBtn');
        const brochureModal = document.getElementById('brochureModal');
        const closeBrochureModal = document.getElementById('closeBrochureModal');
        const brochureForm = document.getElementById('brochureForm');
        const brochureFormStatus = document.getElementById('brochureFormStatus');

        if (brochureBtns.length && brochureModal && closeBrochureModal) {
            brochureBtns.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    brochureModal.classList.remove('hidden');
                });
            });
            closeBrochureModal.addEventListener('click', function () {
                brochureModal.classList.add('hidden');
                brochureFormStatus.textContent = '';
                brochureForm.reset();
            });
            brochureModal.addEventListener('click', function (e) {
                if (e.target === brochureModal) {
                    brochureModal.classList.add('hidden');
                    brochureFormStatus.textContent = '';
                    brochureForm.reset();
                }
            });
        }

        // Brochure form submit logic
        if (brochureForm) {
            brochureForm.addEventListener('submit', function (e) {
                e.preventDefault();
                brochureFormStatus.textContent = 'Submitting...';
                // Simulate AJAX (replace with real API if needed)
                setTimeout(function () {
                    brochureFormStatus.textContent = 'Thank you! Your details have been submitted.';
                    // Download brochure.pdf (ensure this file exists in your public directory)
                    const link = document.createElement('a');
                    link.href = '/assets/brochure.pdf';
                    link.download = 'brochure.pdf';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    setTimeout(() => {
                        brochureModal.classList.add('hidden');
                        brochureFormStatus.textContent = '';
                        brochureForm.reset();
                    }, 1200);
                }, 900);
            });
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/SplitText.min.js"></script>
    <script>
        gsap.registerPlugin(ScrollTrigger);

        document.querySelectorAll(".img-wrap-animation").forEach((section) => {

            const image = section.querySelector(".parallax-img");
            const image1 = section.querySelector(".parallax-img1");

            gsap.fromTo(image,
                {
                    clipPath: "inset(0 100% 0 0)",
                    scale: 1.2
                },
                {
                    clipPath: "inset(0 0% 0 0)",
                    scale: 1,
                    duration: 1.6,   // ✅ THIS NOW WORKS PROPERLY
                    ease: "power4.out",
                    scrollTrigger: {
                        trigger: section,
                        start: "top 85%",
                        toggleActions: "play reset play reset"
                    }
                }
            );
            gsap.fromTo(image1,
                {
                    clipPath: "inset(0 0 100% 0)",
                    scale: 1.2
                },
                {
                    clipPath: "inset(0 0 0% 0)",
                    scale: 1,
                    ease: "none",
                    scrollTrigger: {
                        trigger: section,
                        start: "top 90%",
                        toggleActions: "play reset play reset",
                    }
                }
            );
        });
        gsap.registerPlugin(SplitText);
        const split = new SplitText(".ban-sub-text", {
            type: "words",
        });

        gsap.from(split.words, {
            y: 50,
            opacity: 0,
            duration: 1.5,
            stagger: 0.05,
            ease: "power4.out",
        });
    </script>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        AOS.init({
            duration: 1000,
        });
    </script>
</body>

</html>