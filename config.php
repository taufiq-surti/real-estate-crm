<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Paradise Group',
        'base_url' => 'http://localhost/paradisegroup',
        'timezone' => 'Asia/Kolkata',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'paradise_crm',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'contact' => [
        'default_phone' => '+91 9324724193',
        'default_whatsapp' => '919324724193',
        'admin_email' => 'admin@example.com',
    ],
    'crm' => [
        'auto_assign_leads' => true,
        'default_lead_status' => 'New',
    ],
    'smtp' => [
        'host' => 'smtp.hostinger.com',
        'port' => 587,
        'username' => 'noreply@example.com',
        'password' => 'CHANGE_ME',
        'from_email' => 'noreply@example.com',
        'from_name' => 'Paradise Group',
        'secure' => 'tls',
    ],
    'google_sheets' => [
        'enabled' => false,
        'spreadsheet_id' => 'CHANGE_ME',
        'sheet_name' => 'Leads',
        'service_account_email' => 'service-account@project-id.iam.gserviceaccount.com',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nCHANGE_ME\n-----END PRIVATE KEY-----\n",
    ],
    'security' => [
        'session_name' => 'paradisegroup_crm_session',
        'form_min_seconds' => 2,
        'lead_rate_limit_seconds' => 10,
    ],
];
