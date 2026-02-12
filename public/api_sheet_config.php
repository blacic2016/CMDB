<?php
/**
 * CMDB VILASECA - Controlador de Configuración de Tablas
 * Ubicación: /var/www/html/Sonda/public/api_config_sheets.php
 */

// --- 1. Configuración de Errores y Entorno ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../config.php';
ini_set('error_log', STORAGE_DIR . '/logs/api_errors.log');

// --- 2. Dependencias y Autenticación ---
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Validar que la respuesta sea JSON y que el usuario sea Super Admin
header('Content-Type: application/json');

if (!has_role(['SUPER_ADMIN'])) {
    http_response_code(403);
    exit(json_encode([
        'success' => false, 
        'error' => 'Permiso denegado. Se requiere nivel SUPER_ADMIN.'
    ]));
}

// --- 3. Procesamiento de la Solicitud ---
$action = $_POST['action'] ?? '';
$table = $_POST['table'] ?? '';

// Validar nombre de tabla (Función en helpers.php)
if (!isValidTableName($table)) {
    exit(json_encode(['success' => false, 'error' => 'Nombre de tabla inválido o inseguro.']));
}

$pdo = getPDO();

try {
    /**
     * ACCIÓN: SAVE - Guarda o actualiza las columnas únicas de una tabla
     */
    if ($action === 'save') {
        $cols = $_POST['cols'] ?? [];
        if (!is_array($cols)) {
            exit(json_encode(['success' => false, 'error' => 'El formato de columnas es inválido.']));
        }

        // Filtrar solo las columnas que realmente existen en la tabla física
        $existing_columns = getTableColumns($table);
        $clean_cols = [];
        foreach ($cols as $c) {
            if (in_array($c, $existing_columns, true)) {
                $clean_cols[] = $c;
            }
        }
        $json_columns = json_encode(array_values($clean_cols));

        // Lógica de UPSERT (Update o Insert)
        $stmt_check = $pdo->prepare("SELECT id FROM sheet_configs WHERE table_name = :t LIMIT 1");
        $stmt_check->execute([':t' => $table]);
        
        if ($stmt_check->fetchColumn()) {
            // Actualizar existente
            $u = $pdo->prepare("UPDATE sheet_configs SET unique_columns = :u, updated_at = NOW() WHERE table_name = :t");
            $u->execute([':u' => $json_columns, ':t' => $table]);
        } else {
            // Crear nueva configuración
            // Corregido: Usamos str_replace en lugar de regex inválida
            $sheetName = ucfirst(str_replace('sheet_', '', $table));
            
            $ins = $pdo->prepare("INSERT INTO sheet_configs (sheet_name, table_name, unique_columns, created_at) VALUES (:s, :t, :u, NOW())");
            $ins->execute([
                ':s' => $sheetName, 
                ':t' => $table, 
                ':u' => $json_columns
            ]);
        }

        exit(json_encode([
            'success' => true, 
            'message' => "Configuración de '{$table}' guardada con éxito."
        ]));
    }

    /**
     * ACCIÓN: DELETE - Elimina la configuración de una tabla
     */
    if ($action === 'delete') {
        $d = $pdo->prepare("DELETE FROM sheet_configs WHERE table_name = :t");
        $d->execute([':t' => $table]);
        
        exit(json_encode([
            'success' => true, 
            'message' => "Configuración de '{$table}' eliminada correctamente."
        ]));
    }

    // Acción por defecto
    exit(json_encode(['success' => false, 'error' => 'La acción solicitada no es válida.']));

} catch (PDOException $e) {
    // Registro del error en el log privado y respuesta limpia al usuario
    error_log("Error en api_config_sheets: " . $e->getMessage());
    exit(json_encode([
        'success' => false, 
        'error' => 'Error de Base de Datos (172.32.1.51): ' . $e->getMessage()
    ]));
} catch (Exception $e) {
    exit(json_encode([
        'success' => false, 
        'error' => 'Error General: ' . $e->getMessage()
    ]));
}