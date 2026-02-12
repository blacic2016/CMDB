<?php
require_once  '../src/helpers.php';
require_once  '../src/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
$user = current_user();

$name = isset($_GET['name']) ? $_GET['name'] : '';
if (!isValidTableName($name)) {
    http_response_code(400);
    exit('Nombre de hoja inválido');
}

$cols = getTableColumns($name);

// Pagination and filters
$perPage = isset($_GET['per_page']) ? max(10, (int)$_GET['per_page']) : 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$filters = [];
$params = [];

if ($q !== '') {
    $qparam = '%' . $q . '%';
    $or = [];
    foreach ($cols as $c) {
        $or[] = "`$c` LIKE :q";
    }
    $filters[] = '(' . implode(' OR ', $or) . ')';
    $params[':q'] = $qparam;
}


$total = countTableRows($name, $filters, $params);
$rows = fetchTableRows($name, $filters, $params, '', $perPage, $offset);

// partial response for AJAX tab loading
$partial = isset($_GET['partial']) && $_GET['partial'] === '1';

function render_sheet_content($name, $cols, $rows, $q, $page, $perPage, $total) {
    ?>
    <div class="sheet-card">
      <form id="sheetFilterForm" method="get" class="row g-2 mb-3">
        <input type="hidden" name="name" value="<?php echo htmlspecialchars($name); ?>">
        <div class="col-md-4">
          <input class="form-control" name="q" placeholder="Búsqueda global..." value="<?php echo htmlspecialchars($q); ?>">
        </div>

        <div class="col-md-12 mt-2">
          <button class="btn btn-primary">Filtrar</button>
          <?php if (has_role(['ADMIN','SUPER_ADMIN'])): ?>
            <button type="button" class="btn btn-success ms-2" id="btnNew">Crear nuevo</button>
          <?php endif; ?>
        </div>
      </form>
      <script>
      (function(){
        const form = document.getElementById('sheetFilterForm');
        if (!form) return;
        form.addEventListener('submit', function(e){
          const container = document.getElementById('sheetsContent');
          if (!container) return; // full-page submit - allow default behaviour
          e.preventDefault();
          const fd = new FormData(this);
          fd.set('partial','1');
          const params = new URLSearchParams();
          for (const pair of fd.entries()) { params.append(pair[0], pair[1]); }
          const q = params.get('q') || '';
          history.pushState(null,'','..eets.php' + (q ? '?q=' + encodeURIComponent(q) : ''));
          fetch('sheet.php?' + params.toString()).then(r=>r.text()).then(html=>{
            container.innerHTML = html;
            if (typeof bindRowActions === 'function') bindRowActions();
          }).catch(e => container.innerHTML = '<div class="text-danger p-4">Error aplicando filtros.</div>');
        });
      })();
      </script>

      <div class="row">
      <div class="col-md-7">
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead>
              <tr>
                <?php foreach ($cols as $c): ?>
                  <?php if ($c === '_row_hash') continue; ?>
                  <th><?php echo htmlspecialchars($c); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <?php
                    // Adjust colspan to account for the hidden column
                    $visible_cols = count(array_filter($cols, function($c){ return $c !== '_row_hash'; }));
                ?>
                <tr><td colspan="<?php echo $visible_cols; ?>"><div class="empty-note">No se encontraron filas.</div></td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr class="data-row" data-id="<?php echo $r['id']; ?>">
                    <?php foreach ($cols as $c): ?>
                      <?php if ($c === '_row_hash') continue; ?>
                      <td><?php echo htmlspecialchars($r[$c] ?? ''); ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col-md-5">
        <div id="detailPane" class="sheet-card">
          <div class="empty-note">Selecciona una fila para ver detalles y acciones (editar, desactivar, eliminar).</div>
        </div>
      </div>
    </div>

    <?php
    $lastPage = (int)ceil($total / $perPage);
    if ($lastPage > 1):
    ?>
      <nav>
        <ul class="pagination">
          <?php for ($p = 1; $p <= $lastPage; $p++): ?>
            <li class="page-item <?php echo $p===$page ? 'active' : ''; ?>"><a class="page-link" href="?name=<?php echo urlencode($name); ?>&page=<?php echo $p; ?>&per_page=<?php echo $perPage; ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
      <?php endif; ?>

    </div>

    <!-- Modal placeholder -->
    <div class="modal fade" id="itemModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content" id="itemModalContent"></div>
      </div>
    </div>

    <!-- Edit Modal placeholder -->
    <div class="modal fade" id="editModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content" id="editModalContent"></div>
      </div>
    </div>

    <script>
    function bindRowActions(){
      document.querySelectorAll('.data-row').forEach(function(row){
        row.addEventListener('click', function(){
          const id = this.dataset.id;
          fetch('item.php?table=' + encodeURIComponent('<?php echo $name; ?>') + '&id=' + encodeURIComponent(id))
            .then(r => r.text()).then(html => {
              document.getElementById('itemModalContent').innerHTML = html;
              var myModal = new bootstrap.Modal(document.getElementById('itemModal'));
              myModal.show();
            });
        });
      });

      document.getElementById('btnNew')?.addEventListener('click', function(){
        fetch('item.php?table=' + encodeURIComponent('<?php echo $name; ?>') + '&new=1')
          .then(r => r.text()).then(html => {
            document.getElementById('itemModalContent').innerHTML = html;
            var myModal = new bootstrap.Modal(document.getElementById('itemModal'));
            myModal.show();
          });
      });
    }
    // call after injection
    if (typeof bindRowActions === 'function') bindRowActions();
    </script>
    <?php
}

if ($partial) {
    render_sheet_content($name, $cols, $rows, $q, $page, $perPage, $total);
    exit;
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars($name); ?> - CMDB</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="p-4">
  <?php include  '..rtials/header.php'; ?>
  <div class="app-shell">
    <?php include  '..rtials/sidebar.php'; ?>
    <main class="main-content p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><?php echo htmlspecialchars($name); ?></h1>
        <div>
          <a class="btn btn-sm btn-outline-secondary" href="/sheets.php">Volver</a>
        </div>
      </div>

      <?php render_sheet_content($name, $cols, $rows, $q, $page, $perPage, $total); ?>

    </main>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>document.getElementById('sidebarToggle')?.addEventListener('click', function(){ document.querySelector('.sidebar')?.classList.toggle('open'); });</script>
</body>
</html>