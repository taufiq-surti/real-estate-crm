<?php

declare(strict_types=1);

require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/db.php';

if (file_exists(__DIR__ . '/.installed.lock')) {
    echo 'Application already installed. Delete .installed.lock to re-run installer.';
    exit;
}

try {
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Could not read schema file.');
    }

    db()->exec($schema);

    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE role = :role');
    $stmt->execute(['role' => 'superadmin']);

    if ((int) $stmt->fetchColumn() === 0) {
        $seedAdmin = db()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, is_active)
             VALUES (:name, :email, :phone, :password_hash, :role, 1)'
        );
        $seedAdmin->execute([
            'name' => 'Super Admin',
            'email' => 'admin@paradisegroup.local',
            'phone' => '9324724193',
            'password_hash' => $password,
            'role' => 'superadmin',
        ]);
    }

    $projectCount = (int) db()->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($projectCount === 0) {
        $insert = db()->prepare(
            'INSERT INTO projects
             (name, slug, location, price_range, hero_image, about_image, about_text, highlights, amenities, location_advantages, gallery, whatsapp_number, phone_number, status)
             VALUES
             (:name, :slug, :location, :price, :hero, :about_image, :about_text, :highlights, :amenities, :location_advantages, :gallery, :whatsapp, :phone, 1)'
        );

        $projects = [
            [
                'name' => 'Skyline Residences',
                'slug' => 'skyline-residences',
                'location' => 'Newtown, Kolkata',
                'price' => 'INR 58L - 1.2Cr',
                'hero' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1600&q=80',
                'about_image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80',
                'about_text' => 'Skyline Residences is a premium mixed-use community designed for modern urban families seeking luxury, connectivity, and wellness-focused living.',
                'highlights' => json_encode(['Metro access in 5 minutes', '70% open landscaped area', 'Smart-home ready apartments', 'Gated 24x7 security']),
                'amenities' => json_encode(['Clubhouse', 'Swimming Pool', 'Gymnasium', 'Jogging Track', 'Kids Play Zone', 'CCTV Security']),
                'location_advantages' => json_encode(['2.3 km from IT hub', 'Nearby hospitals and schools', 'Airport in 20 minutes', 'Shopping mall in 8 minutes']),
                'gallery' => json_encode([
                    'https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1613977257360-a9f6f93f85ad?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1600566752227-8f3b5d2f66f4?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1600607687644-c7f34b42f8a8?auto=format&fit=crop&w=1200&q=80'
                ]),
                'whatsapp' => '919324724193',
                'phone' => '+91 93247 24193',
            ],
            [
                'name' => 'Green Valley Villas',
                'slug' => 'green-valley-villas',
                'location' => 'Rajarhat, Kolkata',
                'price' => 'INR 1.3Cr - 2.6Cr',
                'hero' => 'https://images.unsplash.com/photo-1600607687644-c7f34b42f8a8?auto=format&fit=crop&w=1600&q=80',
                'about_image' => 'https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?auto=format&fit=crop&w=1200&q=80',
                'about_text' => 'Green Valley Villas offers signature plotted villas with private gardens, serene boulevard views, and curated wellness amenities.',
                'highlights' => json_encode(['Private garden villas', 'Close to business districts', 'Premium construction quality', 'Low-density master plan']),
                'amenities' => json_encode(['Infinity Pool', 'Yoga Deck', 'Banquet Hall', 'Amphitheatre', 'EV Charging', 'Multi-level Parking']),
                'location_advantages' => json_encode(['Expressway in 10 minutes', 'International school nearby', 'Prime retail district access', 'Upcoming metro corridor']),
                'gallery' => json_encode([
                    'https://images.unsplash.com/photo-1600573472592-401b489a3cdc?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1600607688066-c0d7f3ad87b2?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1600607688969-a5bfcd646154?auto=format&fit=crop&w=1200&q=80',
                    'https://images.unsplash.com/photo-1593696140826-c58b021acf8b?auto=format&fit=crop&w=1200&q=80'
                ]),
                'whatsapp' => '919324724193',
                'phone' => '+91 93247 24193',
            ],
        ];

        foreach ($projects as $project) {
            $insert->execute([
                'name' => $project['name'],
                'slug' => $project['slug'],
                'location' => $project['location'],
                'price' => $project['price'],
                'hero' => $project['hero'],
                'about_image' => $project['about_image'],
                'about_text' => $project['about_text'],
                'highlights' => $project['highlights'],
                'amenities' => $project['amenities'],
                'location_advantages' => $project['location_advantages'],
                'gallery' => $project['gallery'],
                'whatsapp' => $project['whatsapp'],
                'phone' => $project['phone'],
            ]);
        }
    }

    file_put_contents(__DIR__ . '/.installed.lock', date('c'));

    echo '<h2>Installation completed.</h2>';
    echo '<p>Super Admin Login:</p>';
    echo '<p>Email: <strong>admin@paradisegroup.local</strong><br>Password: <strong>admin123</strong></p>';
    echo '<p><a href="' . e(base_url('admin/login.php')) . '">Go to Login</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Installation failed</h3>';
    echo '<pre>' . e($e->getMessage()) . '</pre>';
}
