<?php
/**
 * CMDB VILASECA - Importación de Archivos Excel
 * Ubicación: /var/www/html/Sonda/public/import.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/importer.php';

// 1. Protección de acceso: Solo ADMIN y SUPER_ADMIN
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

if (!has_role(['ADMIN', 'SUPER_ADMIN'])) {
    http_response_code(403);
    die('Acceso denegado: No tienes permisos para importar datos.');
}

/**
 * Función auxiliar para registrar errores de subida en el log de Sonda
 */
function log_upload_error($msg) {
    $dir = STORAGE_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/upload_errors.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $msg . ' IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'n/a') . PHP_EOL;
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

$show_results = false;
$summary = [];
$dest = '';

// 2. Procesar el formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        $err = 'No se recibió ningún archivo.';
        log_upload_error($err);
        exit($err);
    }

    $file = $_FILES['file'];

    // Validar errores de subida de PHP
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo en php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario',
            UPLOAD_ERR_PARTIAL    => 'Subida parcial',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Error de carpeta temporal en servidor',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco',
        ];
        $msg = $messages[$file['error']] ?? 'Error desconocido';
        log_upload_error("Fallo subida: $msg");
        exit("Error al subir archivo: $msg");
    }

    // Determinar modo de importación
    $mode = $_POST['mode'] ?? 'add';
    if ($mode === 'replace' && !has_role(['SUPER_ADMIN'])) {
        die('Error: Solo el Super Admin puede usar el modo Reemplazar.');
    }

    // Validar y crear directorio de destino
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    if (!is_writable(UPLOAD_DIR)) die('Error: La carpeta de destino no tiene permisos de escritura.');

    // Mover archivo al storage
    $dest = UPLOAD_DIR . '/' . time() . '_' . basename($file['name']);
    
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Llamada al motor de importación
        try {
            $summary = Importer::importExcelFile($dest, $mode);
            $show_results = true;
        } catch (Exception $e) {
            log_upload_error("Error Importer: " . $e->getMessage());
            die("Error procesando Excel: " . $e->getMessage());
        }
    } else {
        die('Error al mover el archivo al servidor.');
    }
}

$page_title = 'Importar Excel';
require_once __DIR__ . '/partials/header.php'; 
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1>Gestión de Importación CMDB</h1>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($show_results): ?>
                <div class="card card-success">
                    <div class="card-header"><h3 class="card-title">Importación Finalizada</h3></div>
                    <div class="card-body">
                        <p>Archivo procesado: <strong><?php echo htmlspecialchars(basename($dest)); ?></strong></p>
                        <a href="cmdb.php" class="btn btn-primary mb-4">Ver Inventario</a>
                        
                        <?php foreach ($summary as $sheet => $r): ?>
                            <div class="alert alert-light border shadow-sm">
                                <h5><i class="fas fa-file-excel mr-2 text-success"></i>Hoja: <?php echo htmlspecialchars($sheet); ?></h5>
                                <ul class="mb-0">
                                    <li>Registros Agregados: <?php echo $r['added']; ?></li>
                                    <li>Registros Omitidos: <?php echo $r['skipped']; ?></li>
                                    <?php if(isset($r['updated'])): ?><li>Registros Actualizados: <?php echo $r['updated']; ?></li><?php endif; ?>
                                </ul>
                                <?php if (!empty($r['errors'])): ?>
                                    <div class="mt-2 text-danger small">
                                        <strong>Errores detectados:</strong>
                                        <pre class="bg-dark text-white p-2 mt-1"><?php echo htmlspecialchars(implode("\n", $r['errors'])); ?></pre>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card card-primary card-outline">
                    <div class="card-header"><h3 class="card-title">Cargar Nuevo Inventario (Excel)</h3></div>
                    <div class="card-body">
                        <form id="importFormPage" action="import.php" method="post" enctype="multipart/form-data">
                            <div class="form-group mb-4">
                                <label for="filePage">Seleccione archivo (.xlsx, .xls)</label>
                                <input type="file" class="form-control" name="file" id="filePage" accept=".xlsx,.xls" required>
                                <small class="text-muted">El archivo será procesado y cruzado con la base de datos 172.32.1.51.</small>
                            </div>
                            
                            <div class="form-group mb-4">
                                <label>Modo de Importación</label>
                                <select name="mode" class="form-control" id="importModeSelectPage">
                                    <option value="add">Solo agregar nuevos (No toca lo existente)</option>
                                    <option value="update">Actualizar existentes (Basado en columnas únicas)</option>
                                    <?php if (has_role(['SUPER_ADMIN'])): ?>
                                        <option value="replace" class="text-danger">REEMPLAZAR (Borra datos actuales de la tabla)</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="text-right">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitImportBtnPage">
                                    <i class="fas fa-cloud-upload-alt mr-2"></i> Iniciar Importación
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
document.getElementById('importFormPage')?.addEventListener('submit', function(e) {
    const mode = document.getElementById('importModeSelectPage').value;
    if (mode === 'replace') {
        if (!confirm('¡PELIGRO! El modo Reemplazar borrará los datos actuales de la CMDB para estas hojas. ¿Está totalmente seguro?')) {
            e.preventDefault();
            return;
        }
    }
    const btn = document.getElementById('submitImportBtnPage');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Procesando Excel...';
});
</script>