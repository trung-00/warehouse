<?php
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) {
    header('Location: /warehouse/pages/dashboard.php');
} else {
    header('Location: /warehouse/auth/login.php');
}
exit;
