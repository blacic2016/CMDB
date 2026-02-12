<?php
/**
 * Archivo de Configuración Central - CMDB VILASECA
 * Ubicación: /var/www/html/Sonda/config.php
 */

// 1. Definición de Rutas del Sistema de Archivos (DEBE IR PRIMERO)
define('ROOT_PATH', __DIR__);
define('STORAGE_DIR', ROOT_PATH . '/storage');
define('UPLOAD_DIR_PUBLIC', ROOT_PATH . '/public/uploads');

// 2. Configuración de Errores (Ahora ya existe STORAGE_DIR)
ini_set('display_errors', 0); // Cambiar a 1 para debugear el NS_ERROR si persiste
ini_set('log_errors', 1);
ini_set('error_log', STORAGE_DIR . '/logs/api_errors.log');

// 3. Configuración de Base de Datos
define('DB_CONFIG', [
    'host'     => 'localhost',
    'user'     => 'root',
    'password' => 'zabbix',
    'database' => 'CMDBVilaseca'
]);

// 4. Configuración de Zabbix API
define('ZABBIX_API_URL', 'http://172.32.1.50/zabbix/api_jsonrpc.php');
define('ZABBIX_API_TOKEN', '23c5e835efd1c26742b6848ee63b2547ce5349efb88b4ecefee83fa27683cb9a');

// 5. Detección Dinámica de la URL (Web Paths)
if (!defined('PUBLIC_URL_PREFIX')) {
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $currentDir = str_replace('\\', '/', __DIR__);
    
    // Extraemos la subcarpeta (ej: /Sonda)
    $baseUrl = str_replace($docRoot, '', $currentDir);
    
    // Definimos el prefijo público para la web
    $public_prefix = rtrim($baseUrl, '/') . '/public';
    define('PUBLIC_URL_PREFIX', $public_prefix);
}

// URL para acceder a los archivos subidos
define('UPLOAD_URL_PUBLIC', PUBLIC_URL_PREFIX . '/uploads');

// 6. Configuraciones Adicionales
define('IMAGE_MAX_BYTES', 32 * 1024 * 1024);
date_default_timezone_set('America/Santiago');