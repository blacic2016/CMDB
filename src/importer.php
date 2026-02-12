<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

class Importer
{
    // mode: 'add' or 'update'
    public static function importExcelFile(string $filePath, string $mode = 'add')
    {
        $pdo = getPDO();
        $spreadsheet = IOFactory::load($filePath);
        $summary = [];

        // If mode is 'replace' we will truncate only the tables that are present in the uploaded spreadsheet
        $truncatedTables = [];
        if ($mode === 'replace') {
            $sheetNames = $spreadsheet->getSheetNames();
            foreach ($sheetNames as $sheetName) {
                $tableName = 'sheet_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sheetName));
                $tableName = preg_replace('/_+/', '_', $tableName);
                try {
                    // check table exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl");
                    $stmt->execute([':db' => DB_CONFIG['database'], ':tbl' => $tableName]);
                    if ($stmt->fetchColumn() > 0) {
                        try { $pdo->exec("TRUNCATE TABLE `{$tableName}`"); $truncatedTables[$tableName] = true; } catch (Exception $e) { /* ignore truncate errors */ }
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }
        }

        foreach ($spreadsheet->getSheetNames() as $index => $sheetName) {
            $sheet = $spreadsheet->getSheet($index);
            $rows = $sheet->toArray(null, true, true, true);
            if (count($rows) < 1) continue;
            // Make trimming null-safe to avoid PHP deprecated warnings
            $headers = array_map(function($h){ return $h === null ? '' : trim($h); }, $rows[1]);
            // sanitize headers and ensure unique column names; avoid using 'id' (reserved primary key)
            $cols = [];
            $seen = [];
            foreach ($headers as $h) {
                $base = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $h));
                $base = preg_replace('/_+/', '_', $base);
                $base = trim($base, '_');
                if ($base === '') $base = 'col';
                if ($base === 'id') $base = 'excel_id'; // prevent collision with PK 'id'
                $col = $base;
                $i = 1;
                while (in_array($col, $seen, true)) {
                    $col = $base . '_' . $i;
                    $i++;
                }
                $seen[] = $col;
                $cols[] = $col;
            }
            $tableName = 'sheet_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sheetName));
            $tableName = preg_replace('/_+/', '_', $tableName);

            // create table if not exists
            $createSql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                _row_hash VARCHAR(64) NOT NULL,
                estado_actual ENUM('USADO','ENTREGADO','NO_APARECE','DANADO') DEFAULT 'USADO',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $pdo->exec($createSql);

            // add columns if not exist
            $all_cols_in_db = [];
            $col_check_stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}`");
            $col_check_stmt->execute();
            while($c = $col_check_stmt->fetch(PDO::FETCH_ASSOC)) {
                $all_cols_in_db[] = $c['Field'];
            }

            // Ensure asset_code column exists
            if (!in_array('asset_code', $all_cols_in_db)) {
                 try {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `asset_code` VARCHAR(50) NULL UNIQUE AFTER `id`");
                } catch (Exception $e) { /* ignore */ }
            }

            foreach ($cols as $col) {
                if ($col === 'id' || in_array($col, $all_cols_in_db)) continue;
                try {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$col}` VARCHAR(255) NULL");
                } catch (Exception $e) {
                    // MySQL < 8 doesn't support ADD COLUMN IF NOT EXISTS in older versions; try to check first
                    try {
                        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE :col");
                        $stmt->execute([':col' => $col]);
                        if ($stmt->rowCount() == 0) {
                            $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$col}` VARCHAR(255) NULL");
                        }
                    } catch (Exception $e2) {
                        // ignore
                    }
                }
            }

            // add unique index on _row_hash if not exists
            try {
                $pdo->exec("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX idx_{$tableName}_rowhash (_row_hash)");
            } catch (Exception $e) {
                // ignore if exists
            }

            // fetch configured unique columns for this sheet (if any)
            $uniqueCols = [];
            try {
                $stmtUc = $pdo->prepare("SELECT unique_columns FROM sheet_configs WHERE table_name = :t LIMIT 1");
                $stmtUc->execute([':t' => $tableName]);
                $ucJson = $stmtUc->fetchColumn();
                if ($ucJson) {
                    $ucArr = json_decode($ucJson, true);
                    if (is_array($ucArr)) {
                        // ensure columns exist
                        foreach ($ucArr as $uc) {
                            if (in_array($uc, $cols, true)) $uniqueCols[] = $uc;
                        }
                    }
                }
            } catch (Exception $e) {
                // ignore
            }

            // if unique columns configured, add a non-unique index to help lookups (ignore errors)
            if (!empty($uniqueCols)) {
                try {
                    $idxName = 'idx_' . $tableName . '_unique_cols';
                    $colsList = implode('`,`', $uniqueCols);
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD INDEX {$idxName} (`{$colsList}`)");
                } catch (Exception $e) {
                    // ignore index creation errors
                }
            }

            // if mode is 'replace' clear the table before inserting rows
            if ($mode === 'replace') {
                try { $pdo->exec("TRUNCATE TABLE `{$tableName}`"); } catch (Exception $e) { /* ignore */ }
            }

            // insert rows
            $added = 0; $skipped = 0; $updated = 0; $errors = [];
            $headerKeys = array_keys($rows[1]); // e.g. A,B,C...
            for ($r = 2; $r <= count($rows); $r++) {
                $row = $rows[$r];
                // build associative mapping using headerKeys to handle columns beyond Z and null-safe trimming
                $data = [];
                foreach ($headerKeys as $i => $letter) {
                    if (!isset($cols[$i])) continue; // no header defined
                    $col = $cols[$i];
                    if ($col === '') continue;
                    $value = array_key_exists($letter, $row) ? ($row[$letter] === null ? null : trim($row[$letter])) : null;
                    $data[$col] = $value;
                }
                $rowHash = hash('md5', json_encode(array_values($data)));
                $data['_row_hash'] = $rowHash;

                // determine existence using configured unique columns when possible, otherwise fall back to _row_hash
                $exists = false;
                $existsId = null;
                $usedMethod = 'row_hash';
                $useUnique = false;
                if (!empty($uniqueCols)) {
                    $hasNonEmpty = false;
                    foreach ($uniqueCols as $uc) {
                        if (isset($data[$uc]) && $data[$uc] !== null && $data[$uc] !== '') { $hasNonEmpty = true; break; }
                    }
                    if ($hasNonEmpty) $useUnique = true;
                }

                if ($useUnique) {
                    // build WHERE with null-safe comparisons
                    $where = []; $paramsWhere = [];
                    foreach ($uniqueCols as $uc) {
                        $param = ':uniq_' . $uc;
                        $where[] = "((`$uc` IS NULL AND $param IS NULL) OR (`$uc` = $param))";
                        $paramsWhere[$param] = array_key_exists($uc, $data) ? $data[$uc] : null;
                    }
                    $sql = "SELECT id FROM `{$tableName}` WHERE " . implode(' AND ', $where) . " LIMIT 1";
                    $stmtU = $pdo->prepare($sql);
                    $stmtU->execute($paramsWhere);
                    $existsId = $stmtU->fetchColumn();
                    if ($existsId) { $exists = true; $usedMethod = 'unique_cols'; }
                }

                if (!$exists) {
                    $stmt = $pdo->prepare("SELECT id FROM `{$tableName}` WHERE _row_hash = :h LIMIT 1");
                    $stmt->execute([':h' => $rowHash]);
                    $existsId = $stmt->fetchColumn();
                    if ($existsId) { $exists = true; $usedMethod = 'row_hash'; }
                }

                if ($exists) {
                    if ($mode === 'update') {
                        // update all columns except PK 'id'
                        $sets = [];
                        $params = [];
                        foreach ($data as $k => $v) { if ($k === '_row_hash' || $k === 'id') continue; $sets[] = "`$k` = :$k"; $params[":$k"] = $v; }
                        // always update _row_hash as well
                        $params[':_row_hash'] = $rowHash;
                        $sets[] = "`_row_hash` = :_row_hash";
                        $params[':id'] = $existsId;
                        $sql = "UPDATE `{$tableName}` SET " . implode(', ', $sets) . " WHERE id = :id";
                        $pdo->prepare($sql)->execute($params);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // insert, but avoid inserting into PK 'id' if present
                    if (isset($data['id'])) unset($data['id']);
                    $colsInsert = array_keys($data);
                    $placeholders = array_map(function ($c) { return ':' . $c; }, $colsInsert);
                    $sql = "INSERT INTO `{$tableName}` (`" . implode('`,`', $colsInsert) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                    $params = [];
                    foreach ($data as $k => $v) $params[':' . $k] = $v;
                    
                    // Assign a new asset code if one isn't provided
                    if (!isset($data['asset_code']) || empty($data['asset_code'])) {
                        $params[':asset_code'] = getNextAssetCode();
                    }

                    try {
                        $pdo->prepare($sql)->execute($params);
                        $added++;
                    } catch (Exception $e) {
                        $errors[] = "Fila $r: " . $e->getMessage();
                    }
                }
            }

