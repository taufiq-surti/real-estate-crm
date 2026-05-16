<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('superadmin');

function clean_media_token(string $value): string
{
    return trim($value, " \t\n\r\0\x0B\"'[]");
}

function normalize_gallery_entries(?string $raw): array
{
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    $entries = [];

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['url'])) {
                $url = clean_media_token((string) $item['url']);
                $type = trim((string) ($item['type'] ?? ''));
                if ($url !== '') {
                    $entries[] = ['type' => $type, 'url' => $url];
                }
                continue;
            }

            if (is_string($item)) {
                $parts = array_values(array_filter(array_map('clean_media_token', explode(',', $item))));
                foreach ($parts as $part) {
                    $entries[] = ['type' => '', 'url' => $part];
                }
            }
        }

        return $entries;
    }

    $rows = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    if (count($rows) <= 1 && str_contains($raw, ',')) {
        $rows = array_map('trim', explode(',', $raw));
    }

    foreach ($rows as $row) {
        $row = clean_media_token((string) $row);
        if ($row !== '') {
            $entries[] = ['type' => '', 'url' => $row];
        }
    }

    return $entries;
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

function save_uploaded_gallery_files(string $field, array $allowedExt): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }

    $output = [];
    $uploadDir = __DIR__ . '/../assets/uploads/projects';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $names = $_FILES[$field]['name'];
    $tmpNames = $_FILES[$field]['tmp_name'];
    $errors = $_FILES[$field]['error'];

    foreach ($names as $index => $name) {
        $name = (string) $name;
        if ($name === '') {
            continue;
        }

        if (($errors[$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $destination = $uploadDir . '/' . $fileName;

        if (move_uploaded_file((string) $tmpNames[$index], $destination)) {
            $output[] = 'assets/uploads/projects/' . $fileName;
        }
    }

    return $output;
}

function gallery_entries_to_text(array $entries): string
{
    $urls = [];
    foreach ($entries as $entry) {
        if (!empty($entry['url'])) {
            $urls[] = (string) $entry['url'];
        }
    }

    return implode(PHP_EOL, $urls);
}

function normalize_amenity_media(mixed $media): array
{
    $items = [];

    if (is_string($media)) {
        $parts = array_map('trim', explode(',', $media));
        foreach ($parts as $part) {
            if ($part !== '') {
                $items[] = $part;
            }
        }

        return $items;
    }

    if (!is_array($media)) {
        return [];
    }

    foreach ($media as $entry) {
        if (is_string($entry)) {
            $value = trim($entry);
            if ($value !== '') {
                $items[] = $value;
            }
            continue;
        }

        if (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
            $value = trim($entry['url']);
            if ($value !== '') {
                $items[] = $value;
            }
        }
    }

    return $items;
}

$error = '';
if (is_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($_GET['edit'] ?? 0);
    }
    $existingProject = null;
    if ($id > 0) {
        $existingStmt = db()->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $existingStmt->execute(['id' => $id]);
        $existingProject = $existingStmt->fetch() ?: null;
    }

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'Submitted data is too large. Please reduce upload size and try again.';
    }

    $name = trim((string) ($_POST['name'] ?? ($existingProject['name'] ?? '')));
    $slug = trim((string) ($_POST['slug'] ?? ($existingProject['slug'] ?? '')));
    $location = trim((string) ($_POST['location'] ?? ($existingProject['location'] ?? '')));
    $price = trim((string) ($_POST['price_range'] ?? ($existingProject['price_range'] ?? '')));
    $hero = trim((string) ($_POST['hero_image'] ?? ($existingProject['hero_image'] ?? '')));
    $aboutImage = trim((string) ($_POST['about_image'] ?? ($existingProject['about_image'] ?? '')));
    $aboutText = trim((string) ($_POST['about_text'] ?? ($existingProject['about_text'] ?? '')));
    $highlights = trim((string) ($_POST['highlights'] ?? implode(PHP_EOL, parse_text_list($existingProject['highlights'] ?? ''))));
    // Handle amenities as structured array with per-amenity image upload
    $amenitiesTitles = is_array($_POST['amenities_titles'] ?? null) ? $_POST['amenities_titles'] : [];
    $amenitiesExistingMedia = is_array($_POST['amenities_existing_media'] ?? null) ? $_POST['amenities_existing_media'] : [];
    $amenitiesData = [];
    foreach ($amenitiesTitles as $idx => $title) {
        $title = trim((string) $title);
        if ($title === '') {
            continue;
        }

        $existingMediaRaw = $amenitiesExistingMedia[$idx] ?? [];
        $media = normalize_amenity_media($existingMediaRaw);
        $field = 'amenities_images_' . $idx;
        if (isset($_FILES[$field]) && is_array($_FILES[$field]['name'])) {
            $uploadDir = __DIR__ . '/../assets/uploads/amenities';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $names = $_FILES[$field]['name'];
            $tmpNames = $_FILES[$field]['tmp_name'];
            $errors = $_FILES[$field]['error'];
            foreach ($names as $i => $name) {
                $name = (string) $name;
                if ($name === '' || ($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    continue;
                }
                $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                $destination = $uploadDir . '/' . $fileName;
                if (move_uploaded_file((string) $tmpNames[$i], $destination)) {
                    $media[] = 'assets/uploads/amenities/' . $fileName;
                }
            }
        }
        $amenitiesData[] = [
            'title' => $title,
            'media' => $media
        ];
    }
    if (empty($amenitiesData) && $id > 0 && isset($existingProject['amenities'])) {
        $amenities = (string) $existingProject['amenities'];
    } else {
        $amenities = (string) json_encode($amenitiesData);
    }

    $locationAdvantages = trim((string) ($_POST['location_advantages'] ?? implode(PHP_EOL, parse_text_list($existingProject['location_advantages'] ?? ''))));
    $galleryInput = trim((string) ($_POST['gallery_media'] ?? ''));
    $galleryEmbedInput = trim((string) ($_POST['gallery_embed_urls'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp_number'] ?? ($existingProject['whatsapp_number'] ?? '')));
    $phone = trim((string) ($_POST['phone_number'] ?? ($existingProject['phone_number'] ?? '')));
    $status = (int) ($_POST['status'] ?? 1);

    if ($id > 0 && $existingProject) {
        if ($name === '') {
            $name = trim((string) ($existingProject['name'] ?? ''));
        }
        if ($slug === '') {
            $slug = trim((string) ($existingProject['slug'] ?? ''));
        }
        if ($location === '') {
            $location = trim((string) ($existingProject['location'] ?? ''));
        }
        if ($price === '') {
            $price = trim((string) ($existingProject['price_range'] ?? ''));
        }
    }

    if ($error === '' && ($name === '' || $slug === '' || $location === '' || $price === '')) {
        $error = 'Name, slug, location and price are required.';
    } elseif ($error === '') {
        $stmt = $id > 0
            ? db()->prepare('UPDATE projects SET name=:name, slug=:slug, location=:location, price_range=:price, hero_image=:hero, about_image=:about_image, about_text=:about_text, highlights=:highlights, amenities=:amenities, location_advantages=:location_advantages, gallery=:gallery, whatsapp_number=:whatsapp, phone_number=:phone, status=:status, updated_at=NOW() WHERE id=:id')
            : db()->prepare('INSERT INTO projects (name, slug, location, price_range, hero_image, about_image, about_text, highlights, amenities, location_advantages, gallery, whatsapp_number, phone_number, status, created_at, updated_at) VALUES (:name, :slug, :location, :price, :hero, :about_image, :about_text, :highlights, :amenities, :location_advantages, :gallery, :whatsapp, :phone, :status, NOW(), NOW())');

        $textMediaEntries = [];
        $galleryRows = preg_split('/\r\n|\r|\n/', $galleryInput) ?: [];
        foreach ($galleryRows as $row) {
            $row = clean_media_token((string) $row);
            if ($row === '') {
                continue;
            }

            $urls = array_values(array_filter(array_map('clean_media_token', explode(',', $row))));
            foreach ($urls as $url) {
                $type = detect_gallery_type($url);
                $textMediaEntries[] = [
                    'type' => $type,
                    'url' => $type === 'embed' ? to_embed_url($url) : $url,
                ];
            }
        }

        $embedMediaEntries = [];
        $embedRows = preg_split('/\r\n|\r|\n/', $galleryEmbedInput) ?: [];
        foreach ($embedRows as $row) {
            $row = clean_media_token((string) $row);
            if ($row === '') {
                continue;
            }

            $embedMediaEntries[] = ['type' => 'embed', 'url' => to_embed_url($row)];
        }

        $imageUploads = save_uploaded_gallery_files('gallery_images', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $videoUploads = save_uploaded_gallery_files('gallery_videos', ['mp4', 'webm', 'ogg']);

        $uploadedImageEntries = array_map(static fn($url) => ['type' => 'image', 'url' => $url], $imageUploads);
        $uploadedVideoEntries = array_map(static fn($url) => ['type' => 'video', 'url' => $url], $videoUploads);

        $galleryEntries = array_merge($textMediaEntries, $embedMediaEntries, $uploadedImageEntries, $uploadedVideoEntries);

        if ($id > 0 && empty($galleryEntries)) {
            $existingStmt = db()->prepare('SELECT gallery FROM projects WHERE id = :id LIMIT 1');
            $existingStmt->execute(['id' => $id]);
            $existingRaw = (string) ($existingStmt->fetchColumn() ?: '');
            $galleryEntries = normalize_gallery_entries($existingRaw);
        }

        $galleryEntries = array_values(array_filter($galleryEntries, static fn($entry) => !empty($entry['url'])));

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'location' => $location,
            'price' => $price,
            'hero' => $hero,
            'about_image' => $aboutImage,
            'about_text' => $aboutText,
            'highlights' => json_encode(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $highlights) ?: [])))),
            'amenities' => $amenities,
            'location_advantages' => json_encode(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $locationAdvantages) ?: [])))),
            'gallery' => json_encode($galleryEntries),
            'whatsapp' => $whatsapp,
            'phone' => $phone,
            'status' => $status,
        ];

        if ($id > 0) {
            $payload['id'] = $id;
        }

        $stmt->execute($payload);
        redirect('superadmin/projects.php');
    }
}

