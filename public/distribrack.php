<?php
/**
 * CMDB VILASECA - Galería Distribrack
 * Ubicación: /var/www/html/Sonda/public/distribrack.php
 */

$page_title = 'Distribrack';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$user = current_user();
$pdo = getPDO();

// 1. Procesamiento de Filtros
$entity_type = $_GET['entity_type'] ?? '';
$entity_id = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($entity_type !== '') { 
    $where[] = 'entity_type = :et'; 
    $params[':et'] = $entity_type; 
}
if ($entity_id > 0) { 
    $where[] = 'entity_id = :eid'; 
    $params[':eid'] = $entity_id; 
}
if ($q !== '') { 
    $where[] = '(filename LIKE :q OR filepath LIKE :q)'; 
    $params[':q'] = '%' . $q . '%'; 
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 2. Obtener tipos de entidad para el filtro (Dinámico desde 172.32.1.51)
$types = $pdo->query("SELECT DISTINCT entity_type FROM images ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

// 3. Consulta de Imágenes
$stmt = $pdo->prepare("SELECT id, entity_type, entity_id, filepath, filename, uploaded_at FROM images " . $whereSql . " ORDER BY uploaded_at DESC LIMIT 300");
$stmt->execute($params);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
    /* Estilos para la cuadrícula de imágenes */
    .thumb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
    .thumb { border: 1px solid #ddd; padding: 10px; border-radius: 8px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .thumb-img img { width: 100%; height: 150px; object-fit: cover; border-radius: 4px; }
    .thumb-meta { margin-top: 10px; font-size: 0.85rem; }
</style>

<div class="content-wrapper">
    <section class="content">
        <div class="container-fluid pt-3">
            <div class="card card-outline card-success">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-images mr-2"></i> Galería de Imágenes Distribrack</h3>
                </div>
                <div class="card-body">
                    <form method="get" class="row mb-4">
                        <div class="col-md-3">
                            <select class="form-select" name="entity_type">
                                <option value="">-- Todos los tipos --</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>" <?php if ($t === $entity_type) echo 'selected'; ?>><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input class="form-control" name="entity_id" placeholder="ID entidad" value="<?php echo $entity_id ? $entity_id : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <input class="form-control" name="q" placeholder="Nombre de archivo..." value="<?php echo htmlspecialchars($q); ?>">
                        </div>
                        <div class="col-md-3 text-end">
                            <button class="btn btn-primary"><i class="fas fa-filter"></i></button>
                            <button type="button" class="btn btn-success ms-2" id="btnUpload"><i class="fas fa-upload"></i> Subir</button>
                        </div>
                    </form>

                    <div class="thumb-grid">
                        <?php if (empty($images)): ?>
                            <div class="col-12 text-center text-muted py-5">
                                <i class="fas fa-image-slash fa-3x mb-3"></i>
                                <p>No se encontraron imágenes en la galería.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($images as $img): ?>
                                <div class="thumb">
                                    <div class="thumb-img">
                                        <img src="<?php echo PUBLIC_URL_PREFIX . '/../' . ltrim($img['filepath'], '/'); ?>" alt="<?php echo htmlspecialchars($img['filename']); ?>">
                                    </div>
                                    <div class="thumb-meta">
                                        <div class="text-truncate"><strong><?php echo htmlspecialchars($img['filename']); ?></strong></div>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($img['entity_type']); ?> #<?php echo $img['entity_id']; ?><br>
                                            <i class="far fa-calendar-alt"></i> <?php echo date("d/m/Y", strtotime($img['uploaded_at'])); ?>
                                        </div>
                                    </div>
                                    <?php if (has_role(['ADMIN','SUPER_ADMIN'])): ?>
                                        <div class="thumb-actions mt-2 text-end">
                                            <button class="btn btn-sm btn-danger btnDeleteImage" data-id="<?php echo $img['id']; ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="uploadForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">Subir Nueva Imagen</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Archivo</label>
                <input type="file" name="image" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Tipo de Entidad (ej: Rack, UPS)</label>
                <input type="text" name="entity_type" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">ID de Entidad</label>
                <input type="number" name="entity_id" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Subir Imagen</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Eliminar Imagen
  document.querySelectorAll('.btnDeleteImage').forEach(function(b){
    b.addEventListener('click', function(){
      if (!confirm('¿Estás seguro de eliminar esta imagen?')) return;
      const id = this.dataset.id;
      const formData = new URLSearchParams();
      formData.append('id', id);
      formData.append('action', 'delete_image');

      fetch('api_action.php', { 
          method: 'POST', 
          headers: {'Content-Type':'application/x-www-form-urlencoded'}, 
          body: formData.toString() 
      })
      .then(r => r.json())
      .then(js => { 
          if (js.success) location.reload(); 
          else alert("Error: " + js.error); 
      })
      .catch(err => alert("Error de conexión al eliminar."));
    });
  });

  // Abrir Modal de Subida
  const btnUpload = document.getElementById('btnUpload');
  if(btnUpload) {
      btnUpload.addEventListener('click', function(){
        var myModal = new bootstrap.Modal(document.getElementById('uploadModal'));
        myModal.show();
      });
  }

  // Manejar Formulario de Subida
  document.getElementById('uploadForm')?.addEventListener('submit', function(e){
    e.preventDefault();
    const f = new FormData(this);
    fetch('upload_image.php', { method: 'POST', body: f })
    .then(r => r.json())
    .then(js => { 
        if (js.success) location.reload(); 
        else alert("Error al subir: " + js.error); 
    })
    .catch(err => alert("Error en el servidor al subir la imagen."));
  });
});
</script>