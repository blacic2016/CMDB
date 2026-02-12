<?php
// --- Robust Error Handling & Logging ---
ini_set('display_errors', 0); // Disable public error reporting
ini_set('log_errors', 1);     // Enable error logging
$log_path =  '../storage/logs/api_errors.log';
ini_set('error_log', $log_path); // Set log file path

// Set a global error handler to catch fatal errors and return valid JSON
register_shutdown_function(function () use ($log_path) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Log the detailed error
        error_log(sprintf("Fatal Error: %s in %s on line %d", $error['message'], $error['file'], $error['line']));
        
        // Ensure we don't send headers that are already sent
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500); // Internal Server Error
        }
        // Attempt to clean any buffered output that might have happened before the error
        ob_get_level() > 0 && ob_end_clean();

        echo json_encode([
            'success' => false,
            'error' => 'Error fatal del servidor: ' . $error['message'],
            'details' => "File: {$error['file']}, Line: {$error['line']}"
        ]);
        exit();
    }
});

// Set a global exception handler to log uncaught exceptions
set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    ob_get_level() > 0 && ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Excepción no controlada: ' . $exception->getMessage()
    ]);
    exit();
});

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/zabbix_api.php';




if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

header('Content-Type: application/json');
$action = $_REQUEST['action'] ?? '';
$user = current_user();

if ($action === 'save_zabbix_cmdb_config') {
    if (!has_role(['SUPER_ADMIN'])) {
        exit(json_encode(['success' => false, 'error' => 'Permiso denegado.']));
    }

    $selected_tables = $_POST['tables'] ?? [];
    if (!is_array($selected_tables)) {
        exit(json_encode(['success' => false, 'error' => 'Datos inválidos.']));
    }

    $pdo = getPDO();
    try {
        $pdo->beginTransaction();

        // First, disable all tables
        $stmt_disable = $pdo->prepare("UPDATE zabbix_cmdb_config SET is_enabled = 0");
        $stmt_disable->execute();

        // Then, insert or update the selected tables to be enabled
        $stmt_upsert = $pdo->prepare(
            "INSERT INTO zabbix_cmdb_config (table_name, is_enabled) 
             VALUES (:table_name, 1) 
             ON DUPLICATE KEY UPDATE is_enabled = 1"
        );

        foreach ($selected_tables as $table) {
            if (isValidTableName($table)) { // Security check
                $stmt_upsert->execute([':table_name' => $table]);
            }
        }

        $pdo->commit();
        exit(json_encode(['success' => true]));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exit(json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]));
    }
}





