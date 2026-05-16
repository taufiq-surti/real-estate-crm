<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';

require_login();
$user = current_user();
redirect($user['role'] === 'superadmin' ? 'superadmin/dashboard.php' : 'agent/dashboard.php');