            // save import log
            // ensure updated_count column exists (for older DBs)
            try {
                $colStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'import_logs' AND COLUMN_NAME = 'updated_count'");
                $colStmt->execute([':db' => DB_CONFIG['database']]);
                if ($colStmt->fetchColumn() == 0) {
                    try {
                        $pdo->exec("ALTER TABLE import_logs ADD COLUMN updated_count INT DEFAULT 0");
                    } catch (Exception $e) {
                        // ignore alter errors
                    }
                }
            } catch (Exception $e) {
                // ignore if information_schema not accessible
            }

            $ins = $pdo->prepare("INSERT INTO import_logs (filename, sheet_name, mode, added_count, skipped_count, updated_count, errors) VALUES (:f, :s, :m, :a, :sk, :u, :e)");
            $ins->execute([
                ':f' => basename($filePath),
                ':s' => $sheetName,
                ':m' => $mode,
                ':a' => $added,
                ':sk' => $skipped,
                ':u' => $updated,
                ':e' => implode("\n", $errors)
            ]);

            $summary[$sheetName] = ['added' => $added, 'skipped' => $skipped, 'updated' => $updated, 'errors' => $errors, 'unique_columns' => $uniqueCols, 'truncated' => (!empty($truncatedTables) && isset($truncatedTables[$tableName]))];
        }

        return $summary;
    }
}
