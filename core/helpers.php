<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = require __DIR__ . '/../config.php';
    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

    return $config;
}

function config(string $key, mixed $default = null): mixed
{
    $value = app_config();

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function base_url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_url', ''), '/');
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function validate_mobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
}

function lead_statuses(): array
{
    return ['New', 'Contacted', 'Follow-up', 'Closed'];
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Contacted' => 'bg-blue-100 text-blue-700',
        'Follow-up' => 'bg-amber-100 text-amber-700',
        'Closed' => 'bg-green-100 text-green-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string) config('security.session_name', 'crm_session'));
    session_start();
}

function generate_form_token(string $name): string
{
    ensure_session_started();

    $token = bin2hex(random_bytes(16));
    $_SESSION['form_tokens'][$name] = [
        'token' => $token,
        'created_at' => time(),
    ];

    return $token;
}

function verify_form_token(string $name, ?string $token): bool
{
    ensure_session_started();

    if ($token === null || $token === '') {
        return false;
    }

    $stored = $_SESSION['form_tokens'][$name] ?? null;
    if (!$stored || !hash_equals((string) $stored['token'], $token)) {
        return false;
    }

    $minSeconds = (int) config('security.form_min_seconds', 2);
    if ((time() - (int) $stored['created_at']) < $minSeconds) {
        return false;
    }

    unset($_SESSION['form_tokens'][$name]);

    return true;
}

function rate_limit_check(string $key, int $seconds): bool
{
    ensure_session_started();

    $now = time();
    $last = $_SESSION['rate_limit'][$key] ?? 0;

    if (($now - (int) $last) < $seconds) {
        return false;
    }

    $_SESSION['rate_limit'][$key] = $now;
    return true;
}

function parse_text_list(?string $value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = [];

        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
                continue;
            }

            if (is_array($entry) && isset($entry['title']) && is_string($entry['title'])) {
                $trimmed = trim($entry['title']);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
                continue;
            }

            if (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
                $trimmed = trim($entry['url']);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($items));
    }

    $normalized = trim($value);

    // Handle legacy data where values are stored as comma-separated strings.
    if (!str_contains($normalized, "\n") && str_contains($normalized, ',')) {
        $parts = explode(',', $normalized);
        return array_values(array_filter(array_map('trim', $parts)));
    }

    $lines = preg_split('/\r\n|\r|\n/', $normalized);
    return array_values(array_filter(array_map('trim', $lines ?: [])));
}

function whatsapp_link(string $number, string $text): string
{
    $clean = preg_replace('/\D+/', '', $number) ?? '';
    return 'https://wa.me/' . $clean . '?text=' . rawurlencode($text);
}
