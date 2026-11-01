<?php
require_once __DIR__ . '/../includes/auth.php';
session_destroy();
header('Location: /warehouse/auth/login.php?logout=1');
exit;
