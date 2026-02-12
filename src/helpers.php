<?php
require_once __DIR__ . '/db.php';

function listSheetTables()
{
    $pdo = getPDO();
    $rows = $pdo->query("SHOW TABLES LIKE 'sheet_%'")->fetchAll(PDO::FETCH_NUM);
    $tables = [];
    foreach ($rows as $r) $tables[] = $r[0];
    return $tables;
}

function isValidTableName($name)
{
    return preg_match('/^sheet_[a-z0-9_]+$/', $name);
}

function getTableColumns($table)
{
    if (!isValidTableName($table)) return [];
    $pdo = getPDO();
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "`");
    $stmt->execute();
    $cols = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $row['Field'];
    }

    // Custom sorting logic for columns
    $begin = ['id', 'nombre', 'ipaddress'];
    $end = ['estado_actual', 'created_at', 'updated_at'];

    $ordered_cols = [];
    $middle_cols = [];

    // First, pull out the 'begin' columns that exist in $cols
    foreach ($begin as $b_col) {
        if (in_array($b_col, $cols)) {
            $ordered_cols[] = $b_col;
        }
    }

    // Then, collect all other columns that are not in 'begin' or 'end'
    foreach ($cols as $col) {
        if (!in_array($col, $begin) && !in_array($col, $end)) {
            $middle_cols[] = $col;
        }
    }
    
    // Merge the middle columns
    $ordered_cols = array_merge($ordered_cols, $middle_cols);

    // Finally, add the 'end' columns that exist in $cols
    foreach ($end as $e_col) {
        if (in_array($e_col, $cols)) {
            $ordered_cols[] = $e_col;
        }
    }
    
    return $ordered_cols;
}

function fetchTableRows($table, $whereClauses = [], $params = [], $order = '', $limit = 50, $offset = 0)
{
    if (!isValidTableName($table)) return [];
    $pdo = getPDO();
    $sql = "SELECT * FROM `" . $table . "`";
    if (!empty($whereClauses)) $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
    if ($order) $sql .= ' ORDER BY ' . $order;
    $sql .= ' LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countTableRows($table, $whereClauses = [], $params = [])
{
    if (!isValidTableName($table)) return 0;
    $pdo = getPDO();
    $sql = "SELECT COUNT(*) FROM `" . $table . "`";
    if (!empty($whereClauses)) $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function getRowById($table, $id)
{
    if (!isValidTableName($table)) return null;
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM `" . $table . "` WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getNextAssetCode()
{
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->query("SELECT last_id, prefix FROM asset_sequence WHERE id = 1 FOR UPDATE");
        $seq = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $next_id = $seq['last_id'] + 1;
        $prefix = $seq['prefix'];

        $update_stmt = $pdo->prepare("UPDATE asset_sequence SET last_id = :next_id WHERE id = 1");
        $update_stmt->execute([':next_id' => $next_id]);
        
        $pdo->commit();

        // Format the code, e.g., AE-00001
        return $prefix . '-' . str_pad($next_id, 5, '0', STR_PAD_LEFT);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Fallback in case of error, though this should be rare
        return 'ERR-' . time();
    }
}