$editProject = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editProject = $stmt->fetch() ?: null;
}

$projects = db()->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll();

$editGalleryEntries = normalize_gallery_entries((string) ($editProject['gallery'] ?? ''));

panel_header('Manage Projects');
?>
<div class="grid xl:grid-cols-3 gap-5">
    <div class="xl:col-span-1 bg-white border rounded-xl p-5">
        <h2 class="font-bold text-lg"><?= $editProject ? 'Edit Project' : 'Create Project' ?></h2>
        <?php if ($error): ?><div class="mt-3 p-2 text-sm rounded bg-red-100 text-red-700"><?= e($error) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="space-y-3 mt-4">
            <input type="hidden" name="id" value="<?= (int) ($editProject['id'] ?? 0) ?>">
            <input name="name" required placeholder="Project Name" value="<?= e($editProject['name'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="slug" required placeholder="project-slug" value="<?= e($editProject['slug'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="location" required placeholder="Location" value="<?= e($editProject['location'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="price_range" required placeholder="Price Range" value="<?= e($editProject['price_range'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="hero_image" placeholder="Hero image URL" value="<?= e($editProject['hero_image'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="about_image" placeholder="About image URL" value="<?= e($editProject['about_image'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <textarea name="about_text" placeholder="About text" class="w-full border rounded px-3 py-2 h-24"><?= e($editProject['about_text'] ?? '') ?></textarea>
            <textarea name="highlights" placeholder="Highlights (one per line)" class="w-full border rounded px-3 py-2 h-24"><?= e(implode(PHP_EOL, parse_text_list($editProject['highlights'] ?? ''))) ?></textarea>
            <div id="amenities-list" class="space-y-2">
                <?php
                $editAmenities = [];
                $rawAmenities = $editProject['amenities'] ?? '';
                $decodedAmenities = json_decode($rawAmenities, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmenities)) {
                    foreach ($decodedAmenities as $idx => $item) {
                        if (is_array($item) && isset($item['title'])) {
                            $editAmenities[] = [
                                'title' => trim((string) $item['title']),
                                'media' => normalize_amenity_media($item['media'] ?? [])
                            ];
                        } elseif (is_string($item)) {
                            $editAmenities[] = ['title' => trim($item), 'media' => []];
                        }
                    }
                } else {
                    $lines = preg_split('/\r\n|\r|\n/', (string) $rawAmenities) ?: [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $editAmenities[] = ['title' => $line, 'media' => []];
                        }
                    }
                }
                if (empty($editAmenities)) {
                    $editAmenities[] = ['title' => '', 'media' => []];
                }
                foreach ($editAmenities as $i => $amenity) {
                ?>
                <div class="flex items-center gap-2 amenity-row">
                    <input name="amenities_titles[]" value="<?= e($amenity['title']) ?>" placeholder="Amenity title" class="w-1/2 border rounded px-3 py-2" />
                    <input type="file" name="amenities_images_<?= $i ?>[]" multiple accept="image/*" class="w-1/2 border rounded px-3 py-2 bg-white" />
                    <?php if (!empty($amenity['media'])): ?>
                        <div class="flex gap-1 flex-wrap">
                        <?php foreach ($amenity['media'] as $img): ?>
                            <?php if (is_string($img) && trim($img) !== ''): ?>
                                <input type="hidden" name="amenities_existing_media[<?= $i ?>][]" value="<?= e($img) ?>">
                                <img src="<?= e(base_url($img)) ?>" alt="" class="w-10 h-10 object-cover rounded border" />
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php } ?>
            </div>
            <button type="button" onclick="addAmenityRow()" class="bg-slate-200 hover:bg-slate-300 text-xs px-2 py-1 rounded">+ Add Amenity</button>
            <script>
            function addAmenityRow() {
                const list = document.getElementById('amenities-list');
                const idx = list.children.length;
                const div = document.createElement('div');
                div.className = 'flex items-center gap-2 amenity-row';
                div.innerHTML = `<input name="amenities_titles[]" placeholder="Amenity title" class="flex-1 border rounded px-3 py-2" />
                    <input type="file" name="amenities_images_${idx}[]" multiple accept="image/*" class="border rounded px-3 py-2 bg-white" />`;
                list.appendChild(div);
            }
            </script>
            <textarea name="location_advantages" placeholder="Location advantages (one per line)" class="w-full border rounded px-3 py-2 h-24"><?= e(implode(PHP_EOL, parse_text_list($editProject['location_advantages'] ?? ''))) ?></textarea>
            <textarea name="gallery_media" placeholder="Gallery media URLs (images/videos). One per line or comma-separated." class="w-full border rounded px-3 py-2 h-24"><?= e(gallery_entries_to_text($editGalleryEntries)) ?></textarea>
            <textarea name="gallery_embed_urls" placeholder="Embed video URLs (YouTube/Vimeo), one per line" class="w-full border rounded px-3 py-2 h-20"></textarea>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Upload Gallery Images</label>
                <input type="file" name="gallery_images[]" multiple accept="image/*" class="w-full border rounded px-3 py-2 bg-white">
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Upload Gallery Videos</label>
                <input type="file" name="gallery_videos[]" multiple accept="video/mp4,video/webm,video/ogg" class="w-full border rounded px-3 py-2 bg-white">
            </div>
            <input name="whatsapp_number" placeholder="WhatsApp number e.g. 9198..." value="<?= e($editProject['whatsapp_number'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <input name="phone_number" placeholder="Phone number" value="<?= e($editProject['phone_number'] ?? '') ?>" class="w-full border rounded px-3 py-2">
            <select name="status" class="w-full border rounded px-3 py-2">
                <option value="1" <?= ((int) ($editProject['status'] ?? 1) === 1) ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= ((int) ($editProject['status'] ?? 1) === 0) ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-2 rounded"><?= $editProject ? 'Update Project' : 'Create Project' ?></button>
        </form>
    </div>

    <div class="xl:col-span-2 bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b font-bold">All Projects</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-3 py-2 text-left">Name</th>
                        <th class="px-3 py-2 text-left">Slug</th>
                        <th class="px-3 py-2 text-left">Location</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <tr class="border-t">
                            <td class="px-3 py-2"><?= e($project['name']) ?></td>
                            <td class="px-3 py-2"><?= e($project['slug']) ?></td>
                            <td class="px-3 py-2"><?= e($project['location']) ?></td>
                            <td class="px-3 py-2"><?= (int) $project['status'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td class="px-3 py-2">
                                <a class="text-cyan-700 font-semibold" href="<?= e(base_url('superadmin/projects.php?edit=' . $project['id'])) ?>">Edit</a>
                                <a class="ml-3 text-emerald-700 font-semibold" target="_blank" href="<?= e(base_url('project/' . $project['slug'])) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php panel_footer();