if ($action === 'save_zabbix_mapping') {
    try {
        $pdo = getPDO();
        $table = $_POST['cmdb_table_name'] ?? '';
        
        if (empty($table)) {
            throw new Exception("Nombre de tabla no recibido.");
        }

        // 1. Procesar Inventario (inv_...)
        $inventory = [];
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'inv_') === 0 && !empty($val)) {
                $field_zabbix = str_replace('inv_', '', $key);
                $inventory[$field_zabbix] = $val;
            }
        }

        // 2. Procesar Tags Dinámicos
        $tag_names = $_POST['tag_names'] ?? [];
        $tag_cols = $_POST['tag_cols'] ?? [];
        $tags_final = [];
        for ($i = 0; $i < count($tag_names); $i++) {
            if (!empty(trim($tag_names[$i]))) {
                $tags_final[trim($tag_names[$i])] = $tag_cols[$i];
            }
        }

        // 3. SQL con todos los campos requeridos
        $sql = "INSERT INTO zabbix_mappings 
                (cmdb_table_name, hostname_template, visible_name_template, hostgroup_template, ip_field, snmp_community_field, template_name, inventory_fields_json, tags_json) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                hostname_template=VALUES(hostname_template), 
                visible_name_template=VALUES(visible_name_template), 
                hostgroup_template=VALUES(hostgroup_template), 
                ip_field=VALUES(ip_field), 
                snmp_community_field=VALUES(snmp_community_field), 
                template_name=VALUES(template_name), 
                inventory_fields_json=VALUES(inventory_fields_json), 
                tags_json=VALUES(tags_json)";
        
        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([
            $table, 
            $_POST['hostname_template'], 
            $_POST['visible_name_template'], 
            $_POST['hostgroup_template'], 
            $_POST['ip_field'], 
            $_POST['snmp_community_field'] ?: 'public', 
            $_POST['template_name'], 
            json_encode($inventory), 
            json_encode($tags_final) // Guardamos los tags procesados
        ]);

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        // Si hay error, lo devolvemos como JSON para que el JS lo capture
        error_log("Error en save_zabbix_mapping: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
if ($action === 'create_zabbix_host') {
    header('Content-Type: application/json');
    try {
        $pdo = getPDO();
        $table_name = $_POST['table_name'] ?? '';
        $row_id = filter_input(INPUT_POST, 'row_id', FILTER_VALIDATE_INT);

        if (!$table_name || !$row_id) throw new Exception("Faltan parámetros básicos.");

        // 1. OBTENER MAPEO
        $stmt_map = $pdo->prepare("SELECT * FROM zabbix_mappings WHERE cmdb_table_name = :tbl LIMIT 1");
        $stmt_map->execute([':tbl' => $table_name]);
        $mapping = $stmt_map->fetch(PDO::FETCH_ASSOC);

        if (!is_array($mapping)) {
            throw new Exception("Configuración de mapeo no encontrada para '$table_name'.");
        }

        // 2. OBTENER FILA DEL EQUIPO + DATA DE LOCALIDAD (JOIN)
        // Unimos con la tabla localidades usando 'sucursal' -> 'localidad'
        $sql_row = "
            SELECT e.*, l.latitud, l.longitud 
            FROM `$table_name` AS e
            LEFT JOIN sheet_localidades AS l ON e.sucursal = l.localidades
            WHERE e.id = :id
        ";
        $stmt_row = $pdo->prepare($sql_row);
        $stmt_row->execute([':id' => $row_id]);
        $row = $stmt_row->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new Exception("Equipo ID $row_id no encontrado.");

        // --- HELPER: PARSER ---
        $parser = function($text, $data) {
            return preg_replace_callback('/\{([^}]+)\}/', function($m) use ($data) {
                return (isset($data[$m[1]])) ? (string)$data[$m[1]] : '';
            }, (string)$text);
        };

        // 3. PROCESAR NOMBRES Y LIMPIEZA
        $h_raw = $parser($mapping['hostname_template'], $row);
        $v_raw = $parser($mapping['visible_name_template'], $row) ?: $h_raw;
        $visible_name = trim(str_replace(["\xc2\xa0", "\xa0"], ' ', $v_raw));
        $hostname = preg_replace('/[\s\x{00a0}]+/u', '_', $h_raw);
        $hostname = preg_replace('/[^A-Za-z0-9\.\-_]/', '', $hostname);
        $hostname = trim($hostname, '_');

        if (empty($hostname)) throw new Exception("Nombre técnico vacío tras limpieza.");

        // 4. DATOS DE RED Y GRUPO
        $hostgroup_name = $parser($mapping['hostgroup_template'], $row);
        $ip_final = $row[$mapping['ip_field']] ?? '';
        $community = $row[$mapping['snmp_community_field']] ?? 'public';

        if (empty($ip_final)) throw new Exception("IP no encontrada.");

        // 5. GESTIÓN DE GRUPO
        $group_res = call_zabbix_api('hostgroup.get', ['filter' => ['name' => $hostgroup_name]]);
        if (empty($group_res['result'])) {
            $g_create = call_zabbix_api('hostgroup.create', ['name' => $hostgroup_name]);
            $group_id = $g_create['result']['groupids'][0] ?? null;
        } else {
            $group_id = $group_res['result'][0]['groupid'];
        }

        // 6. GESTIÓN DE TEMPLATES
        $template_names = array_map('trim', explode(',', $mapping['template_name']));
        $tpl_res = call_zabbix_api('template.get', ['output' => ['templateid'], 'filter' => ['host' => $template_names]]);
        if (empty($tpl_res['result'])) throw new Exception("Templates no encontrados en Zabbix.");
        
        $template_ids_payload = array_map(function($t) { return ['templateid' => $t['templateid']]; }, $tpl_res['result']);

        // 7. PREPARAR INVENTARIO (Incluyendo Coordenadas del JOIN)
        $inventory_data = [];
        $saved_inv_mapping = json_decode($mapping['inventory_fields_json'] ?? '[]', true);
        foreach ($saved_inv_mapping as $z_field => $cmdb_col) {
            if (!empty($cmdb_col) && !empty($row[$cmdb_col])) {
                $inventory_data[$z_field] = (string)$row[$cmdb_col];
            }
        }
        // Inyectar coordenadas para los Geomaps de Zabbix
        if (!empty($row['latitud']))  $inventory_data['location_lat'] = (string)$row['latitud'];
        if (!empty($row['longitud'])) $inventory_data['location_lon'] = (string)$row['longitud'];

        // Tags Dinámicos
        $tags_data = [];
        $saved_tags_mapping = json_decode($mapping['tags_json'] ?? '[]', true);
        foreach ($saved_tags_mapping as $tag_n => $cmdb_c) {
            if (!empty($row[$cmdb_c])) {
                $tags_data[] = ['tag' => (string)$tag_n, 'value' => (string)$row[$cmdb_c]];
            }
        }

        // 8. CONSTRUCCIÓN DEL PAYLOAD FINAL (Macro SNMP + Inventario)
        $params = [
            'host' => $hostname,
            'name' => $visible_name,
            'interfaces' => [[
                'type' => 2, 'main' => 1, 'useip' => 1, 'ip' => $ip_final, 'dns' => '', 'port' => '161',
                'details' => [
                    'version' => 2, 
                    'community' => '{$SNMP_COMMUNITY}' // Asignación de la macro
                ]
            ]],
            'groups' => [['groupid' => $group_id]],
            'templates' => $template_ids_payload,
            'macros' => [[
                'macro' => '{$SNMP_COMMUNITY}',
                'value' => $community // Valor real de la comunidad
            ]],
            'inventory_mode' => 1, // 1 = Manual
            'inventory' => $inventory_data,
            'tags' => $tags_data
        ];

        // 9. LLAMADA ÚNICA A LA API
        $response = call_zabbix_api('host.create', $params);

        if (isset($response['error'])) {
            $err_msg = $response['error']['data'] ?? $response['error']['message'];
            throw new Exception("API Zabbix: " . $err_msg);
        }

        // 10. ACTUALIZAR CMDB
        $new_hostid = $response['result']['hostids'][0];
        $pdo->prepare("UPDATE `$table_name` SET zabbix_host_id = ? WHERE id = ?")->execute([$new_hostid, $row_id]);

        echo json_encode(['success' => true, 'log' => "Éxito: Creado como '$hostname' (ID: $new_hostid)"]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'log' => "ERROR: " . $e->getMessage()]);
    }
    exit;
}











