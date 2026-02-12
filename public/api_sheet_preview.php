<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
header('Content-Type: application/json');
$table = $_GET['table'] ?? '';
if (!isValidTableName($table)) exit(json_encode(['success'=>false,'error'=>'Tabla inválida']));
try {
    $cols = getTableColumns($table);
    $count = countTableRows($table);
    $rows = fetchTableRows($table, [], [], '', 1, 0);
    $sample = [];
    if (!empty($rows)) {
        $first = $rows[0];
        foreach ($cols as $c) {
            if (in_array($c, ['id','_row_hash','created_at','updated_at'])) continue;
            if (isset($first[$c]) && trim((string)$first[$c]) !== '') {
                $sample[$c] = $first[$c];
            }
        }
    }
    // load unique columns if configured
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT unique_columns FROM sheet_configs WHERE table_name = :t LIMIT 1");
    $stmt->execute([':t' => $table]);
    $ucJson = $stmt->fetchColumn();
    $uniqueCols = [];
    if ($ucJson) $uniqueCols = json_decode($ucJson, true) ?: [];

    exit(json_encode(['success'=>true,'table'=>$table,'count'=>$count,'sample'=>$sample,'unique_columns'=>$uniqueCols]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'error'=>$e->getMessage()]));
}
