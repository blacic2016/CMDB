<?php
require_once '../src/helpers.php';
require_once '../src/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
$user = current_user();

$tables = listSheetTables();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Hojas - CMDB Vilaseca</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    .cursor-pointer { cursor: pointer; }
    .data-row:hover { background-color: rgba(0,0,0,0.05); }
    .tab-preview { font-size: 0.75rem; display: block; min-height: 1rem; }
  </style>
</head>
<body class="p-4">
  <?php include '../partials/header.php'; ?>
  
  <div class="app-shell">
    <?php include '../partials/sidebar.php'; ?>
    
    <main class="main-content p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Hojas (Sheets)</h1>
      </div>

      <form class="d-block position-relative header-search mb-4" action="sheets.php" method="get">
        <div class="input-group">
          <input name="q" class="form-control" placeholder="Búsqueda global en todas las hojas..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
          <button type="submit" class="btn btn-primary btn-search" title="Buscar">
            <i class="bi bi-search"></i>
          </button>
        </div>
      </form>

    <?php if (empty($tables)): ?>
      <div class="alert alert-info mt-3">No hay hojas importadas aún.</div>
    <?php else: ?>
      <ul class="nav nav-tabs" role="tablist" id="sheetsTabs">
        <?php $first = true; foreach ($tables as $t): $count = countTableRows($t); ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $first ? 'active' : ''; ?>" data-table="<?php echo htmlspecialchars($t); ?>" data-bs-toggle="tab" type="button" role="tab">
              <div class="d-flex align-items-start text-start">
                <div class="me-2"><strong><?php echo htmlspecialchars($t); ?></strong></div>
                <div class="ms-auto text-end">
                  <div><span class="badge bg-primary ms-2"><?php echo $count; ?></span></div>
                  <div class="text-muted small tab-preview" data-table="<?php echo htmlspecialchars($t); ?>">Cargando...</div>
                </div>
              </div>
            </button>
          </li>
        <?php $first = false; endforeach; ?>
      </ul>

      <div class="tab-content mt-3" id="sheetsContent">
        <div class="empty-note text-muted p-4">Selecciona una pestaña para ver la hoja.</div>
      </div>

      <noscript>
        <div class="sheet-list mt-3">
          <div class="list-group">
            <?php foreach ($tables as $t): $count = countTableRows($t); ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="sheet.php?name=<?php echo urlencode($t); ?>">
              <?php echo htmlspecialchars($t); ?>
              <span class="badge bg-primary rounded-pill"><?php echo $count; ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </noscript>
    <?php endif; ?>

    </main>
  </div>

  <div class="modal fade" id="itemModal" tabindex="-1" aria-labelledby="itemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content" id="itemModalContent">
        <div class="modal-body text-center p-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2">Cargando datos...</p>
        </div>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  const tabs = document.querySelectorAll('#sheetsTabs .nav-link');
  const modalEl = document.getElementById('itemModal');
  const modalInstance = new bootstrap.Modal(modalEl);

  function getCurrentQ(){
    const headerQ = document.querySelector('.header-search input[name="q"]')?.value || '';
    const urlQ = new URLSearchParams(window.location.search).get('q') || '';
    return headerQ || urlQ || '';
  }

  function loadSheet(table) {
    const content = document.getElementById('sheetsContent');
    content.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p>Cargando hoja...</p></div>';
    
    const q = getCurrentQ();
    const params = new URLSearchParams({ name: table, partial: '1' });
    if (q) params.set('q', q);
    
    fetch('sheet.php?' + params.toString())
      .then(r => r.text())
      .then(html => {
        content.innerHTML = html;
        bindSheetRows(table);
      })
      .catch(e => {
        content.innerHTML = '<div class="alert alert-danger">Error cargando la hoja.</div>';
        console.error(e);
      });
  }

  window.refreshDetailModal = function(table, id) {
    const modalContent = document.getElementById('itemModalContent');
    if (!modalContent) return;
    
    fetch(`item.php?table=${encodeURIComponent(table)}&id=${encodeURIComponent(id)}`)
        .then(response => response.text())
        .then(html => {
            modalContent.innerHTML = html;
            if (typeof attachItemHandlers === 'function') {
                attachItemHandlers(modalContent);
            }
        });
  }

  function bindSheetRows(table){
    const container = document.getElementById('sheetsContent');
    if (!container) return;

    // Click en fila -> Abrir Modal
    container.querySelectorAll('.data-row').forEach(function(row){
      row.addEventListener('click', function(){
        const id = this.dataset.id;
        const modalContent = document.getElementById('itemModalContent');
        
        fetch('item.php?table=' + encodeURIComponent(table) + '&id=' + encodeURIComponent(id))
          .then(r => r.text())
          .then(html => {
            modalContent.innerHTML = html;
            
            // Ejecutar scripts inyectados para que funcionen botones de guardar/borrar
            modalContent.querySelectorAll('script').forEach(s => {
              const ns = document.createElement('script');
              if (s.src) { ns.src = s.src; }
              ns.text = s.textContent; 
              document.body.appendChild(ns); 
              document.body.removeChild(ns);
            });

            if (typeof attachItemHandlers === 'function') {
              try { attachItemHandlers(modalContent); } catch(e){ console.warn(e); }
            }
            modalInstance.show();
          })
          .catch(e => alert('Error cargando detalle'));
      });
    });

    // Botón Nuevo dentro del fragmento cargado
    const btnNew = container.querySelector('#btnNew');
    btnNew?.addEventListener('click', function(){
      const modalContent = document.getElementById('itemModalContent');
      fetch('item.php?table=' + encodeURIComponent(table) + '&new=1')
        .then(r => r.text())
        .then(html => {
          modalContent.innerHTML = html;
          modalContent.querySelectorAll('script').forEach(s => {
            const ns = document.createElement('script');
            if (s.src) { ns.src = s.src; }
            ns.text = s.textContent; 
            document.body.appendChild(ns); 
            document.body.removeChild(ns);
          });
          modalInstance.show();
        })
        .catch(e => alert('Error cargando formulario de creación'));
    });
  }

  if (tabs.length) {
    const active = document.querySelector('#sheetsTabs .nav-link.active');
    if (active) loadSheet(active.dataset.table);

    tabs.forEach(t => t.addEventListener('click', function(){ 
        loadSheet(this.dataset.table); 
    }));

    // Previews de las pestañas
    document.querySelectorAll('.tab-preview').forEach(function(el){
      const table = el.dataset.table;
      fetch('api_sheet_preview.php?table=' + encodeURIComponent(table))
        .then(r => r.json())
        .then(js => {
          if (!js.success) { el.textContent = '—'; return; }
          const sample = js.sample || {};
          let parts = [];
          let added = 0;
          for (const k in sample) {
            parts.push(k + ': ' + String(sample[k]).substring(0,12));
            if (++added >= 2) break;
          }
          el.textContent = parts.length === 0 ? 'Sin filas' : parts.join(' | ');
        })
        .catch(() => { el.textContent = 'Error'; });
    });

    // Búsqueda sin recargar página
    const headerForm = document.querySelector('.header-search');
    headerForm?.addEventListener('submit', function(e){
      e.preventDefault();
      const q = this.querySelector('input[name="q"]').value;
      const active = document.querySelector('#sheetsTabs .nav-link.active');
      
      // Corregido: sheets.php sin los puntos extra
      history.pushState(null, '', 'sheets.php' + (q ? '?q=' + encodeURIComponent(q) : ''));
      if (active) loadSheet(active.dataset.table);
    });
  }

  // Sidebar Toggle
  document.getElementById('sidebarToggle')?.addEventListener('click', function(){ 
      document.querySelector('.sidebar')?.classList.toggle('open'); 
  });
})();
</script>
</body>
</html>