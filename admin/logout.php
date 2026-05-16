<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';

logout_user();
redirect('admin/login.php');
