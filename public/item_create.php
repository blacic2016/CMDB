<?php
$page_title = 'Crear Nuevo Activo';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/partials/header.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();
require_role(['ADMIN', 'SUPER_ADMIN']);

$table = $_GET['table'] ?? '';
if (!isValidTableName($table)) {
    exit('Tabla no válida.');
}

$cols = getTableColumns($table);
$sheet_name_clean = ucfirst(preg_replace('/^sheet_/', '', $table));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    $data = [];

    // 1. Recopilar datos solo de columnas que existen en la tabla
    foreach ($cols as $c) {
        // Saltamos las columnas automáticas
        if (in_array($c, ['id', '_row_hash', 'created_at', 'updated_at', 'zabbix_host_id'])) continue;
        
        if (isset($_POST[$c]) && $_POST[$c] !== '') {
            $data[$c] = $_POST[$c];
        }
    }

    // 2. Solo agregar asset_code si la columna EXISTE en la tabla
    if (in_array('asset_code', $cols)) {
        $data['asset_code'] = getNextAssetCode(); 
    }

    // 3. Generar Hash de seguridad
    $data['_row_hash'] = hash('md5', json_encode(array_values($data)) . time());

    // 4. Preparar la consulta SQL dinámicamente
    $colsInsert = array_keys($data);
    $placeholders = array_map(function($c) { return ':' . $c; }, $colsInsert);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $colsInsert) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data); // PDO asocia automáticamente :key con el valor
        $new_id = $pdo->lastInsertId();

        // 5. Manejo de imagen (Usando la lógica de api_upload_image)
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $base_dir = dirname(__DIR__);
            $upload_path = $base_dir . '/storage/uploads/';
            
            if (!is_dir($upload_path)) mkdir($upload_path, 0755, true);

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $new_name = "IMG_NEW_" . uniqid() . "." . $ext;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path . $new_name)) {
                $db_relative_path = "storage/uploads/" . $new_name;
                $stmt_img = $pdo->prepare("INSERT INTO images (entity_type, entity_id, filepath, filename, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_img->execute([$table, $new_id, $db_relative_path, $_FILES['image']['name']]);
            }
        }

        echo "<script>window.location.href = 'item_detail.php?table=" . urlencode($table) . "&id=" . $new_id . "&created=true';</script>";
        exit;

    } catch (Exception $e) {
        $error_message = "Error al crear el registro: " . $e->getMessage();
    }
}
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1>Nuevo Activo: <?php echo htmlspecialchars($sheet_name_clean); ?></h1>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <div class="card card-success card-outline">
                <form method="post" enctype="multipart/form-data">
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($cols as $c): 
                                // Ocultar columnas internas del formulario
                                if (in_array($c, ['id', 'asset_code', '_row_hash', 'created_at', 'updated_at', 'zabbix_host_id'])) continue; 
                            ?>
                                <div class="col-md-6 form-group">
                                    <label><?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($c))); ?></label>
                                    <input type="text" class="form-control" name="<?php echo $c; ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <hr>
                        <div class="form-group">
                            <label>Fotografía del Activo</label>
                            <input type="file" name="image" class="form-control-file" accept="image/*">
                        </div>
                    </div>
                    <div class="card-footer text-right">
                        <a href="db.php?name=<?php echo urlencode($table); ?>" class="btn btn-default">Cancelar</a>
                        <button type="submit" class="btn btn-success shadow">
                            <i class="fas fa-save mr-1"></i> Guardar Activo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>