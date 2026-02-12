<?php
/**
 * Dashboard Principal - CMDB VILASECA
 * Ubicación: /var/www/html/Sonda/public/dashboard.php
 */

// 1. Cargar configuración y archivos de soporte
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

// 2. PROTECCIÓN: Verificar que el usuario esté logueado
require_login(); 

// 3. Obtener datos para la vista
$pdo = getPDO();
$page_title = 'Dashboard';

// Si la conexión a la DB falla (PDO es null), evitamos el error de red
if (!$pdo) {
    die("Error crítico: No se pudo conectar a la base de datos en 172.32.1.51");
}

try {
    $total_sheets = count(listSheetTables());
    $total_images = $pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
    $recentImports = $pdo->query("SELECT filename, sheet_name, mode, added_count, skipped_count, updated_count, created_at 
                                  FROM import_logs 
                                  ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error en Dashboard: " . $e->getMessage());
    $total_sheets = 0;
    $total_images = 0;
    $recentImports = [];
}

// 4. Cargar el encabezado visual
require_once __DIR__ . '/partials/header.php'; 
?>

<div class="row">
    <div class="col-lg-3 col-6">
        <div class="small-box bg-info">
            <div class="inner">
                <h3><?php echo $total_sheets; ?></h3>
                <p>Hojas Gestionadas</p>
            </div>
            <div class="icon"><i class="fas fa-layer-group"></i></div>
            <a href="<?php echo PUBLIC_URL_PREFIX; ?>/cmdb.php" class="small-box-footer">Más info <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="small-box bg-success">
            <div class="inner">
                <h3><?php echo $total_images; ?></h3>
                <p>Imágenes Almacenadas</p>
            </div>
            <div class="icon"><i class="fas fa-image"></i></div>
            <a href="<?php echo PUBLIC_URL_PREFIX; ?>/distribrack.php" class="small-box-footer">Más info <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Importaciones Recientes</h3>
    </div>
    <div class="card-body p-0">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Hoja</th>
                    <th>Modo</th>
                    <th>Agregados</th>
                    <th>Omitidos</th>
                    <th>Actualizados</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentImports)): ?>
                    <tr><td colspan="7" class="text-center">No hay registros de importación.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentImports as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['filename']); ?></td>
                            <td><?php echo htmlspecialchars($r['sheet_name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['mode']); ?></span></td>
                            <td><span class="badge bg-success"><?php echo $r['added_count']; ?></span></td>
                            <td><span class="badge bg-warning"><?php echo $r['skipped_count']; ?></span></td>
                            <td><span class="badge bg-info"><?php echo $r['updated_count']; ?></span></td>
                            <td><?php echo date("d/m/Y H:i", strtotime($r['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>