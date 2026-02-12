<?php
require_once __DIR__ . '/../src/db.php';

echo "== Migración inicial: creando base de datos y tablas básicas ==\n";
$pdo = getPDOWithoutDB();
// Crear base de datos si no existe
$dbName = DB_CONFIG['database'];
$pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $dbName . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

echo "Base de datos '$dbName' creada o ya existente.\n";
$pdo = getPDO();

// Crear tablas base
$sqls = [
    // roles
    "CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // users
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // images
    "CREATE TABLE IF NOT EXISTS images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(100) NOT NULL,
        entity_id INT NOT NULL,
        filepath VARCHAR(255) NOT NULL,
        filename VARCHAR(255),
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // import logs
    "CREATE TABLE IF NOT EXISTS import_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        sheet_name VARCHAR(255),
        mode VARCHAR(20) NOT NULL,
        added_count INT DEFAULT 0,
        skipped_count INT DEFAULT 0,
        updated_count INT DEFAULT 0,
        errors TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // sheet configs
    "CREATE TABLE IF NOT EXISTS sheet_configs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_name VARCHAR(255) NOT NULL UNIQUE,
        table_name VARCHAR(255) NOT NULL UNIQUE,
        unique_columns TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // sheet history for row changes
    "CREATE TABLE IF NOT EXISTS sheet_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_table_name VARCHAR(255) NOT NULL,
        sheet_row_id INT NOT NULL,
        changed_by_user_id INT,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        old_values TEXT,
        new_values TEXT,
        KEY idx_sheet_row (sheet_table_name, sheet_row_id),
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Sequence for unique asset codes
    "CREATE TABLE IF NOT EXISTS asset_sequence (
        id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        prefix VARCHAR(10) NOT NULL DEFAULT 'AE',
        last_id INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

// Initialize the sequence if the table is empty
$pdo->exec("INSERT INTO asset_sequence (id, prefix, last_id) SELECT 1, 'AE', 0 FROM (SELECT COUNT(*) c FROM asset_sequence) t WHERE t.c = 0");


foreach ($sqls as $s) {
    try {
        $pdo->exec($s);
        // Extract table name for logging
        if (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $s, $matches)) {
            echo "Tabla '{$matches[1]}' creada o ya existente.\n";
        }
    } catch (PDOException $e) {
        echo "ERROR al ejecutar la consulta para la tabla '{$matches[1]}': " . $e->getMessage() . "\n";
        // Optionally, stop the script on first error
        // exit(1);
    }
}

echo "Tablas base procesadas.\n";

// ensure updated_count exists in import_logs for older installations
try {
    $pdo->exec("ALTER TABLE import_logs ADD COLUMN updated_count INT DEFAULT 0");
} catch (Exception $e) {
    // ignore if column already exists or on older MySQL without IF NOT EXISTS
}

// Insert default roles if not exists
$stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = :name");
foreach (['SUPER_ADMIN', 'ADMIN', 'USER'] as $r) {
    $stmt->execute([':name' => $r]);
    if ($stmt->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO roles (name) VALUES (:name)");
        $ins->execute([':name' => $r]);
        echo "Rol '$r' creado.\n";
    }
}

// Crear usuario super admin por defecto (cambiar contraseña luego)
$username = 'superadmin';
$password = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
// comprobar si existe
$ch = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
$ch->execute([':u' => $username]);
if ($ch->fetchColumn() == 0) {
    $roleIdStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'SUPER_ADMIN'");
    $roleIdStmt->execute();
    $roleId = $roleIdStmt->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO users (username, password, role_id) VALUES (:u, :p, :r)");
    $ins->execute([':u' => $username, ':p' => $password, ':r' => $roleId]);
    echo "Usuario 'superadmin' creado con contraseña por defecto. Por favor cámbiala.\n";
} else {
    echo "Usuario 'superadmin' ya existe.\n";
}

// Crear usuarios demo admin y user
$demoUsers = [
    ['username' => 'admin', 'password' => password_hash('AdminPass123!', PASSWORD_DEFAULT), 'role' => 'ADMIN'],
    ['username' => 'user', 'password' => password_hash('UserView123!', PASSWORD_DEFAULT), 'role' => 'USER'],
];
foreach ($demoUsers as $d) {
    $ch = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
    $ch->execute([':u' => $d['username']]);
    if ($ch->fetchColumn() == 0) {
        $roleIdStmt = $pdo->prepare("SELECT id FROM roles WHERE name = :r");
        $roleIdStmt->execute([':r' => $d['role']]);
        $roleId = $roleIdStmt->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO users (username, password, role_id) VALUES (:u, :p, :r)");
        $ins->execute([':u' => $d['username'], ':p' => $d['password'], ':r' => $roleId]);
        echo "Usuario '{$d['username']}' creado con rol {$d['role']}.\n";
    } else {
        echo "Usuario '{$d['username']}' ya existe.\n";
    }
}

echo "Migración finalizada.\n";
