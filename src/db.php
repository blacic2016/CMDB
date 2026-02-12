<?php
/**
 * Conexión a Base de Datos - CMDB VILASECA
 * Ubicación: /var/www/html/Sonda/src/db.php
 */

// Cargamos la configuración para acceder a DB_CONFIG
require_once __DIR__ . '/../config.php';

/**
 * Conexión PDO sin base de datos específica
 */
function getPDOWithoutDB()
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = "mysql:host=" . DB_CONFIG['host'] . ";charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5 // Timeout de 5 segundos para evitar bloqueos de red
        ]);
    } catch (PDOException $e) {
        // En lugar de exit, lanzamos una excepción que pueda ser capturada o logueada
        error_log("DB Connection Error: " . $e->getMessage());
        return null;
    }
    return $pdo;
}

/**
 * Conexión PDO con la base de datos CMDBVilaseca
 */
function getPDO()
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = "mysql:host=" . DB_CONFIG['host'] . ";dbname=" . DB_CONFIG['database'] . ";charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
    } catch (PDOException $e) {
        // Logueamos el error en storage/logs/api_errors.log según tu config.php
        error_log("DB (with DB) Connection Error: " . $e->getMessage());
        
        // Si la conexión falla, devolvemos null para que auth.php pueda manejarlo
        return null; 
    }
    return $pdo;
}