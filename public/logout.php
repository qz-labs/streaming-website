<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';

logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
