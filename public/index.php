<?php
/**
 * CMDB VILASECA - Punto de Entrada (Dentro de /public)
 * Ubicación: /var/www/html/Sonda/public/index.php
 */

// 1. Subimos un nivel para cargar la configuración y la autenticación
// Usamos '../' porque el archivo está dentro de la carpeta /public
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../src/auth.php';

// 2. Iniciar la sesión para verificar el estado del usuario
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 3. Lógica de Redirección
 * Al estar ya dentro de /public, las redirecciones son locales a esta carpeta.
 */

if (current_user()) {
    // Si está logueado, va al dashboard
    // No necesitamos PUBLIC_URL_PREFIX aquí porque ya estamos en la carpeta public
    header('Location: dashboard.php');
} else {
    // Si no está logueado, va al login
    header('Location: login.php');
}

exit;