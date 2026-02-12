<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

// Manejo de sesión y seguridad
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$user = current_user();
$table = $_GET['table'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!isValidTableName($table) || $id <= 0) {
    http_response_code(400);
    exit('Parámetros inválidos.');
}

$row = getRowById($table, $id);
if (!$row) {
    http_response_code(404);
    exit('Registro no encontrado.');
}

$cols = getTableColumns($table);
$page_title = 'Detalle: ' . htmlspecialchars($row['nombre'] ?? 'Activo');

require_once __DIR__ . '/partials/header.php'; 

// Cargar imágenes
$pdo = getPDO();
$stmt_images = $pdo->prepare("SELECT id, filepath FROM images WHERE entity_type = :table AND entity_id = :id ORDER BY uploaded_at DESC");
$stmt_images->execute([':table' => $table, ':id' => $id]);
$images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class="fas fa-info-circle mr-2"></i>Detalle de Activo</h1>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-8" id="main-content-column"></div>

                <div class="col-lg-4">
                    <div class="card card-info card-outline">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-camera mr-1"></i> Fotografías</h3></div>
                        <div class="card-body">
                            <div id="image-gallery" class="row gx-2 gy-2 mb-3">
                                <?php if (empty($images)): ?>
                                    <p class="text-center text-muted w-100 p-3">Sin imágenes.</p>
                                <?php else: ?>
                                    <?php foreach ($images as $img): ?>
                                        <div class="col-6 position-relative mb-2">
                                            <a href="<?= htmlspecialchars($img['filepath']) ?>" target="_blank">
                                                <img src="<?= htmlspecialchars($img['filepath']) ?>" class="img-fluid rounded shadow-sm" style="height: 120px; object-fit: cover; width: 100%;">
                                            </a>
                                            <?php if (has_role(['ADMIN', 'SUPER_ADMIN'])): ?>
                                                <button class="btn btn-danger btn-xs position-absolute delete-image-btn" style="top:5px; right:10px;" data-image-id="<?= $img['id'] ?>">×</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (has_role(['ADMIN', 'SUPER_ADMIN'])): ?>
                                <hr>
                                <form id="image-upload-form" enctype="multipart/form-data">
                                    <input type="file" class="form-control-file mb-2" name="image" accept="image/*" required>
                                    <button type="submit" class="btn btn-info btn-block btn-sm">Añadir Imagen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-lg-8" id="action-buttons-container"></div>
            </div>

            <div id="map-section" class="row mt-3" style="display:none;">
                <div class="col-lg-8">
                    <div class="card card-outline card-success shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-map-marked-alt mr-1"></i> Ubicación Geográfica</h3>
                        </div>
                        <div class="card-body p-0">
                            <div id="map-container" style="width: 100%; height: 450px; background: #f4f6f9;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mainContentColumn = document.getElementById('main-content-column');
    const actionButtonsContainer = document.getElementById('action-buttons-container');
    let isEditMode = false;

    const rowData = <?= json_encode($row) ?>;
    const colsData = <?= json_encode($cols) ?>;
    const mainFields = ['nombre', 'direcci_n_ip_gesti_n', 'serial_number', 'marca_modelo', 'sucursal', 'tipo', 'googlemaps'];

    // --- LÓGICA DE MAPA ---
    function updateGoogleMapsView(link) {
        const mapSection = document.getElementById('map-section');
        const mapContainer = document.getElementById('map-container');

        if (!link || link.trim() === "") {
            mapSection.style.display = 'none';
            return;
        }

        mapSection.style.display = 'block';

        if (link.includes('<iframe')) {
            mapContainer.innerHTML = link.replace(/width="\d+"/, 'width="100%"').replace(/height="\d+"/, 'height="450"');
        } else if (link.includes('maps.app.goo.gl')) {
            mapContainer.innerHTML = `
                <div class="p-5 text-center">
                    <i class="fas fa-map-marker-alt fa-3x text-danger mb-3"></i><br>
                    <h5>Enlace de Celular Detectado</h5>
                    <p>Google no permite incrustar estos links directamente.</p>
                    <a href="${link}" target="_blank" class="btn btn-primary btn-sm">Abrir Mapa Externo</a>
                </div>`;
        } else {
            mapContainer.innerHTML = `<iframe width="100%" height="450" frameborder="0" style="border:0;" src="${link}" allowfullscreen></iframe>`;
        }
    }

    function render() {
        if (isEditMode) renderEditView(); else renderDetailView();
    }

    function renderDetailView() {
        let html = `
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header"><h3 class="card-title">Información Principal</h3></div>
                <div class="card-body"><div class="row">
                    ${mainFields.map(f => `
                        <div class="col-md-4 mb-3">
                            <label class="text-muted small d-block">${f.replace(/_/g, ' ').toUpperCase()}</label>
                            <strong>${rowData[f] ?? 'N/A'}</strong>
                        </div>
                    `).join('')}
                </div></div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header"><h3 class="card-title">Atributos Adicionales</h3></div>
                <div class="card-body"><div class="row">
                    ${colsData.filter(c => !mainFields.includes(c) && !['id', '_row_hash', 'created_at', 'updated_at', 'zabbix_host_id'].includes(c)).map(c => `
                        <div class="col-md-4 mb-3 border-bottom pb-2">
                            <label class="text-muted small d-block">${c.replace(/_/g, ' ').toUpperCase()}</label>
                            <span>${rowData[c] ?? 'N/A'}</span>
                        </div>
                    `).join('')}
                </div></div>
            </div>`;
        mainContentColumn.innerHTML = html;

        actionButtonsContainer.innerHTML = `
            <div class="card card-footer d-flex flex-row justify-content-end shadow-sm" style="gap: 10px;">
                <a href="history.php?table=${encodeURIComponent('<?= $table ?>')}&id=<?= $id ?>" class="btn btn-outline-secondary">Historial</a>
                <?php if (has_role(['ADMIN','SUPER_ADMIN'])): ?>
                    <button class="btn btn-danger" id="delete-btn">Eliminar</button>
                    <button class="btn btn-primary" id="edit-btn">Editar</button>
                <?php endif; ?>
            </div>`;
        
        updateGoogleMapsView(rowData['googlemaps']);
        attachViewListeners();
    }

    function renderEditView() {
        mainContentColumn.innerHTML = `
            <div class="card card-warning shadow-sm">
                <div class="card-header"><h3 class="card-title text-white">Editando Registro</h3></div>
                <div class="card-body">
                    <form id="edit-form">
                        <div class="row">
                            ${colsData.filter(c => !['id', '_row_hash', 'created_at', 'updated_at', 'zabbix_host_id'].includes(c)).map(c => `
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small text-muted">${c.replace(/_/g, ' ').toUpperCase()}</label>
                                    <input type="text" class="form-control form-control-sm" name="${c}" id="input-${c}" value="${rowData[c] ?? ''}">
                                </div>
                            `).join('')}
                        </div>
                        <div class="mt-4 d-flex justify-content-end" style="gap: 10px;">
                            <button type="button" class="btn btn-secondary" id="cancel-edit-btn">Cancelar</button>
                            <button type="submit" class="btn btn-success">Guardar Cambios</button>
                        </div>
                    </form>
                </div>
            </div>`;
        
        // Listener en tiempo real para el mapa
        const mapInput = document.getElementById('input-googlemaps');
        if(mapInput) {
            mapInput.addEventListener('input', (e) => updateGoogleMapsView(e.target.value));
        }

        updateGoogleMapsView(rowData['googlemaps']);
        attachEditListeners();
    }

    function attachViewListeners() {
        document.getElementById('edit-btn')?.addEventListener('click', () => { isEditMode = true; render(); });
        document.getElementById('delete-btn')?.addEventListener('click', function() {
            if (!confirm('¿Eliminar registro?')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('table', '<?= addslashes($table) ?>');
            fd.append('id', '<?= $id ?>');
            fetch('api_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(js => js.success ? window.location.href = 'db.php?name=<?= urlencode($table) ?>' : alert(js.error));
        });
    }

    function attachEditListeners() {
        document.getElementById('cancel-edit-btn').addEventListener('click', () => { isEditMode = false; render(); });
        document.getElementById('edit-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'update');
            fd.append('table', '<?= addslashes($table) ?>');
            fd.append('id', '<?= $id ?>');
            fetch('api_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(js => js.success ? location.reload() : alert(js.error));
        });
    }

    render();

    // Eventos de Imágenes
    document.getElementById('image-upload-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('table', '<?= addslashes($table) ?>');
        fd.append('id', '<?= $id ?>');
        fetch('api_upload_image.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => data.success ? location.reload() : alert(data.error));
    });

    document.getElementById('image-gallery').addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-image-btn')) {
            if (!confirm('¿Eliminar imagen?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_image');
            fd.append('id', e.target.dataset.imageId);
            fetch('api_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(js => js.success ? location.reload() : alert(js.error));
        }
    });
});
</script>


