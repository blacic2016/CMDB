<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

logout_user();

// Redirigir al index.php de la carpeta public
header('Location: index.php');
exit();