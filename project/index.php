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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Cormorant+Garamond:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body { font-family: 'Manrope', sans-serif; }
        .display-font { font-family: 'Cormorant Garamond', serif; }
        .section-label { letter-spacing: 0.25em; }
    </style>
</head>
<body class="bg-white text-slate-900">
    <header class="sticky top-0 z-50 bg-white/95 border-b border-slate-200 backdrop-blur-sm">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
            <a href="<?= e(base_url()) ?>" class="font-extrabold tracking-wide text-lg"><?= e($appName) ?></a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-semibold text-slate-700">
                <a href="#home" class="hover:text-emerald-700">HOME</a>
                <a href="#overview" class="hover:text-emerald-700">OVERVIEW</a>
                <a href="#amenities" class="hover:text-emerald-700">AMENITIES</a>
                <a href="#plans" class="hover:text-emerald-700">FLOOR PLANS</a>
                <a href="#location" class="hover:text-emerald-700">LOCATION</a>
                <a href="#contact" class="hover:text-emerald-700">ENQUIRE NOW</a>
            </nav>
            <div class="flex items-center gap-2">
                <a href="#contact" class="hidden sm:inline-flex bg-emerald-700 hover:bg-emerald-800 text-white px-4 py-2 rounded-md text-sm font-bold">BOOK VISIT</a>
                <a href="tel:<?= e($phoneHref) ?>" class="inline-flex items-center gap-2 border border-amber-600/40 hover:border-amber-600 text-slate-900 px-3 py-2 rounded-md text-sm font-bold whitespace-nowrap">
                    <i class="fa-solid fa-phone text-amber-700"></i>
                    <span><?= e($phone) ?></span>
                </a>
            </div>
        </div>
    </header>

    <section id="home" class="relative min-h-screen bg-cover bg-center" style="background-image: linear-gradient(to right, rgba(2,6,23,0.75), rgba(2,6,23,0.35)), url('<?= e($project['hero_image']) ?>');">
        <div class="max-w-7xl mx-auto px-6 pt-20 pb-14 grid lg:grid-cols-[1.1fr_0.9fr] gap-10 items-end min-h-screen">
            <div class="text-white">
                <p class="section-label text-xs text-emerald-300 font-bold">PREMIUM LIVING DESTINATION</p>
                <h1 class="display-font text-5xl md:text-6xl leading-none mt-3 font-semibold"><?= e($project['name']) ?></h1>
                <p class="mt-4 text-xl text-slate-200"><?= e($project['location']) ?></p>
                <p class="mt-2 text-2xl text-amber-300 font-bold"><?= e($project['price_range']) ?></p>
                <p class="mt-5 max-w-2xl text-slate-100/90">Strategic living meets confident investment with thoughtfully planned residences, lifestyle amenities, and seamless city connectivity.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="#contact" class="bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-3 rounded-md font-bold">BOOK A SITE VISIT</a>
                    <a href="tel:<?= e(preg_replace('/\D+/', '', (string) $phone)) ?>" class="border border-white/60 hover:bg-white/10 text-white px-6 py-3 rounded-md font-bold">CALL NOW</a>
                </div>
            </div>

            <div class="bg-white rounded-xl p-5 shadow-2xl border border-slate-200 max-w-lg lg:ml-auto">
                <h2 class="text-xl font-extrabold text-slate-900">Register Interest</h2>
                <p class="text-sm text-slate-500 mt-1">Get latest offers, floor plans and site visit details.</p>
                <form id="projectLeadForm" class="mt-4 space-y-3" data-project-id="<?= (int) $project['id'] ?>" data-token="<?= e($formToken) ?>" data-submit-url="<?= e($formSubmitUrl) ?>">
                    <input type="hidden" name="trap" value="">
                    <input type="hidden" name="form_name" value="lead_form_<?= (int) $project['id'] ?>">
                    <input type="text" name="name" placeholder="Full Name *" required class="w-full border border-slate-300 rounded-md px-3 py-2">
                    <input type="text" name="mobile" placeholder="Mobile (10 digits) *" maxlength="10" required class="w-full border border-slate-300 rounded-md px-3 py-2">
                    <input type="email" name="email" placeholder="Email (optional)" class="w-full border border-slate-300 rounded-md px-3 py-2">
                    <input type="text" name="city" placeholder="City (optional)" class="w-full border border-slate-300 rounded-md px-3 py-2">
                    <textarea name="message" rows="3" required placeholder="Tell us your requirement *" class="w-full border border-slate-300 rounded-md px-3 py-2"></textarea>
                    <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold py-2.5 rounded-md">GET PRICING DETAILS</button>
                    <p id="formStatus" class="text-xs text-center"></p>
                </form>
            </div>
        </div>
    </section>

    <section id="overview" class="py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <p class="section-label text-xs text-emerald-700 font-bold">STRATEGIC VALUE</p>
                <h2 class="display-font text-5xl font-semibold mt-2">Why This Project</h2>
                <p class="text-slate-600 mt-4 leading-7"><?= nl2br(e((string) $project['about_text'])) ?></p>
                <div class="mt-6 grid sm:grid-cols-2 gap-3">
                    <?php foreach (array_slice($highlights, 0, 6) as $item): ?>
                        <div class="flex gap-2 items-start">
                            <i class="fa-solid fa-check text-emerald-700 mt-1"></i>
                            <span class="text-slate-700"><?= e($item) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <img src="<?= e($project['about_image']) ?>" alt="<?= e($project['name']) ?>" class="w-full rounded-xl h-[420px] object-cover shadow-xl">
                <p class="text-xs text-slate-400 mt-2">Artistic impression</p>
            </div>
        </div>
    </section>

    <section id="amenities" class="py-20">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-emerald-700 font-bold text-center">CURATED EXPERIENCES</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">World-Class Amenities</h2>
            <div class="md:flex md:flex-wrap justify-center gap-5 mt-12">
                <?php foreach ($amenityItems as $item): ?>
                    <article class="md:w-5/12 lg:w-3/12 bg-white rounded-xl overflow-hidden shadow-md border border-slate-200">
                        <?php $media = $item['media']; ?>
                        <?php if (!empty($media)): ?>
                            <div class="grid grid-cols-2 gap-1 p-1 bg-slate-100">
                                <img src="../<?= e($media[0]) ?>" alt="<?= e($item['title']) ?>" class="w-full h-36 object-cover rounded-sm col-span-2">
                                <?php if (!empty($media[1])): ?>
                                    <img src="../<?= e($media[1]) ?>" alt="<?= e($item['title']) ?>" class="w-full h-16 object-cover rounded-sm">
                                <?php endif; ?>
                                <?php if (!empty($media[2])): ?>
                                    <img src="../<?= e($media[2]) ?>" alt="<?= e($item['title']) ?>" class="w-full h-16 object-cover rounded-sm">
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="h-24 bg-slate-100 flex items-center justify-center text-slate-400 text-sm">No amenity media added</div>
                        <?php endif; ?>
                        <div class="p-4">
                            <h3 class="font-bold text-slate-900"><?= e($item['title']) ?></h3>
                            <p class="text-sm text-slate-500 mt-1">Designed for everyday comfort and premium urban living.</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="plans" class="py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-emerald-700 font-bold text-center">SPATIAL GRANDEUR</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">Floor Plans & Layouts</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5 mt-12">
                <?php foreach (range(1, 4) as $i): ?>
                    <article class="bg-white rounded-xl overflow-hidden shadow-md border border-slate-200">
                        <img src="https://images.unsplash.com/photo-1516455590571-18256e5bb9ff?auto=format&fit=crop&w=700&q=80" alt="Floor Plan <?= $i ?>" class="w-full h-44 object-cover">
                        <div class="p-4">
                            <h3 class="font-bold text-slate-900">Tower Layout <?= $i ?></h3>
                            <button class="mt-3 w-full border border-emerald-700 text-emerald-700 hover:bg-emerald-50 rounded-md py-2 font-semibold">View Plan</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-20">
        <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <p class="section-label text-xs text-emerald-700 font-bold">VISUAL EXPERIENCE</p>
                <h2 class="display-font text-5xl font-semibold mt-2">Project Walkthrough</h2>
                <p class="mt-4 text-slate-600 leading-7">A planned neighborhood with future-ready infrastructure, elegant architecture and strong rental and appreciation potential.</p>
                <a href="#contact" class="inline-flex mt-6 bg-emerald-700 hover:bg-emerald-800 text-white px-6 py-3 rounded-md font-bold">SCHEDULE A WALKTHROUGH</a>
            </div>
            <img src="https://images.unsplash.com/photo-1460317442991-0ec209397118?auto=format&fit=crop&w=1200&q=80" alt="Walkthrough" class="w-full rounded-xl h-[380px] object-cover shadow-xl">
        </div>
    </section>

    <section id="location" class="py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-emerald-700 font-bold text-center">THE CENTER OF EVERYTHING</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">Unmatched Connectivity</h2>
            <p class="text-slate-600 text-center max-w-3xl mx-auto mt-4">Located in a high-growth corridor with quick access to transport, work hubs, education and healthcare.</p>
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 mt-12">
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="font-bold text-slate-900 mb-3">Connectivity</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <?php foreach (array_slice($locationAdvantages, 0, 4) as $item): ?>
                            <li>• <?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="font-bold text-slate-900 mb-3">Business Hubs</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li>• IT parks and startup hubs nearby</li>
                        <li>• Easy access to office corridors</li>
                        <li>• Direct route to CBD zones</li>
                    </ul>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="font-bold text-slate-900 mb-3">Education</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li>• Reputed schools in close radius</li>
                        <li>• Colleges and institutes nearby</li>
                        <li>• Coaching and learning centers</li>
                    </ul>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="font-bold text-slate-900 mb-3">Health & Retail</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li>• Multi-speciality hospitals</li>
                        <li>• Daily convenience stores</li>
                        <li>• Malls and high-street retail</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20">
        <div class="max-w-7xl mx-auto px-6">
            <p class="section-label text-xs text-emerald-700 font-bold text-center">GALLERY</p>
            <h2 class="display-font text-5xl font-semibold text-center mt-2">Visual Experience</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-12">
                <?php foreach ($galleryItems as $media): ?>
                    <?php $mediaUrl = to_public_media_url((string) $media['url']); ?>
                    <?php if ($media['type'] === 'video'): ?>
                        <video controls class="w-full h-56 object-cover rounded-lg shadow-sm hover:shadow-lg transition bg-black">
                            <source src="<?= e($mediaUrl) ?>">
                        </video>
                    <?php elseif ($media['type'] === 'embed'): ?>
                        <iframe src="<?= e($mediaUrl) ?>" title="Project video" class="w-full h-56 rounded-lg shadow-sm hover:shadow-lg transition" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                    <?php else: ?>
                        <img src="<?= e($mediaUrl) ?>" alt="Project gallery image" class="w-full h-56 object-cover rounded-lg shadow-sm hover:shadow-lg transition">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="contact" class="py-20 bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid lg:grid-cols-2 gap-10 items-center">
                <div>
                    <p class="section-label text-xs text-emerald-300 font-bold">ENQUIRE NOW</p>
                    <h2 class="display-font text-5xl font-semibold mt-2">Talk To Our Property Advisor</h2>
                    <p class="mt-4 text-slate-300">Get brochure, payment plans and site visit schedule instantly.</p>
                </div>
                <div class="bg-white text-slate-900 rounded-xl p-6">
                    <form id="projectLeadFormSecondary" class="space-y-3" onsubmit="document.getElementById('projectLeadForm').dispatchEvent(new Event('submit', {cancelable:true})); return false;">
                        <p class="text-sm text-slate-500">Use the top form in hero section to submit enquiry instantly.</p>
                        <a href="#home" class="inline-flex mt-2 bg-emerald-700 hover:bg-emerald-800 text-white px-5 py-2.5 rounded-md font-bold">GO TO ENQUIRY FORM</a>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-slate-950 text-slate-300 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-6 py-12 grid md:grid-cols-4 gap-8">
            <div>
                <h3 class="font-bold text-white"><?= e($appName) ?></h3>
                <p class="text-sm text-slate-400 mt-2">Elevating urban lifestyles with design-led residential communities.</p>
            </div>
            <div>
                <h4 class="font-semibold text-white mb-3">Quick Links</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#home" class="hover:text-emerald-300">Home</a></li>
                    <li><a href="#overview" class="hover:text-emerald-300">Overview</a></li>
                    <li><a href="#amenities" class="hover:text-emerald-300">Amenities</a></li>
                    <li><a href="#plans" class="hover:text-emerald-300">Floor Plans</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-white mb-3">Get In Touch</h4>
                <p class="text-sm text-slate-400"><?= e($project['location']) ?></p>
                <p class="text-sm mt-2"><a href="tel:<?= e(preg_replace('/\D+/', '', (string) $phone)) ?>" class="hover:text-emerald-300"><?= e($phone) ?></a></p>
            </div>
            <div>
                <h4 class="font-semibold text-white mb-3">Compliance</h4>
                <p class="text-xs text-slate-400">Visuals are illustrative only. Pricing and specifications may change without prior notice.</p>
            </div>
        </div>
        <div class="border-t border-slate-800 py-4 px-6 text-center text-sm text-slate-500">© 2026 <?= e($appName) ?>. All rights reserved.</div>
    </footer>

    <a href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener" class="fixed right-4 bottom-20 z-50 bg-green-500 hover:bg-green-600 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-xl" aria-label="WhatsApp">
        <i class="fa-brands fa-whatsapp text-2xl"></i>
    </a>
    <a href="tel:<?= e($phoneHref) ?>" class="fixed right-4 bottom-5 z-50 bg-sky-600 hover:bg-sky-700 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-xl" aria-label="Call">
        <i class="fa-solid fa-phone"></i>
    </a>

    <div id="successModal" class="hidden fixed inset-0 z-[100] bg-black/50 p-4 items-center justify-center">
        <div class="max-w-md w-full bg-white rounded-xl p-6 text-center">
            <h3 class="text-2xl font-bold">Thank You</h3>
            <p class="text-slate-600 mt-2">Your enquiry has been submitted successfully.</p>
            <button id="closeModal" class="mt-5 w-full bg-slate-900 text-white py-2 rounded-md">Close</button>
        </div>
    </div>

    <script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body>
</html>
