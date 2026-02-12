<?php
$page_title = 'Historial de Cambios';
require_once __DIR__ . '/partials/header.php'; 
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$table = $_GET['table'] ?? '';
$row_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!isValidTableName($table) || $row_id <= 0) {
    http_response_code(400);
    exit('Parámetros inválidos para ver el historial.');
}

$pdo = getPDO();
$stmt = $pdo->prepare(
    "SELECT h.*, u.username 
     FROM sheet_history h
     LEFT JOIN users u ON h.changed_by_user_id = u.id
     WHERE h.sheet_table_name = :table AND h.sheet_row_id = :id
     ORDER BY h.changed_at DESC"
);
$stmt->execute([':table' => $table, ':id' => $row_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<?php if (empty($history)): ?>
    <div class="alert alert-info">No se encontraron cambios para este registro.</div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Cambios para <?php echo htmlspecialchars($table); ?> #<?php echo $row_id; ?></h3>
        </div>
        <div class="card-body">
            <?php foreach ($history as $entry): ?>
                <div class="post">
                    <div class="user-block">
                        <span class="username">
                            <a href="#"><?php echo htmlspecialchars($entry['username'] ?? 'Usuario desconocido'); ?></a>
                        </span>
                        <span class="description">Realizó un cambio - <?php echo htmlspecialchars($entry['changed_at']); ?></span>
                    </div>
                    <?php
                        $old_values = json_decode($entry['old_values'], true);
                        $new_values = json_decode($entry['new_values'], true);
                    ?>
                    <div class="mt-2">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th>Valor Anterior</th>
                                    <th>Valor Nuevo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($new_values as $field => $new_val): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($field); ?></strong></td>
                                        <td><span class="text-danger"><?php echo htmlspecialchars($old_values[$field] ?? 'N/A'); ?></span></td>
                                        <td><span class="text-success"><?php echo htmlspecialchars($new_val ?? 'N/A'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