if ($action === 'get_zabbix_mapping') {
    if (!has_role(['SUPER_ADMIN'])) {
        exit(json_encode(['success' => false, 'error' => 'Permiso denegado.']));
    }
    
    try {
        $table_name = $_POST['table_name'] ?? '';
        if (!isValidTableName($table_name)) {
            exit(json_encode(['success' => false, 'error' => 'Nombre de tabla inválido.']));
        }

        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM zabbix_mappings WHERE cmdb_table_name = :table_name LIMIT 1");
        $stmt->execute([':table_name' => $table_name]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        // Also get the columns for this table to populate dropdowns
        $columns = getTableColumns($table_name);
        exit(json_encode(['success' => false, 'error' => 'Error de Base de Datos: ' . $e->getMessage()]));
    } catch (Exception $e) {
        exit(json_encode(['success' => false, 'error' => 'Error General del Servidor: ' . $e->getMessage()]));
    }
}


if ($action === 'get_cmdb_data_for_zabbix') {
    if (!has_role(['ADMIN', 'SUPER_ADMIN'])) {
        exit(json_encode(['success' => false, 'error' => 'Permiso denegado.']));
    }
    
    try {
        require_once  '../src/zabbix_api.php';

        $tables = $_POST['tables'] ?? [];
        if (empty($tables) || !is_array($tables)) {
            exit(json_encode(['success' => false, 'error' => 'No se seleccionaron tablas.']));
        }

        $pdo = getPDO();
        $results = [];
        
        // Fetch all hosts from Zabbix once to avoid multiple API calls
        $zabbix_response = call_zabbix_api('host.get', [
            'output' => ['host', 'name', 'hostid'],
            'selectInterfaces' => ['ip']
        ]);
        if (isset($zabbix_response['error'])) {
            exit(json_encode(['success' => false, 'error' => $zabbix_response['error']]));
        }
        $all_zabbix_hosts = $zabbix_response['result'];

        // Create lookup maps for quick access
        $zabbix_host_map_by_name = [];
        $zabbix_host_map_by_ip = [];
        foreach ($all_zabbix_hosts as $host) {
            $zabbix_host_map_by_name[strtolower($host['host'])] = $host; // Hostname
            $zabbix_host_map_by_name[strtolower($host['name'])] = $host; // Visible Name
            foreach ($host['interfaces'] as $interface) {
                $zabbix_host_map_by_ip[$interface['ip']] = $host;
            }
        }

        // --- LOGGING POINT ---
        error_log("Acción 'get_cmdb_data_for_zabbix' iniciada. Tablas solicitadas: " . implode(', ', $tables));

        foreach ($tables as $table) {
            if (!isValidTableName($table)) {
                error_log("ADVERTENCIA: Nombre de tabla inválido omitido: {$table}");
                continue;
            }

            try {
                $stmt = $pdo->prepare("SELECT * FROM `$table` ");
		$stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $rowCount = count($rows);
                // --- LOGGING POINT ---
                error_log("Consultando tabla '{$table}': Se encontraron {$rowCount} filas.");

                if ($rowCount === 0) {
                    $results[$table] = ['columns' => [], 'rows' => []]; // Send empty result
                    continue;
                }

                $columns = array_keys($rows[0]); // Get columns from the first row
                $processed_rows = [];

                // Define which CMDB columns to check for hostnames and IPs. This should be configurable later.
                $name_candidates = ['nombre', 'hostname', 'device_name', 'name', 'ap'];
                $ip_candidates = ['ip', 'ip_address', 'direccion_ip'];

                foreach ($rows as $row) {
                    $status = 'No Monitoreado';
                    $zabbix_host_id = $row['zabbix_host_id'] ?? null;
                    $found_host = null;

                    // 1. Check by stored Zabbix Host ID first
                    if ($zabbix_host_id) {
                        $status = 'Monitoreado (ID)';
                    } else {
                        // 2. If no ID, check by name/hostname and IP
                        $cmdb_name = '';
                        foreach($name_candidates as $key) { if(!empty($row[$key])) { $cmdb_name = strtolower($row[$key]); break; } }

                        $cmdb_ip = '';
                        foreach($ip_candidates as $key) { if(!empty($row[$key])) { $cmdb_ip = $row[$key]; break; } }
                        
                        if (isset($zabbix_host_map_by_name[$cmdb_name])) {
                            $found_host = $zabbix_host_map_by_name[$cmdb_name];
                        } elseif (isset($zabbix_host_map_by_ip[$cmdb_ip]) && !empty($cmdb_ip)) {
                            $found_host = $zabbix_host_map_by_ip[$cmdb_ip];
                        }

                        if ($found_host) {
                            $status = 'Monitoreado';
                            // Auto-update zabbix_host_id in CMDB for future lookups
                            $update_stmt = $pdo->prepare("UPDATE `$table` SET zabbix_host_id = :hostid WHERE id = :rowid");
                            $update_stmt->execute([':hostid' => $found_host['hostid'], ':rowid' => $row['id']]);
                        }
                    }
                    $row['_zabbix_status'] = $status;
                    $processed_rows[] = $row;
                }
                $results[$table] = [
                    'columns' => $columns,
                    'rows' => $processed_rows
                ];
            } catch (PDOException $e) {
                // Log the error and continue to the next table if possible
                error_log("ERROR de base de datos al consultar la tabla '{$table}': " . $e->getMessage());
                // We can return an error for this specific table in the response
                $results[$table] = ['error' => 'No se pudo consultar la tabla: ' . $e->getMessage()];
            }
        }
        exit(json_encode(['success' => true, 'data' => $results]));

    } catch (PDOException $e) {
        // Catch database errors (e.g., table not found)
        exit(json_encode(['success' => false, 'error' => 'Error de Base de Datos: ' . $e->getMessage()]));
    } catch (Exception $e) {
        // Catch any other general errors
        exit(json_encode(['success' => false, 'error' => 'Error General del Servidor: ' . $e->getMessage()]));
    }
}

if ($action === 'delete') {
    if (!has_role(['ADMIN','SUPER_ADMIN'])) exit(json_encode(['success'=>false,'error'=>'Permiso denegado']));
    $table = $_POST['table'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!isValidTableName($table) || !$id) exit(json_encode(['success'=>false,'error'=>'Parametros invalidos']));
    $pdo = getPDO();
    // fetch row before delete for history
    try {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        // ensure history table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS sheet_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(255) NOT NULL,
            row_id INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            changed_by VARCHAR(255) DEFAULT NULL,
            old_data JSON DEFAULT NULL,
            new_data JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // insert history
        $h = $pdo->prepare('INSERT INTO sheet_history (table_name, row_id, action, changed_by, old_data) VALUES (:t,:rid,:a,:u,:o)');
        $h->execute([':t'=>$table, ':rid'=>$id, ':a'=>'delete', ':u'=>current_user()['username'] ?? null, ':o'=>json_encode($old)]);
    } catch (Exception $e) {
        // ignore history errors
    }
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = :id");
    $stmt->execute([':id'=>$id]);
    exit(json_encode(['success'=>true]));
}

if ($action === 'deactivate') {
    if (!has_role(['ADMIN','SUPER_ADMIN'])) exit(json_encode(['success'=>false,'error'=>'Permiso denegado']));
    $table = $_POST['table'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!isValidTableName($table) || !$id) exit(json_encode(['success'=>false,'error'=>'Parametros invalidos']));
    $pdo = getPDO();
    // Set estado_actual to 'NO_APARECE' or 'DANADO' as deactivation; we'll use 'NO_APARECE'
    $stmt = $pdo->prepare("UPDATE `$table` SET estado_actual = 'NO_APARECE' WHERE id = :id");
    $stmt->execute([':id'=>$id]);
    exit(json_encode(['success'=>true]));
}

if ($action === 'update') {
    if (!has_role(['ADMIN','SUPER_ADMIN'])) exit(json_encode(['success'=>false,'error'=>'Permiso denegado']));
    $table = $_POST['table'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!isValidTableName($table) || !$id) exit(json_encode(['success'=>false,'error'=>'Parametros invalidos']));
    
    $pdo = getPDO();
    $cols = getTableColumns($table);

    // Get current state of the row BEFORE update for history logging
    $stmt_select = $pdo->prepare("SELECT * FROM `$table` WHERE id = :id");
    $stmt_select->execute([':id' => $id]);
    $old_row = $stmt_select->fetch(PDO::FETCH_ASSOC);
    if (!$old_row) exit(json_encode(['success'=>false,'error'=>'El registro no existe.']));

    // Determine what changed
    $old_values = [];
    $new_values = [];
    foreach ($cols as $c) {
        if (in_array($c, ['id', '_row_hash', 'created_at', 'updated_at'])) continue;
        // Check if field was submitted and is different from the old value
        if (isset($_POST[$c]) && array_key_exists($c, $old_row) && $_POST[$c] != $old_row[$c]) {
            $old_values[$c] = $old_row[$c];
            $new_values[$c] = $_POST[$c];
        }
    }

    // Prepare update query only with submitted fields
    $sets = []; $params = [':id'=>$id];
    foreach ($_POST as $key => $value) {
        if (in_array($key, $cols) && !in_array($key, ['id','_row_hash','created_at','updated_at'])) {
            $sets[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($sets)) {
        exit(json_encode(['success'=>true, 'message'=>'No hay cambios que aplicar.']));
    }

    // Log to history table ONLY if there were actual changes
    if (!empty($new_values)) {
        try {
            $stmt_history = $pdo->prepare(
                "INSERT INTO sheet_history (sheet_table_name, sheet_row_id, changed_by_user_id, old_values, new_values) 
                 VALUES (:table_name, :row_id, :user_id, :old, :new)"
            );
            $stmt_history->execute([
                ':table_name' => $table,
                ':row_id' => $id,
                ':user_id' => current_user_id(),
                ':old' => json_encode($old_values, JSON_UNESCAPED_UNICODE),
                ':new' => json_encode($new_values, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Exception $e) {
            // optional: log history error to a file
        }
    }
    
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    exit(json_encode(['success'=>true]));
}

if ($action === 'create') {
    if (!has_role(['ADMIN','SUPER_ADMIN'])) exit(json_encode(['success'=>false,'error'=>'Permiso denegado']));
    $table = $_POST['table'] ?? '';
    if (!isValidTableName($table)) exit(json_encode(['success'=>false,'error'=>'Tabla invalida']));
    $cols = getTableColumns($table);
    $data = [];
    foreach ($cols as $c) {
        if (in_array($c, ['id','_row_hash','created_at','updated_at'])) continue;
        if (isset($_POST[$c]) && $_POST[$c] !== '') $data[$c] = $_POST[$c];
    }
    if (empty($data)) exit(json_encode(['success'=>false,'error'=>'Sin datos para insertar']));
    // compute _row_hash to match import logic
    $rowHash = hash('md5', json_encode(array_values($data)));
    $data['_row_hash'] = $rowHash;
    $colsInsert = array_keys($data);
    $placeholders = array_map(function($c){return ':'.$c;}, $colsInsert);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $colsInsert) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    $pdo = getPDO();
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($data as $k=>$v) $params[':'.$k]=$v;
    try { $stmt->execute($params); } catch (Exception $e){ exit(json_encode(['success'=>false,'error'=>$e->getMessage()])); }
    exit(json_encode(['success'=>true]));
}

if ($action === 'list_images') {
    $table = $_GET['table'] ?? '';
    $id = (int)($_GET['id'] ?? 0);
    if (!isValidTableName($table) || !$id) exit(json_encode([]));
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT id, filepath, filename, uploaded_at FROM images WHERE entity_type = :t AND entity_id = :id ORDER BY uploaded_at DESC");
    $stmt->execute([':t'=>$table, ':id'=>$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    exit(json_encode($rows));
}

if ($action === 'delete_image') {
    if (!has_role(['ADMIN','SUPER_ADMIN'])) exit(json_encode(['success'=>false,'error'=>'Permiso denegado']));
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) exit(json_encode(['success'=>false,'error'=>'Id invalido']));
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT filepath FROM images WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $fp = $stmt->fetchColumn();
    if (!$fp) exit(json_encode(['success'=>false,'error'=>'Imagen no encontrada']));
    // remove file if exists
    $full = '../' . ltrim($fp, '/');
    try {
        if (is_file($full)) @unlink($full);
    } catch (Exception $e) {
        // ignore
    }
    $d = $pdo->prepare("DELETE FROM images WHERE id = :id");
    $d->execute([':id' => $id]);
    exit(json_encode(['success'=>true]));
}


if ($action === 'get_mapping_form') {
    $table = $_GET['table'] ?? '';
    if (!isValidTableName($table)) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'error' => 'Tabla no válida']));
    }

    $columns = getTableColumns($table); 
    if (empty($columns)) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'error' => 'La tabla está vacía.']));
    }

    header('Content-Type: text/html; charset=utf-8');

    // Recuperar mapeo previo
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM zabbix_mappings WHERE cmdb_table_name = ?");
    $stmt->execute([$table]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $saved_inv = json_decode($saved['inventory_fields_json'] ?? '[]', true);
    $saved_tags = json_decode($saved['tags_json'] ?? '[]', true);

    $zabbix_inventory = [
        'type' => 'Tipo (Type)', 'os' => 'S.O / Firmware', 'hardware' => 'Hardware',
        'serialno_a' => 'S/N Principal', 'location' => 'Ubicación', 'vendor' => 'Fabricante'
    ];
    ?>
    <form id="mapping-form">
        <input type="hidden" name="cmdb_table_name" value="<?= htmlspecialchars($table) ?>">
        
        <div class="card card-primary card-outline mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Identidad y Grupos</h3></div>
            <div class="card-body row">
                <div class="col-md-6 mb-3">
                    <label>Hostname (Zabbix Name)</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="hostname_template" id="h_temp" class="form-control" placeholder="{serial_number}" value="<?= htmlspecialchars($saved['hostname_template'] ?? '') ?>" required>
                        <select class="form-control col-4 btn-append-val" data-target="#h_temp">
                            <option value="">+ Columna</option>
                            <?php foreach($columns as $c) echo "<option value='{$c}'>$c</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <label>Visible Name</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="visible_name_template" id="v_temp" class="form-control" placeholder="MON-{hostname}" value="<?= htmlspecialchars($saved['visible_name_template'] ?? '') ?>">
                        <select class="form-control col-4 btn-append-val" data-target="#v_temp">
                            <option value="">+ Columna</option>
                            <?php foreach($columns as $c) echo "<option value='{$c}'>$c</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-12">
                    <label>Host Group Template</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="hostgroup_template" id="g_temp" class="form-control" placeholder="Infra/{sucursal}" value="<?= htmlspecialchars($saved['hostgroup_template'] ?? '') ?>" required>
                        <select class="form-control col-2 btn-append-val" data-target="#g_temp">
                            <option value="">+ Columna</option>
                            <?php foreach($columns as $c) echo "<option value='{$c}'>$c</option>"; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-info card-outline mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-network-wired mr-2"></i>Conectividad y Template</h3></div>
            <div class="card-body row">
                <div class="col-md-4 mb-2">
                    <label>Columna IP Gestión</label>
                    <select name="ip_field" class="form-control form-control-sm select2-mapping">
                        <?php foreach($columns as $c) : ?>
                            <option value="<?= $c ?>" <?= ($saved['ip_field'] ?? '') == $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-2">
                    <label>Columna SNMP Community</label>
                    <select name="snmp_community_field" class="form-control form-control-sm select2-mapping">
                        <option value="">-- public --</option>
                        <?php foreach($columns as $c) : ?>
                            <option value="<?= $c ?>" <?= ($saved['snmp_community_field'] ?? '') == $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-2">
    <label>Nombres de Templates (separados por coma)</label>
    <input type="text" name="template_name" class="form-control form-control-sm" 
           placeholder="Template 1, Template 2" 
           value="<?= htmlspecialchars($saved['template_name'] ?? '') ?>" required>
    <small class="text-muted">Ej: Template Net Cisco, ICMP Ping</small>
</div>
            </div>
        </div>

        <div class="card card-secondary card-outline mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Inventario Zabbix</h3></div>
            <div class="card-body row">
                <?php foreach ($zabbix_inventory as $field => $label): ?>
                <div class="col-md-4 mb-2">
                    <label class="small"><?= $label ?></label>
                    <select name="inv_<?= $field ?>" class="form-control form-control-sm select2-mapping">
                        <option value="">-- Ignorar --</option>
                        <?php foreach($columns as $c): ?>
                            <option value="<?= $c ?>" <?= ($saved_inv[$field] ?? '') == $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card card-dark card-outline">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags mr-2"></i>Tags de Host</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" id="add-tag-row"><i class="fas fa-plus"></i> Añadir Tag</button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" id="tags-table">
                    <thead>
                        <tr>
                            <th>Nombre del Tag</th>
                            <th>Valor (Columna CMDB)</th>
                            <th style="width: 40px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($saved_tags)): foreach($saved_tags as $tag_name => $col_val): ?>
                        <tr>
                            <td><input type="text" name="tag_names[]" class="form-control form-control-sm" value="<?= htmlspecialchars($tag_name) ?>"></td>
                            <td>
                                <select name="tag_cols[]" class="form-control form-control-sm">
                                    <?php foreach($columns as $c) echo "<option value='$c' ".($col_val == $c ? 'selected' : '').">$c</option>"; ?>
                                </select>
                            </td>
                            <td><button type="button" class="btn btn-xs btn-danger remove-tag"><i class="fas fa-times"></i></button></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td><input type="text" name="tag_names[]" class="form-control form-control-sm" placeholder="Ej: Pais"></td>
                            <td>
                                <select name="tag_cols[]" class="form-control form-control-sm">
                                    <?php foreach($columns as $c) echo "<option value='$c' ".($c == 'pais' ? 'selected' : '').">$c</option>"; ?>
                                </select>
                            </td>
                            <td><button type="button" class="btn btn-xs btn-danger remove-tag"><i class="fas fa-times"></i></button></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <script>
        // Inyectar columnas en campos de texto
        $('.btn-append-val').on('change', function() {
            const col = $(this).val();
            const target = $(this).data('target');
            if(col) {
                $(target).val($(target).val() + '{' + col + '}');
                $(this).val('');
            }
        });

        // Agregar fila de Tag
        $('#add-tag-row').on('click', function() {
            const row = `<tr>
                <td><input type="text" name="tag_names[]" class="form-control form-control-sm"></td>
                <td>
                    <select name="tag_cols[]" class="form-control form-control-sm">
                        <?php foreach($columns as $c) echo "<option value='$c'>$c</option>"; ?>
                    </select>
                </td>
                <td><button type="button" class="btn btn-xs btn-danger remove-tag"><i class="fas fa-times"></i></button></td>
            </tr>`;
            $('#tags-table tbody').append(row);
        });

        $(document).on('click', '.remove-tag', function() { $(this).closest('tr').remove(); });

        $('.select2-mapping').select2({ width: '100%', dropdownParent: $('#zabbixMappingModal') });
    </script>
    <?php
    exit;
}

if ($action === 'delete_zabbix_host') {
    header('Content-Type: application/json');
    try {
        $pdo = getPDO();
        $table_name = $_POST['table_name'] ?? '';
        $row_id = (int)($_POST['row_id'] ?? 0);
        $zabbix_host_id = $_POST['zabbix_host_id'] ?? '';

        if (!$table_name || !$row_id || !$zabbix_host_id) {
            throw new Exception("Faltan parámetros para la eliminación.");
        }

        // 1. Llamar a la API de Zabbix para eliminar el host
        $response = call_zabbix_api('host.delete', [$zabbix_host_id]);

        if (isset($response['error'])) {
            $err_msg = $response['error']['data'] ?? $response['error']['message'];
            throw new Exception("API Zabbix: " . $err_msg);
        }

        // 2. Limpiar el ID de Zabbix en la tabla de la CMDB
        $stmt = $pdo->prepare("UPDATE `$table_name` SET zabbix_host_id = NULL WHERE id = ?");
        $stmt->execute([$row_id]);

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

exit(json_encode(['success'=>false,'error'=>'Accion desconocida']));
