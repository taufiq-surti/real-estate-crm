<?php declare(strict_types=1);

function validate_gallery_file_extensions(string $field, array $allowedExt, string $label): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }

    $errorsOut = [];
    $names = $_FILES[$field]['name'];
    $errors = $_FILES[$field]['error'];

    foreach ($names as $index => $name) {
        $fileName = trim((string) $name);
        if ($fileName === '' || ($errors[$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errorsOut[] = $label . ' "' . $fileName . '" has unsupported file type (.' . $ext . '). Allowed: ' . implode(', ', $allowedExt) . '.';
        }
    }
    return $errorsOut;
}

require_once __DIR__ . '/../core/panel.php';
require_once __DIR__ . '/../core/db.php';

require_role('superadmin');

function ensure_projects_optional_columns(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columns = [
        'floor_plans' => 'TEXT NULL',
        'visual_experiences' => 'TEXT NULL',
        'theme_settings' => 'TEXT NULL',
    ];

    foreach ($columns as $column => $definition) {
        $check = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $check->execute([
            'table_name' => 'projects',
            'column_name' => $column,
        ]);
        $exists = (int) $check->fetchColumn() > 0;
        if (!$exists) {
            db()->exec('ALTER TABLE projects ADD COLUMN `' . $column . '` ' . $definition);
        }
    }

    $ensured = true;
}

function validate_single_image_upload(string $field, array $allowedExt, int $maxBytes, string $label): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $name = trim((string) ($_FILES[$field]['name'] ?? ''));
    if ($name === '') {
        return [];
    }

    $code = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_OK);
    if ($code === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if ($code !== UPLOAD_ERR_OK) {
        return [$label . ' upload failed (error code: ' . $code . ').'];
    }

    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return [$label . ' "' . $name . '" has unsupported file type (.' . $ext . '). Allowed: ' . implode(', ', $allowedExt) . '.'];
    }

    $size = (int) ($_FILES[$field]['size'] ?? 0);
    if ($maxBytes > 0 && $size > $maxBytes) {
        return [$label . ' "' . $name . '" is too large. Max allowed size is ' . (string) ceil($maxBytes / (1024 * 1024)) . 'MB.'];
    }

    return [];
}

function save_single_image_upload(string $field, array $allowedExt, string $targetDir): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $name = trim((string) ($_FILES[$field]['name'] ?? ''));
    $tmpName = (string) ($_FILES[$field]['tmp_name'] ?? '');
    $code = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_OK);
    if ($name === '' || $tmpName === '' || $code !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $uploadDir = __DIR__ . '/../' . trim($targetDir, '/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destination = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        return null;
    }

    return trim($targetDir, '/') . '/' . $fileName;
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

function sanitize_project_theme(array $input): array
{
    $defaults = default_project_theme();
    $output = $defaults;
    foreach ($defaults as $key => $fallback) {
        $output[$key] = normalize_theme_color((string) ($input[$key] ?? ''), $fallback);
    }

    return $output;
}

function parse_project_theme(?string $raw): array
{
    if (!$raw) {
        return default_project_theme();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return default_project_theme();
    }

    return sanitize_project_theme($decoded);
}

function render_theme_color_picker(string $key, string $label, string $value): void
{
    ?>
    <label class="block" data-color-picker>
        <span class="text-xs font-semibold text-slate-700"><?= e($label) ?></span>
        <div class="mt-1 flex items-center gap-2 rounded border border-slate-200 bg-white px-2 py-2">
            <input type="color" name="theme[<?= e($key) ?>]" value="<?= e($value) ?>" data-theme-color-input data-theme-key="<?= e($key) ?>" class="h-9 w-11 cursor-pointer rounded border border-slate-300 bg-transparent p-0">
            <input type="text" value="<?= e($value) ?>" data-theme-color-text data-theme-key="<?= e($key) ?>" class="w-full rounded border border-slate-200 px-2 py-1 text-xs text-slate-700" />
        </div>
    </label>
    <?php
}

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

function parse_ini_size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => (int) $value,
    };
}

function validate_gallery_video_uploads(string $field, int $maxBytes): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }

    $errorsOut = [];
    $names = $_FILES[$field]['name'];
    $errors = $_FILES[$field]['error'];
    $sizes = $_FILES[$field]['size'] ?? [];

    $serverLimit = parse_ini_size_to_bytes((string) ini_get('upload_max_filesize'));

    foreach ($names as $index => $name) {
        $fileName = trim((string) $name);
        if ($fileName === '') {
            continue;
        }

        $code = (int) ($errors[$index] ?? UPLOAD_ERR_OK);
        if ($code === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $limitMb = $serverLimit > 0 ? (string) ceil($serverLimit / (1024 * 1024)) : 'server';
            $errorsOut[] = 'Video "' . $fileName . '" exceeds upload limit (' . $limitMb . 'MB).';
            continue;
        }

        if ($code !== UPLOAD_ERR_OK) {
            $errorsOut[] = 'Video "' . $fileName . '" upload failed (error code: ' . $code . ').';
            continue;
        }

        $size = (int) ($sizes[$index] ?? 0);
        if ($maxBytes > 0 && $size > $maxBytes) {
            $maxMb = (string) ceil($maxBytes / (1024 * 1024));
            $errorsOut[] = 'Video "' . $fileName . '" is too large. Max allowed size is ' . $maxMb . 'MB.';
        }
    }

    return $errorsOut;
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

