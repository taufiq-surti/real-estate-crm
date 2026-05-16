<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function get_google_access_token(): ?string
{
    if (!(bool) config('google_sheets.enabled', false)) {
        return null;
    }

    $serviceEmail = (string) config('google_sheets.service_account_email', '');
    $privateKey = (string) config('google_sheets.private_key', '');

    if ($serviceEmail === '' || $privateKey === '') {
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => $serviceEmail,
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ];

    $unsigned = base64url_encode(json_encode($header)) . '.' . base64url_encode(json_encode($payload));

    $signature = '';
    $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return null;
    }

    $jwt = $unsigned . '.' . base64url_encode($signature);

    $postData = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 12,
        ],
    ];

    $response = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create($opts));
    if ($response === false) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        return null;
    }

    return (string) $decoded['access_token'];
}

function append_lead_to_google_sheet(array $row): array
{
    if (!(bool) config('google_sheets.enabled', false)) {
        return ['success' => false, 'message' => 'Google Sheets disabled'];
    }

    $token = get_google_access_token();
    if (!$token) {
        return ['success' => false, 'message' => 'Access token failed'];
    }

    $spreadsheetId = (string) config('google_sheets.spreadsheet_id');
    $sheetName = (string) config('google_sheets.sheet_name', 'Leads');

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
        . '/values/' . rawurlencode($sheetName . '!A:I') . ':append?valueInputOption=RAW';

    $payload = json_encode([
        'values' => [[$row['date'], $row['name'], $row['mobile'], $row['email'], $row['city'], $row['message'], $row['project'], $row['assigned_agent'], $row['status']]],
    ]);

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 12,
        ],
    ];

    $response = @file_get_contents($url, false, stream_context_create($opts));
    if ($response === false) {
        return ['success' => false, 'message' => 'Append failed'];
    }

    return ['success' => true, 'message' => 'Synced'];
}