ensure_projects_optional_columns();

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
        if (!$existingProject) {
            $error = 'Unable to update project. The selected project was not found.';
        }
    }

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'Submitted data is too large. Please reduce upload size and try again.';
    }

    $name = trim((string) ($_POST['name'] ?? ($existingProject['name'] ?? '')));
    $slug = trim((string) ($_POST['slug'] ?? ($existingProject['slug'] ?? '')));
    $location = trim((string) ($_POST['location'] ?? ($existingProject['location'] ?? '')));
    $price = trim((string) ($_POST['price_range'] ?? ($existingProject['price_range'] ?? '')));
    $hero = trim((string) ($_POST['hero_image_existing'] ?? ($existingProject['hero_image'] ?? '')));
    $aboutImage = trim((string) ($_POST['about_image_existing'] ?? ($existingProject['about_image'] ?? '')));
    $aboutText = trim((string) ($_POST['about_text'] ?? ($existingProject['about_text'] ?? '')));
    $highlights = trim((string) ($_POST['highlights'] ?? implode(PHP_EOL, parse_text_list($existingProject['highlights'] ?? ''))));
    // Handle amenities as structured array with per-amenity image upload
    $amenitiesTitles = is_array($_POST['amenities_titles'] ?? null) ? $_POST['amenities_titles'] : [];
    $amenitiesExistingMedia = is_array($_POST['amenities_existing_media'] ?? null) ? $_POST['amenities_existing_media'] : [];
    $amenityExtErrors = [];
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
            foreach ($names as $i => $uploadName) {
                $uploadName = (string) $uploadName;
                if ($uploadName === '' || ($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($uploadName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $labelTitle = $title !== '' ? $title : 'Amenity';
                    $amenityExtErrors[] = $labelTitle . ' image "' . $uploadName . '" has unsupported file type (.' . $ext . '). Allowed: jpg, jpeg, png, webp, gif.';
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

    if (($_POST['hero_image_remove'] ?? '') === '1') {
        $hero = '';
    }
    if (($_POST['about_image_remove'] ?? '') === '1') {
        $aboutImage = '';
    }

    $floorPlanTitles = is_array($_POST['floor_plan_titles'] ?? null) ? $_POST['floor_plan_titles'] : [];
    $floorPlanDescriptions = is_array($_POST['floor_plan_descriptions'] ?? null) ? $_POST['floor_plan_descriptions'] : [];
    $floorPlanConfigurations = is_array($_POST['floor_plan_configurations'] ?? null) ? $_POST['floor_plan_configurations'] : [];
    $floorPlanAreas = is_array($_POST['floor_plan_areas'] ?? null) ? $_POST['floor_plan_areas'] : [];
    $floorPlanPrices = is_array($_POST['floor_plan_prices'] ?? null) ? $_POST['floor_plan_prices'] : [];
    $floorPlanExistingImages = is_array($_POST['floor_plan_existing_images'] ?? null) ? $_POST['floor_plan_existing_images'] : [];
    $floorPlanRemoveImage = is_array($_POST['floor_plan_remove_image'] ?? null) ? $_POST['floor_plan_remove_image'] : [];

    $visualTitles = is_array($_POST['visual_titles'] ?? null) ? $_POST['visual_titles'] : [];
    $visualDescriptions = is_array($_POST['visual_descriptions'] ?? null) ? $_POST['visual_descriptions'] : [];
    $visualExistingImages = is_array($_POST['visual_existing_images'] ?? null) ? $_POST['visual_existing_images'] : [];
    $visualRemoveImage = is_array($_POST['visual_remove_image'] ?? null) ? $_POST['visual_remove_image'] : [];

    $floorPlanItems = [];
    $visualItems = [];
    $floorVisualUploadErrors = [];

    foreach ($floorPlanTitles as $idx => $rowTitle) {
        $title = trim((string) $rowTitle);
        $description = trim((string) ($floorPlanDescriptions[$idx] ?? ''));
        $configuration = trim((string) ($floorPlanConfigurations[$idx] ?? ''));
        $area = trim((string) ($floorPlanAreas[$idx] ?? ''));
        $priceText = trim((string) ($floorPlanPrices[$idx] ?? ''));
        $image = trim((string) ($floorPlanExistingImages[$idx] ?? ''));
        if ((string) ($floorPlanRemoveImage[$idx] ?? '') === '1') {
            $image = '';
        }

        $field = 'floor_plan_image_' . $idx;
        $errorsOut = validate_single_image_upload($field, ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024, 'Floor plan image');
        if (!empty($errorsOut)) {
            $floorVisualUploadErrors = array_merge($floorVisualUploadErrors, $errorsOut);
        }

        $uploaded = save_single_image_upload($field, ['jpg', 'jpeg', 'png', 'webp'], 'assets/uploads/projects');
        if ($uploaded) {
            $image = $uploaded;
        }

        if ($title === '' && $description === '' && $configuration === '' && $area === '' && $priceText === '' && $image === '') {
            continue;
        }

        $floorPlanItems[] = [
            'title' => $title,
            'description' => $description,
            'configuration' => $configuration,
            'area' => $area,
            'price' => $priceText,
            'image' => $image,
        ];
    }

    foreach ($visualTitles as $idx => $rowTitle) {
        $title = trim((string) $rowTitle);
        $description = trim((string) ($visualDescriptions[$idx] ?? ''));
        $image = trim((string) ($visualExistingImages[$idx] ?? ''));
        if ((string) ($visualRemoveImage[$idx] ?? '') === '1') {
            $image = '';
        }

        $field = 'visual_image_' . $idx;
        $errorsOut = validate_single_image_upload($field, ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024, 'Visual experience image');
        if (!empty($errorsOut)) {
            $floorVisualUploadErrors = array_merge($floorVisualUploadErrors, $errorsOut);
        }

        $uploaded = save_single_image_upload($field, ['jpg', 'jpeg', 'png', 'webp'], 'assets/uploads/projects');
        if ($uploaded) {
            $image = $uploaded;
        }

        if ($title === '' && $description === '' && $image === '') {
            continue;
        }

        $visualItems[] = [
            'title' => $title,
            'description' => $description,
            'image' => $image,
        ];
    }

    if ($id > 0 && empty($floorPlanItems)) {
        $floorPlanItems = parse_structured_cards((string) ($existingProject['floor_plans'] ?? ''));
    }
    if ($id > 0 && empty($visualItems)) {
        $visualItems = parse_structured_cards((string) ($existingProject['visual_experiences'] ?? ''));
    }
    $galleryInput = trim((string) ($_POST['gallery_media'] ?? ''));
    $galleryEmbedInput = trim((string) ($_POST['gallery_embed_urls'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp_number'] ?? ($existingProject['whatsapp_number'] ?? '')));
    $phone = trim((string) ($_POST['phone_number'] ?? ($existingProject['phone_number'] ?? '')));
    $status = (int) ($_POST['status'] ?? 1);
    $themeInput = is_array($_POST['theme'] ?? null) ? $_POST['theme'] : [];
    $themeSettings = sanitize_project_theme($themeInput);

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


    $imageExtErrors = validate_gallery_file_extensions('gallery_images', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 'Gallery image');
    $videoExtErrors = validate_gallery_file_extensions('gallery_videos', ['mp4', 'webm', 'ogg'], 'Gallery video');
    $videoUploadErrors = validate_gallery_video_uploads('gallery_videos', 50 * 1024 * 1024);
    $heroUploadErrors = validate_single_image_upload('hero_image_upload', ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024, 'Hero image');
    $aboutUploadErrors = validate_single_image_upload('about_image_upload', ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024, 'About image');

    if ($error === '' && ($name === '' || $slug === '' || $location === '' || $price === '')) {
        $error = 'Name, slug, location and price are required.';
    } elseif ($error === '' && (!empty($heroUploadErrors) || !empty($aboutUploadErrors))) {
        $error = implode(' ', array_merge($heroUploadErrors, $aboutUploadErrors));
    } elseif ($error === '' && !empty($floorVisualUploadErrors)) {
        $error = implode(' ', $floorVisualUploadErrors);
    } elseif ($error === '' && !empty($amenityExtErrors)) {
        $error = implode(' ', $amenityExtErrors);
    } elseif ($error === '' && (!empty($imageExtErrors) || !empty($videoExtErrors))) {
        $error = implode(' ', array_merge($imageExtErrors, $videoExtErrors));
    } elseif ($error === '' && !empty($videoUploadErrors)) {
        $error = implode(' ', $videoUploadErrors);
    } elseif ($error === '') {
        $uploadedHero = save_single_image_upload('hero_image_upload', ['jpg', 'jpeg', 'png', 'webp'], 'assets/uploads/projects');
        if ($uploadedHero) {
            $hero = $uploadedHero;
        }

        $uploadedAbout = save_single_image_upload('about_image_upload', ['jpg', 'jpeg', 'png', 'webp'], 'assets/uploads/projects');
        if ($uploadedAbout) {
            $aboutImage = $uploadedAbout;
        }

        $stmt = $id > 0
            ? db()->prepare('UPDATE projects SET name=:name, slug=:slug, location=:location, price_range=:price, hero_image=:hero, about_image=:about_image, about_text=:about_text, highlights=:highlights, amenities=:amenities, location_advantages=:location_advantages, gallery=:gallery, floor_plans=:floor_plans, visual_experiences=:visual_experiences, theme_settings=:theme_settings, whatsapp_number=:whatsapp, phone_number=:phone, status=:status, updated_at=NOW() WHERE id=:id')
            : db()->prepare('INSERT INTO projects (name, slug, location, price_range, hero_image, about_image, about_text, highlights, amenities, location_advantages, gallery, floor_plans, visual_experiences, theme_settings, whatsapp_number, phone_number, status, created_at, updated_at) VALUES (:name, :slug, :location, :price, :hero, :about_image, :about_text, :highlights, :amenities, :location_advantages, :gallery, :floor_plans, :visual_experiences, :theme_settings, :whatsapp, :phone, :status, NOW(), NOW())');

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
            'floor_plans' => json_encode($floorPlanItems),
            'visual_experiences' => json_encode($visualItems),
            'theme_settings' => json_encode($themeSettings),
            'whatsapp' => $whatsapp,
            'phone' => $phone,
            'status' => $status,
        ];

        if ($id > 0) {
            $payload['id'] = $id;
        }

        try {
            $saved = $stmt->execute($payload);
            if ($saved) {
                redirect('superadmin/projects.php');
            }
            $error = 'Unable to save project changes. Please try again.';
        } catch (Throwable $e) {
            $error = 'Unable to save project changes. ' . $e->getMessage();
        }
    }
}

$editProject = null;
$editId = (int) ($_GET['edit'] ?? ($_POST['id'] ?? 0));
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editProject = $stmt->fetch() ?: null;
}

$projects = db()->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll();

$editGalleryEntries = normalize_gallery_entries((string) ($editProject['gallery'] ?? ''));
$editFloorPlans = parse_structured_cards((string) ($editProject['floor_plans'] ?? ''));
$editVisualExperiences = parse_structured_cards((string) ($editProject['visual_experiences'] ?? ''));
$editTheme = is_post()
    ? sanitize_project_theme(is_array($_POST['theme'] ?? null) ? $_POST['theme'] : [])
    : parse_project_theme((string) ($editProject['theme_settings'] ?? ''));

panel_header('Manage Projects');
?>
<div class="grid xl:grid-cols-3 gap-5">
    <div class="xl:col-span-1 bg-white border rounded-xl p-5">
        <h2 class="font-bold text-lg"><?= $editProject ? 'Edit Project' : 'Create Project' ?></h2>
        <?php if ($error): ?><div class="mt-3 p-2 text-sm rounded bg-red-100 text-red-700"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(base_url('superadmin/projects.php' . ($editId > 0 ? '?edit=' . $editId : ''))) ?>" enctype="multipart/form-data" class="space-y-3 mt-4">
            <input type="hidden" name="id" value="<?= (int) ($_POST['id'] ?? ($editProject['id'] ?? 0)) ?>">
            <input name="name" required placeholder="Project Name" value="<?= e($_POST['name'] ?? ($editProject['name'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="slug" required placeholder="project-slug" value="<?= e($_POST['slug'] ?? ($editProject['slug'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="location" required placeholder="Location" value="<?= e($_POST['location'] ?? ($editProject['location'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="price_range" required placeholder="Price Range" value="<?= e($_POST['price_range'] ?? ($editProject['price_range'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Hero Image</label>
                <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="hero_image_upload">
                    <?php $heroValue = trim((string) ($_POST['hero_image_existing'] ?? ($editProject['hero_image'] ?? ''))); ?>
                    <input type="hidden" name="hero_image_existing" value="<?= e($heroValue) ?>" data-existing-value>
                    <input type="hidden" name="hero_image_remove" value="0" data-remove-flag>
                    <input type="file" name="hero_image_upload" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                    <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                        <div class="text-2xl">&#8682;</div>
                        <p class="text-sm font-semibold text-slate-700">Drop image here or click to upload</p>
                        <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                        <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                    </div>
                    <div class="mt-3 <?= $heroValue === '' ? 'hidden' : '' ?>" data-preview-wrap>
                        <?php $heroSrc = $heroValue !== '' ? ((preg_match('/^https?:\/\//i', $heroValue) || str_starts_with($heroValue, '//')) ? $heroValue : base_url(ltrim($heroValue, '/'))) : ''; ?>
                        <img src="<?= e($heroSrc) ?>" alt="Hero preview" class="w-full h-40 object-cover rounded" data-preview-image>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                        <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                    </div>
                </div>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">About Image</label>
                <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="about_image_upload">
                    <?php $aboutValue = trim((string) ($_POST['about_image_existing'] ?? ($editProject['about_image'] ?? ''))); ?>
                    <input type="hidden" name="about_image_existing" value="<?= e($aboutValue) ?>" data-existing-value>
                    <input type="hidden" name="about_image_remove" value="0" data-remove-flag>
                    <input type="file" name="about_image_upload" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                    <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                        <div class="text-2xl">&#8682;</div>
                        <p class="text-sm font-semibold text-slate-700">Drop image here or click to upload</p>
                        <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                        <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                    </div>
                    <div class="mt-3 <?= $aboutValue === '' ? 'hidden' : '' ?>" data-preview-wrap>
                        <?php $aboutSrc = $aboutValue !== '' ? ((preg_match('/^https?:\/\//i', $aboutValue) || str_starts_with($aboutValue, '//')) ? $aboutValue : base_url(ltrim($aboutValue, '/'))) : ''; ?>
                        <img src="<?= e($aboutSrc) ?>" alt="About preview" class="w-full h-40 object-cover rounded" data-preview-image>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                        <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                    </div>
                </div>
            </div>
            <textarea name="about_text" placeholder="About text" class="w-full border rounded px-3 py-2 h-24"><?= e($_POST['about_text'] ?? ($editProject['about_text'] ?? '')) ?></textarea>
            <textarea name="highlights" placeholder="Highlights (one per line)" class="w-full border rounded px-3 py-2 h-24"><?= e($_POST['highlights'] ?? implode(PHP_EOL, parse_text_list($editProject['highlights'] ?? ''))) ?></textarea>
            <div id="amenities-list" class="space-y-3">
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
                <div class="amenity-row border rounded-lg p-3 bg-slate-50" data-amenity-index="<?= $i ?>">
                    <div class="grid grid-cols-2 items-center gap-2">
                        <input name="amenities_titles[]" value="<?= e($amenity['title']) ?>" placeholder="Amenity title" class="flex-1 border rounded px-3 py-2 bg-white" />
                        <input type="file" name="amenities_images_<?= $i ?>[]" multiple accept="image/*" class="border rounded px-3 py-2 bg-white text-sm" />
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2" data-amenity-preview></div>
                    <?php if (!empty($amenity['media'])): ?>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2">
                        <?php foreach ($amenity['media'] as $img): ?>
                            <?php if (is_string($img) && trim($img) !== ''): ?>
                                <?php
                                $imgPath = trim($img);
                                $imgSrc = (preg_match('/^https?:\/\//i', $imgPath) || str_starts_with($imgPath, '//'))
                                    ? $imgPath
                                    : base_url(ltrim($imgPath, '/'));
                                ?>
                                <div class="relative group border rounded overflow-hidden bg-white" data-existing-amenity-media>
                                    <input type="hidden" name="amenities_existing_media[<?= $i ?>][]" value="<?= e($imgPath) ?>">
                                    <img src="<?= e($imgSrc) ?>" alt="" class="w-full h-20 object-cover" />
                                    <button type="button" class="absolute top-1 right-1 bg-red-600 hover:bg-red-500 text-white text-xs px-2 py-0.5 rounded" data-remove-existing-amenity-media>Remove</button>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php } ?>
            </div>
            <button type="button" id="add-amenity-btn" class="bg-slate-200 hover:bg-slate-300 text-xs px-2 py-1 rounded">+ Add Amenity</button>

            <div class="space-y-3 border rounded-lg p-3 bg-slate-50">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-800">Floor Plans &amp; Layouts</h3>
                    <button type="button" id="add-floor-plan-btn" class="bg-slate-200 hover:bg-slate-300 text-xs px-2 py-1 rounded">+ Add Floor Plan</button>
                </div>
                <div id="floor-plans-list" class="space-y-3">
                    <?php
                    $floorPlanFormItems = [];
                    if (is_post() && is_array($_POST['floor_plan_titles'] ?? null)) {
                        foreach ($_POST['floor_plan_titles'] as $idx => $rowTitle) {
                            $floorPlanFormItems[] = [
                                'title' => trim((string) $rowTitle),
                                'description' => trim((string) ($_POST['floor_plan_descriptions'][$idx] ?? '')),
                                'configuration' => trim((string) ($_POST['floor_plan_configurations'][$idx] ?? '')),
                                'area' => trim((string) ($_POST['floor_plan_areas'][$idx] ?? '')),
                                'price' => trim((string) ($_POST['floor_plan_prices'][$idx] ?? '')),
                                'image' => trim((string) ($_POST['floor_plan_existing_images'][$idx] ?? '')),
                            ];
                        }
                    } elseif (!empty($editFloorPlans)) {
                        $floorPlanFormItems = $editFloorPlans;
                    }
                    if (empty($floorPlanFormItems)) {
                        $floorPlanFormItems[] = ['title' => '', 'description' => '', 'configuration' => '', 'area' => '', 'price' => '', 'image' => ''];
                    }
                    foreach ($floorPlanFormItems as $idx => $item):
                        $fpImage = trim((string) ($item['image'] ?? ''));
                        $fpSrc = $fpImage !== '' ? ((preg_match('/^https?:\/\//i', $fpImage) || str_starts_with($fpImage, '//')) ? $fpImage : base_url(ltrim($fpImage, '/'))) : '';
                    ?>
                    <div class="floor-plan-row border rounded-lg bg-white p-3" data-card-row="floor-plan">
                        <div class="flex items-start gap-3">
                            <img src="<?= e($fpSrc) ?>" alt="" class="w-20 h-16 rounded object-cover border <?= $fpImage === '' ? 'hidden' : '' ?>" data-floor-summary-image>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800" data-floor-summary-title><?= e((string) (($item['title'] ?? '') !== '' ? $item['title'] : 'Untitled Floor Plan')) ?></p>
                                <p class="text-xs text-slate-500 mt-1" data-floor-summary-meta>
                                    <?= e((string) (($item['configuration'] ?? '') !== '' ? $item['configuration'] : 'No configuration')) ?>
                                    <?= e((string) (($item['area'] ?? '') !== '' ? ' | ' . $item['area'] : '')) ?>
                                    <?= e((string) (($item['price'] ?? '') !== '' ? ' | ' . $item['price'] : '')) ?>
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-floor-edit-btn>Edit</button>
                                <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-row>Delete</button>
                            </div>
                        </div>
                        <div class="grid gap-2 mt-3" data-floor-form>
                            <input name="floor_plan_titles[]" value="<?= e((string) ($item['title'] ?? '')) ?>" placeholder="Floor Plan Title" class="w-full border rounded px-3 py-2" data-floor-input="title" />
                            <textarea name="floor_plan_descriptions[]" placeholder="Floor Plan Description" class="w-full border rounded px-3 py-2 h-20" data-floor-input="description"><?= e((string) ($item['description'] ?? '')) ?></textarea>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input name="floor_plan_configurations[]" value="<?= e((string) ($item['configuration'] ?? '')) ?>" placeholder="Configuration (e.g. 2 BHK)" class="w-full border rounded px-3 py-2" data-floor-input="configuration" />
                                <input name="floor_plan_areas[]" value="<?= e((string) ($item['area'] ?? '')) ?>" placeholder="Area/Size" class="w-full border rounded px-3 py-2" data-floor-input="area" />
                                <input name="floor_plan_prices[]" value="<?= e((string) ($item['price'] ?? '')) ?>" placeholder="Price (optional)" class="w-full border rounded px-3 py-2" data-floor-input="price" />
                            </div>
                            <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="floor_plan_image_<?= $idx ?>">
                                <input type="hidden" name="floor_plan_existing_images[]" value="<?= e($fpImage) ?>" data-existing-value>
                                <input type="hidden" name="floor_plan_remove_image[]" value="0" data-remove-flag>
                                <input type="file" name="floor_plan_image_<?= $idx ?>" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                                <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                                    <div class="text-2xl">&#8682;</div>
                                    <p class="text-sm font-semibold text-slate-700">Upload Floor Plan Image</p>
                                    <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                                    <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                                </div>
                                <div class="mt-3 <?= $fpImage === '' ? 'hidden' : '' ?>" data-preview-wrap>
                                    <img src="<?= e($fpSrc) ?>" alt="Floor plan preview" class="w-full h-32 object-cover rounded" data-preview-image>
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                                    <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="space-y-3 border rounded-lg p-3 bg-slate-50">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-800">Visual Experience</h3>
                    <button type="button" id="add-visual-btn" class="bg-slate-200 hover:bg-slate-300 text-xs px-2 py-1 rounded">+ Add Experience</button>
                </div>
                <div id="visual-list" class="space-y-3">
                    <?php
                    $visualFormItems = [];
                    if (is_post() && is_array($_POST['visual_titles'] ?? null)) {
                        foreach ($_POST['visual_titles'] as $idx => $rowTitle) {
                            $visualFormItems[] = [
                                'title' => trim((string) $rowTitle),
                                'description' => trim((string) ($_POST['visual_descriptions'][$idx] ?? '')),
                                'image' => trim((string) ($_POST['visual_existing_images'][$idx] ?? '')),
                            ];
                        }
                    } elseif (!empty($editVisualExperiences)) {
                        $visualFormItems = $editVisualExperiences;
                    }
                    if (empty($visualFormItems)) {
                        $visualFormItems[] = ['title' => '', 'description' => '', 'image' => ''];
                    }
                    foreach ($visualFormItems as $idx => $item):
                        $vxImage = trim((string) ($item['image'] ?? ''));
                        $vxSrc = $vxImage !== '' ? ((preg_match('/^https?:\/\//i', $vxImage) || str_starts_with($vxImage, '//')) ? $vxImage : base_url(ltrim($vxImage, '/'))) : '';
                    ?>
                    <div class="visual-row border rounded-lg bg-white p-3" data-card-row="visual">
                        <div class="grid gap-2">
                            <input name="visual_titles[]" value="<?= e((string) ($item['title'] ?? '')) ?>" placeholder="Experience Title" class="w-full border rounded px-3 py-2" />
                            <textarea name="visual_descriptions[]" placeholder="Experience Description" class="w-full border rounded px-3 py-2 h-20"><?= e((string) ($item['description'] ?? '')) ?></textarea>
                            <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="visual_image_<?= $idx ?>">
                                <input type="hidden" name="visual_existing_images[]" value="<?= e($vxImage) ?>" data-existing-value>
                                <input type="hidden" name="visual_remove_image[]" value="0" data-remove-flag>
                                <input type="file" name="visual_image_<?= $idx ?>" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                                <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                                    <div class="text-2xl">&#8682;</div>
                                    <p class="text-sm font-semibold text-slate-700">Upload Experience Image</p>
                                    <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                                    <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                                </div>
                                <div class="mt-3 <?= $vxImage === '' ? 'hidden' : '' ?>" data-preview-wrap>
                                    <img src="<?= e($vxSrc) ?>" alt="Visual preview" class="w-full h-32 object-cover rounded" data-preview-image>
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                                    <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                                    <button type="button" class="text-xs px-3 py-1 rounded bg-slate-200 hover:bg-slate-300 ml-auto" data-remove-row>Delete Card</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <textarea name="location_advantages" placeholder="Location advantages (one per line)" class="w-full border rounded px-3 py-2 h-24"><?= e($_POST['location_advantages'] ?? implode(PHP_EOL, parse_text_list($editProject['location_advantages'] ?? ''))) ?></textarea>
            <textarea name="gallery_media" placeholder="Gallery media URLs (images/videos). One per line or comma-separated." class="w-full border rounded px-3 py-2 h-24"><?= e($_POST['gallery_media'] ?? gallery_entries_to_text($editGalleryEntries)) ?></textarea>
            <textarea name="gallery_embed_urls" placeholder="Embed video URLs (YouTube/Vimeo), one per line" class="w-full border rounded px-3 py-2 h-20"><?= e($_POST['gallery_embed_urls'] ?? '') ?></textarea>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Upload Gallery Images</label>
                <input type="file" id="gallery-images-input" name="gallery_images[]" multiple accept="image/*" class="w-full border rounded px-3 py-2 bg-white">
                <div id="gallery-images-preview" class="grid grid-cols-2 sm:grid-cols-4 gap-2"></div>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Upload Gallery Videos</label>
                <input type="file" id="gallery-videos-input" name="gallery_videos[]" multiple accept="video/mp4,video/webm,video/ogg" class="w-full border rounded px-3 py-2 bg-white">
                <div id="gallery-videos-preview" class="grid grid-cols-1 sm:grid-cols-2 gap-2"></div>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700">Existing Gallery Media (Edit Mode)</label>
                <div id="existing-gallery-preview" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <?php foreach ($editGalleryEntries as $entry): ?>
                        <?php
                        $entryUrl = trim((string) ($entry['url'] ?? ''));
                        if ($entryUrl === '') {
                            continue;
                        }
                        $entryType = trim((string) ($entry['type'] ?? ''));
                        if ($entryType === '') {
                            $entryType = detect_gallery_type($entryUrl);
                        }
                        $entrySrc = (preg_match('/^https?:\/\//i', $entryUrl) || str_starts_with($entryUrl, '//'))
                            ? $entryUrl
                            : base_url(ltrim($entryUrl, '/'));
                        ?>
                        <div class="border rounded p-2 bg-white relative" data-existing-gallery-item data-gallery-url="<?= e($entryUrl) ?>" data-gallery-type="<?= e($entryType) ?>">
                            <?php if ($entryType === 'video'): ?>
                                <video src="<?= e($entrySrc) ?>" controls class="w-full h-36 object-cover rounded"></video>
                            <?php elseif ($entryType === 'embed'): ?>
                                <iframe src="<?= e(to_embed_url($entryUrl)) ?>" class="w-full h-36 rounded" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                            <?php else: ?>
                                <img src="<?= e($entrySrc) ?>" alt="" class="w-full h-36 object-cover rounded" />
                            <?php endif; ?>
                            <button type="button" class="absolute top-3 right-3 bg-red-600 hover:bg-red-500 text-white text-xs px-2 py-0.5 rounded" data-remove-existing-gallery>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <input name="whatsapp_number" placeholder="WhatsApp number e.g. 9198..." value="<?= e($_POST['whatsapp_number'] ?? ($editProject['whatsapp_number'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="phone_number" placeholder="Phone number" value="<?= e($_POST['phone_number'] ?? ($editProject['phone_number'] ?? '')) ?>" class="w-full border rounded px-3 py-2">

            <div class="space-y-3 border rounded-lg p-3 bg-slate-50" id="project-theme-config">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-800">Project Theme Customization</h3>
                    <span class="text-xs text-slate-500">Live Preview Enabled</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php
                    render_theme_color_picker('primary', 'Primary Color', $editTheme['primary']);
                    render_theme_color_picker('secondary', 'Secondary Color', $editTheme['secondary']);
                    render_theme_color_picker('background', 'Background Color', $editTheme['background']);
                    render_theme_color_picker('text', 'Text Color', $editTheme['text']);
                    render_theme_color_picker('navbar', 'Navbar Color', $editTheme['navbar']);
                    render_theme_color_picker('footer', 'Footer Color', $editTheme['footer']);
                    render_theme_color_picker('border', 'Border Color', $editTheme['border']);
                    render_theme_color_picker('card', 'Card Color', $editTheme['card']);
                    ?>
                </div>

                <div class="rounded-xl border p-3 shadow-sm transition-all duration-300" id="theme-live-preview" style="
                    --theme-primary: <?= e($editTheme['primary']) ?>;
                    --theme-secondary: <?= e($editTheme['secondary']) ?>;
                    --theme-background: <?= e($editTheme['background']) ?>;
                    --theme-text: <?= e($editTheme['text']) ?>;
                    --theme-navbar: <?= e($editTheme['navbar']) ?>;
                    --theme-footer: <?= e($editTheme['footer']) ?>;
                    --theme-border: <?= e($editTheme['border']) ?>;
                    --theme-card: <?= e($editTheme['card']) ?>;
                    background: var(--theme-background);
                    color: var(--theme-text);
                    border-color: var(--theme-border);
                ">
                    <div class="rounded-md border p-2" style="background: var(--theme-navbar); border-color: var(--theme-border);">
                        <div class="flex items-center justify-between">
                            <strong style="color: var(--theme-secondary);">Project Navbar</strong>
                            <a href="#" onclick="return false;" style="color: var(--theme-primary);">Theme Link</a>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <div class="rounded-md border p-3" style="background: var(--theme-card); border-color: var(--theme-border);">
                            <h4 class="font-semibold" style="color: var(--theme-secondary);">ThemePreview Card</h4>
                            <p class="text-xs mt-1">Buttons, cards, forms, borders and typography will follow these colors.</p>
                            <button type="button" class="mt-2 rounded px-3 py-1 text-xs text-white" style="background: var(--theme-primary);">Primary CTA</button>
                        </div>
                        <div class="rounded-md border p-3 text-white" style="background: var(--theme-footer); border-color: var(--theme-border);">
                            <h4 class="font-semibold">Footer Preview</h4>
                            <p class="text-xs opacity-90 mt-1">Footer and CTA sections adapt automatically.</p>
                        </div>
                    </div>
                </div>
            </div>

            <select name="status" class="w-full border rounded px-3 py-2">
                <option value="1" <?= ((int) ($_POST['status'] ?? ($editProject['status'] ?? 1)) === 1) ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= ((int) ($_POST['status'] ?? ($editProject['status'] ?? 1)) === 0) ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-2 rounded"><?= $editProject ? 'Update Project' : 'Create Project' ?></button>
        </form>

        <script>
        (function () {
            const galleryMediaTextarea = document.querySelector('textarea[name="gallery_media"]');
            const galleryEmbedTextarea = document.querySelector('textarea[name="gallery_embed_urls"]');
            const amenityList = document.getElementById('amenities-list');
            const addAmenityBtn = document.getElementById('add-amenity-btn');
            const floorPlansList = document.getElementById('floor-plans-list');
            const addFloorPlanBtn = document.getElementById('add-floor-plan-btn');
            const visualList = document.getElementById('visual-list');
            const addVisualBtn = document.getElementById('add-visual-btn');
            const projectForm = document.querySelector('form[enctype="multipart/form-data"]');

            const supportsManagedFiles = typeof DataTransfer !== 'undefined';
            const managedInputs = new Map();
            const maxImageBytes = 5 * 1024 * 1024;
            const allowedImageTypes = ['image/jpeg', 'image/png', 'image/webp'];

            function toFileKey(file) {
                return [file.name, file.size, file.type, file.lastModified].join('::');
            }

            function getManagedState(input) {
                if (!managedInputs.has(input)) {
                    managedInputs.set(input, { files: [] });
                }
                return managedInputs.get(input);
            }

            function syncInputFiles(input) {
                if (!supportsManagedFiles) {
                    return;
                }

                const state = getManagedState(input);
                const dt = new DataTransfer();
                state.files.forEach((file) => dt.items.add(file));
                input.files = dt.files;
            }

            function renderManagedPreview(input, previewContainer, kind) {
                const state = getManagedState(input);
                previewContainer.innerHTML = '';

                state.files.forEach((file) => {
                    const fileKey = toFileKey(file);
                    const card = document.createElement('div');
                    card.className = 'relative border rounded bg-white overflow-hidden';
                    card.dataset.fileKey = fileKey;

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'absolute top-1 right-1 z-10 bg-red-600 hover:bg-red-500 text-white text-xs px-2 py-0.5 rounded';
                    removeBtn.textContent = 'Remove';
                    removeBtn.addEventListener('click', function () {
                        state.files = state.files.filter((candidate) => toFileKey(candidate) !== fileKey);
                        syncInputFiles(input);
                        renderManagedPreview(input, previewContainer, kind);
                    });

                    const objectUrl = URL.createObjectURL(file);
                    if (kind === 'video') {
                        const video = document.createElement('video');
                        video.src = objectUrl;
                        video.controls = true;
                        video.className = 'w-full h-36 object-cover';
                        card.appendChild(video);
                    } else {
                        const img = document.createElement('img');
                        img.src = objectUrl;
                        img.alt = '';
                        img.className = 'w-full h-20 object-cover';
                        card.appendChild(img);
                    }

                    card.appendChild(removeBtn);
                    previewContainer.appendChild(card);
                });
            }

            function bindManagedInput(input, previewContainer, kind) {
                if (!input || !previewContainer) {
                    return;
                }

                getManagedState(input);
                input.addEventListener('change', function () {
                    const state = getManagedState(input);
                    const incoming = Array.from(input.files || []);
                    incoming.forEach((file) => {
                        if (!state.files.some((candidate) => toFileKey(candidate) === toFileKey(file))) {
                            state.files.push(file);
                        }
                    });

                    syncInputFiles(input);
                    renderManagedPreview(input, previewContainer, kind);
                });
            }

            function validateSingleImageFile(file) {
                if (!file) {
                    return 'Please select an image file.';
                }

                if (!allowedImageTypes.includes(file.type)) {
                    return 'Unsupported file type. Allowed: JPG, PNG, WEBP.';
                }

                if (file.size > maxImageBytes) {
                    return 'Image is too large. Max allowed size is 5MB.';
                }

                return '';
            }

            function initSingleUploadCard(card) {
                if (!card) {
                    return;
                }
                if (card.dataset.uploadBound === '1') {
                    return;
                }
                card.dataset.uploadBound = '1';

                const input = card.querySelector('[data-upload-input]');
                const dropzone = card.querySelector('[data-dropzone]');
                const previewWrap = card.querySelector('[data-preview-wrap]');
                const previewImage = card.querySelector('[data-preview-image]');
                const removeFlag = card.querySelector('[data-remove-flag]');
                const existingValue = card.querySelector('[data-existing-value]');
                const loading = card.querySelector('[data-upload-loading]');
                const replaceBtn = card.querySelector('[data-replace-upload]');
                const removeBtn = card.querySelector('[data-remove-upload]');

                function showPreview(file) {
                    if (!previewWrap || !previewImage) {
                        return;
                    }

                    previewImage.src = URL.createObjectURL(file);
                    previewWrap.classList.remove('hidden');
                }

                function handleIncomingFile(file) {
                    const validation = validateSingleImageFile(file);
                    if (validation) {
                        window.alert(validation);
                        if (input) {
                            input.value = '';
                        }
                        return;
                    }

                    if (removeFlag) {
                        removeFlag.value = '0';
                    }
                    if (existingValue) {
                        existingValue.value = '';
                    }
                    if (loading) {
                        loading.classList.remove('hidden');
                    }

                    showPreview(file);
                    if (loading) {
                        setTimeout(() => loading.classList.add('hidden'), 300);
                    }
                }

                if (dropzone && input) {
                    dropzone.addEventListener('click', function () {
                        input.click();
                    });

                    dropzone.addEventListener('dragover', function (event) {
                        event.preventDefault();
                        dropzone.classList.add('border-emerald-500', 'bg-emerald-50');
                    });

                    dropzone.addEventListener('dragleave', function () {
                        dropzone.classList.remove('border-emerald-500', 'bg-emerald-50');
                    });

                    dropzone.addEventListener('drop', function (event) {
                        event.preventDefault();
                        dropzone.classList.remove('border-emerald-500', 'bg-emerald-50');
                        const file = (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]) || null;
                        if (!file) {
                            return;
                        }

                        if (supportsManagedFiles) {
                            const dt = new DataTransfer();
                            dt.items.add(file);
                            input.files = dt.files;
                        }

                        handleIncomingFile(file);
                    });

                    input.addEventListener('change', function () {
                        const file = (input.files && input.files[0]) || null;
                        if (file) {
                            handleIncomingFile(file);
                        }
                    });
                }

                if (replaceBtn && input) {
                    replaceBtn.addEventListener('click', function () {
                        input.click();
                    });
                }

                if (removeBtn) {
                    removeBtn.addEventListener('click', function () {
                        if (input) {
                            input.value = '';
                        }
                        if (removeFlag) {
                            removeFlag.value = '1';
                        }
                        if (existingValue) {
                            existingValue.value = '';
                        }
                        if (previewImage) {
                            previewImage.src = '';
                        }
                        if (previewWrap) {
                            previewWrap.classList.add('hidden');
                        }
                    });
                }
            }

            function parseMediaTextarea(textarea) {
                if (!textarea) {
                    return [];
                }

                const lines = textarea.value.split(/\r\n|\r|\n/);
                const items = [];
                lines.forEach((line) => {
                    line.split(',').forEach((part) => {
                        const trimmed = part.trim();
                        if (trimmed !== '') {
                            items.push(trimmed);
                        }
                    });
                });

                return items;
            }

            function setMediaTextarea(textarea, items) {
                if (!textarea) {
                    return;
                }
                textarea.value = items.join('\n');
            }

            function removeUrlFromTextarea(textarea, url) {
                const normalizedUrl = (url || '').trim();
                if (normalizedUrl === '') {
                    return;
                }

                const current = parseMediaTextarea(textarea);
                const filtered = current.filter((item) => item !== normalizedUrl);
                setMediaTextarea(textarea, filtered);
            }

            function bindExistingAmenityRemove(scope) {
                const root = scope || document;
                root.querySelectorAll('[data-remove-existing-amenity-media]').forEach((button) => {
                    button.addEventListener('click', function () {
                        const card = button.closest('[data-existing-amenity-media]');
                        if (!card) {
                            return;
                        }
                        const hidden = card.querySelector('input[type="hidden"]');
                        if (hidden) {
                            hidden.remove();
                        }
                        card.remove();
                    });
                });
            }

            function bindExistingGalleryRemove() {
                document.querySelectorAll('[data-remove-existing-gallery]').forEach((button) => {
                    button.addEventListener('click', function () {
                        const card = button.closest('[data-existing-gallery-item]');
                        if (!card) {
                            return;
                        }

                        const url = (card.dataset.galleryUrl || '').trim();
                        const type = (card.dataset.galleryType || '').trim();
                        removeUrlFromTextarea(galleryMediaTextarea, url);
                        if (type === 'embed') {
                            removeUrlFromTextarea(galleryEmbedTextarea, url);
                            removeUrlFromTextarea(galleryEmbedTextarea, url.replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v='));
                        }
                        card.remove();
                    });
                });
            }

            function initAmenityRow(row) {
                const fileInput = row.querySelector('input[type="file"]');
                const preview = row.querySelector('[data-amenity-preview]');
                bindManagedInput(fileInput, preview, 'image');
                bindExistingAmenityRemove(row);
            }

            function bindRowRemove(scope) {
                (scope || document).querySelectorAll('[data-remove-row]').forEach((button) => {
                    button.addEventListener('click', function () {
                        const row = button.closest('[data-card-row]');
                        if (row) {
                            row.remove();
                        }
                    });
                });
            }

            function FloorPlanCard(row) {
                this.row = row;
                this.title = row.querySelector('[data-floor-summary-title]');
                this.meta = row.querySelector('[data-floor-summary-meta]');
                this.image = row.querySelector('[data-floor-summary-image]');
                this.editBtn = row.querySelector('[data-floor-edit-btn]');
                this.form = row.querySelector('[data-floor-form]');
            }

            FloorPlanCard.prototype.updateSummary = function () {
                const titleInput = this.row.querySelector('[data-floor-input="title"]');
                const cfgInput = this.row.querySelector('[data-floor-input="configuration"]');
                const areaInput = this.row.querySelector('[data-floor-input="area"]');
                const priceInput = this.row.querySelector('[data-floor-input="price"]');
                const previewImage = this.row.querySelector('[data-preview-image]');
                const previewWrap = this.row.querySelector('[data-preview-wrap]');

                const titleText = (titleInput && titleInput.value.trim()) || 'Untitled Floor Plan';
                const cfg = (cfgInput && cfgInput.value.trim()) || 'No configuration';
                const area = (areaInput && areaInput.value.trim()) || '';
                const price = (priceInput && priceInput.value.trim()) || '';
                const metaParts = [cfg];
                if (area !== '') {
                    metaParts.push(area);
                }
                if (price !== '') {
                    metaParts.push(price);
                }

                if (this.title) {
                    this.title.textContent = titleText;
                }
                if (this.meta) {
                    this.meta.textContent = metaParts.join(' | ');
                }

                if (this.image) {
                    if (previewWrap && !previewWrap.classList.contains('hidden') && previewImage && previewImage.src) {
                        this.image.src = previewImage.src;
                        this.image.classList.remove('hidden');
                    } else {
                        this.image.classList.add('hidden');
                    }
                }
            };

            FloorPlanCard.prototype.bind = function () {
                const refresh = () => this.updateSummary();
                this.row.querySelectorAll('[data-floor-input]').forEach((input) => {
                    input.addEventListener('input', refresh);
                });

                if (this.editBtn && this.form) {
                    this.editBtn.addEventListener('click', () => {
                        this.form.classList.toggle('hidden');
                        this.editBtn.textContent = this.form.classList.contains('hidden') ? 'Edit' : 'Close';
                    });
                }

                this.row.querySelectorAll('[data-replace-upload], [data-remove-upload], [data-upload-input]').forEach((el) => {
                    el.addEventListener('click', () => setTimeout(refresh, 50));
                    el.addEventListener('change', () => setTimeout(refresh, 50));
                });

                refresh();
            };

            function FloorPlanForm(idx) {
                this.idx = idx;
            }

            FloorPlanForm.prototype.render = function () {
                const row = document.createElement('div');
                row.className = 'floor-plan-row border rounded-lg bg-white p-3';
                row.dataset.cardRow = 'floor-plan';
                row.innerHTML = `
                    <div class="flex items-start gap-3">
                        <img src="" alt="" class="w-20 h-16 rounded object-cover border hidden" data-floor-summary-image>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-800" data-floor-summary-title>Untitled Floor Plan</p>
                            <p class="text-xs text-slate-500 mt-1" data-floor-summary-meta>No configuration</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-floor-edit-btn>Edit</button>
                            <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-row>Delete</button>
                        </div>
                    </div>
                    <div class="grid gap-2 mt-3" data-floor-form>
                        <input name="floor_plan_titles[]" placeholder="Floor Plan Title" class="w-full border rounded px-3 py-2" data-floor-input="title" />
                        <textarea name="floor_plan_descriptions[]" placeholder="Floor Plan Description" class="w-full border rounded px-3 py-2 h-20" data-floor-input="description"></textarea>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <input name="floor_plan_configurations[]" placeholder="Configuration (e.g. 2 BHK)" class="w-full border rounded px-3 py-2" data-floor-input="configuration" />
                            <input name="floor_plan_areas[]" placeholder="Area/Size" class="w-full border rounded px-3 py-2" data-floor-input="area" />
                            <input name="floor_plan_prices[]" placeholder="Price (optional)" class="w-full border rounded px-3 py-2" data-floor-input="price" />
                        </div>
                        <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="floor_plan_image_${this.idx}">
                            <input type="hidden" name="floor_plan_existing_images[]" value="" data-existing-value>
                            <input type="hidden" name="floor_plan_remove_image[]" value="0" data-remove-flag>
                            <input type="file" name="floor_plan_image_${this.idx}" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                            <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                                <div class="text-2xl">&#8682;</div>
                                <p class="text-sm font-semibold text-slate-700">Upload Floor Plan Image</p>
                                <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                                <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                            </div>
                            <div class="mt-3 hidden" data-preview-wrap>
                                <img src="" alt="Floor plan preview" class="w-full h-32 object-cover rounded" data-preview-image>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                                <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                            </div>
                        </div>
                    </div>
                `;
                return row;
            };

            function FloorPlansManager(listEl) {
                this.listEl = listEl;
            }

            FloorPlansManager.prototype.initRow = function (row) {
                initSingleUploadCard(row.querySelector('[data-single-upload]'));
                bindRowRemove(row);
                const card = new FloorPlanCard(row);
                card.bind();
            };

            FloorPlansManager.prototype.add = function () {
                if (!this.listEl) {
                    return;
                }
                const idx = this.listEl.querySelectorAll('[data-card-row="floor-plan"]').length;
                const form = new FloorPlanForm(idx);
                const row = form.render();
                this.listEl.appendChild(row);
                this.initRow(row);
            };

            function addFloorPlanRow() {
                floorPlansManager.add();
            }

            function addVisualRow() {
                if (!visualList) {
                    return;
                }
                const idx = visualList.querySelectorAll('[data-card-row="visual"]').length;
                const row = document.createElement('div');
                row.className = 'visual-row border rounded-lg bg-white p-3';
                row.dataset.cardRow = 'visual';
                row.innerHTML = `
                    <div class="grid gap-2">
                        <input name="visual_titles[]" placeholder="Experience Title" class="w-full border rounded px-3 py-2" />
                        <textarea name="visual_descriptions[]" placeholder="Experience Description" class="w-full border rounded px-3 py-2 h-20"></textarea>
                        <div class="border rounded-lg p-3 bg-slate-50" data-single-upload data-field-name="visual_image_${idx}">
                            <input type="hidden" name="visual_existing_images[]" value="" data-existing-value>
                            <input type="hidden" name="visual_remove_image[]" value="0" data-remove-flag>
                            <input type="file" name="visual_image_${idx}" accept="image/jpeg,image/png,image/webp" class="hidden" data-upload-input>
                            <div class="border-2 border-dashed border-slate-300 rounded-lg p-4 text-center bg-white cursor-pointer hover:border-emerald-500 transition" data-dropzone>
                                <div class="text-2xl">&#8682;</div>
                                <p class="text-sm font-semibold text-slate-700">Upload Experience Image</p>
                                <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP up to 5MB</p>
                                <p class="hidden text-xs text-emerald-700 mt-2" data-upload-loading>Uploading preview...</p>
                            </div>
                            <div class="mt-3 hidden" data-preview-wrap>
                                <img src="" alt="Visual preview" class="w-full h-32 object-cover rounded" data-preview-image>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button type="button" class="text-xs px-3 py-1 rounded bg-slate-800 text-white hover:bg-slate-700" data-replace-upload>Replace</button>
                                <button type="button" class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-500" data-remove-upload>Remove</button>
                                <button type="button" class="text-xs px-3 py-1 rounded bg-slate-200 hover:bg-slate-300 ml-auto" data-remove-row>Delete Card</button>
                            </div>
                        </div>
                    </div>
                `;
                visualList.appendChild(row);
                initSingleUploadCard(row.querySelector('[data-single-upload]'));
                bindRowRemove(row);
            }

            function addAmenityRow() {
                const idx = amenityList.querySelectorAll('.amenity-row').length;
                const row = document.createElement('div');
                row.className = 'amenity-row border rounded-lg p-3 bg-slate-50';
                row.dataset.amenityIndex = String(idx);
                row.innerHTML = `
                    <div class="grid grid-cols-2 items-center gap-2">
                        <input name="amenities_titles[]" placeholder="Amenity title" class="flex-1 border rounded px-3 py-2 bg-white" />
                        <input type="file" name="amenities_images_${idx}[]" multiple accept="image/*" class="border rounded px-3 py-2 bg-white text-sm" />
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2" data-amenity-preview></div>
                `;
                amenityList.appendChild(row);
                initAmenityRow(row);
            }

            function ThemePreview(previewEl) {
                this.previewEl = previewEl;
            }

            ThemePreview.prototype.update = function (themeState) {
                if (!this.previewEl) {
                    return;
                }
                Object.entries(themeState).forEach(([key, value]) => {
                    this.previewEl.style.setProperty('--theme-' + key, value);
                });
            };

            function ProjectThemeProvider(rootEl) {
                this.rootEl = rootEl;
                this.state = {
                    primary: '#059669',
                    secondary: '#0f766e',
                    background: '#f8fafc',
                    text: '#0f172a',
                    navbar: '#ffffff',
                    footer: '#020617',
                    border: '#cbd5e1',
                    card: '#ffffff',
                };
                this.preview = new ThemePreview(document.getElementById('theme-live-preview'));
            }

            ProjectThemeProvider.prototype.setColor = function (key, value) {
                if (!/^#[0-9a-fA-F]{6}$/.test(value)) {
                    return;
                }
                this.state[key] = value.toLowerCase();
                this.preview.update(this.state);
            };

            function ColorPicker(rootEl, provider) {
                this.rootEl = rootEl;
                this.provider = provider;
                this.colorInput = rootEl.querySelector('[data-theme-color-input]');
                this.textInput = rootEl.querySelector('[data-theme-color-text]');
                this.key = this.colorInput ? this.colorInput.dataset.themeKey : '';
            }

            ColorPicker.prototype.init = function () {
                if (!this.colorInput || !this.textInput || !this.key) {
                    return;
                }

                const syncFrom = (value) => {
                    const normalized = String(value || '').trim().toLowerCase();
                    if (!/^#[0-9a-f]{6}$/.test(normalized)) {
                        return;
                    }
                    this.colorInput.value = normalized;
                    this.textInput.value = normalized;
                    this.provider.setColor(this.key, normalized);
                };

                syncFrom(this.colorInput.value);

                this.colorInput.addEventListener('input', () => syncFrom(this.colorInput.value));
                this.textInput.addEventListener('change', () => syncFrom(this.textInput.value));
            };

            document.querySelectorAll('.amenity-row').forEach(initAmenityRow);
            document.querySelectorAll('[data-single-upload]').forEach(initSingleUploadCard);
            bindRowRemove(document);
            const floorPlansManager = new FloorPlansManager(floorPlansList);
            document.querySelectorAll('#floor-plans-list [data-card-row="floor-plan"]').forEach((row) => floorPlansManager.initRow(row));
            bindManagedInput(document.getElementById('gallery-images-input'), document.getElementById('gallery-images-preview'), 'image');
            bindManagedInput(document.getElementById('gallery-videos-input'), document.getElementById('gallery-videos-preview'), 'video');
            bindExistingGalleryRemove();

            if (addAmenityBtn) {
                addAmenityBtn.addEventListener('click', addAmenityRow);
            }
            if (addFloorPlanBtn) {
                addFloorPlanBtn.addEventListener('click', addFloorPlanRow);
            }
            if (addVisualBtn) {
                addVisualBtn.addEventListener('click', addVisualRow);
            }

            if (projectForm) {
                projectForm.addEventListener('submit', function () {
                    document.querySelectorAll('#floor-plans-list [data-upload-input]').forEach((input, idx) => {
                        input.name = 'floor_plan_image_' + idx;
                    });
                    document.querySelectorAll('#visual-list [data-upload-input]').forEach((input, idx) => {
                        input.name = 'visual_image_' + idx;
                    });
                });
            }

            const themeRoot = document.getElementById('project-theme-config');
            if (themeRoot) {
                const provider = new ProjectThemeProvider(themeRoot);
                themeRoot.querySelectorAll('[data-color-picker]').forEach((pickerEl) => {
                    const picker = new ColorPicker(pickerEl, provider);
                    picker.init();
                });
            }
        })();
        </script>
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
