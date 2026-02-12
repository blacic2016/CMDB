<?php
require_once  '../src/helpers.php';
require_once  '../src/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
$user = current_user();

$table = $_GET['table'] ?? '';
if (!isValidTableName($table)) { http_response_code(400); exit('Tabla inválida'); }

// Handle Edit Form Request
if (isset($_GET['form']) && $_GET['form'] == '1') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $row = getRowById($table, $id);
    if (!$row) { http_response_code(404); exit('Registro no encontrado'); }
    $cols = array_keys($row);
    ?>
    <div class="modal-header">
        <h5 class="modal-title">Editando <?php echo htmlspecialchars($table) . ' #' . $id; ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <form id="editFormInModal">
            <?php foreach ($cols as $c): ?>
                <?php if (in_array($c, ['id', '_row_hash', 'created_at', 'updated_at'])) continue; ?>
                <div class="mb-3">
                    <label for="field-<?php echo htmlspecialchars($c); ?>" class="form-label"><?php echo htmlspecialchars($c); ?></label>
                    <?php if ($c === 'estado_actual'): ?>
                        <select class="form-select" name="<?php echo htmlspecialchars($c); ?>" id="field-<?php echo htmlspecialchars($c); ?>">
                            <?php $options = ['USADO','ENTREGADO','NO_APARECE','DANADO']; ?>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo ($row[$c] === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" name="<?php echo htmlspecialchars($c); ?>" id="field-<?php echo htmlspecialchars($c); ?>" value="<?php echo htmlspecialchars($row[$c] ?? ''); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="saveChangesInModalBtn">Actualizar</button>
    </div>
    <script>
    (function(){
        var saveBtn = document.getElementById('saveChangesInModalBtn');
        saveBtn.addEventListener('click', function(){
            const form = document.getElementById('editFormInModal');
            const fd = new FormData(form);
            fd.append('action', 'update');
            fd.append('table', '<?php echo addslashes($table); ?>');
            fd.append('id', '<?php echo $id; ?>');

            saveBtn.disabled = true;
            saveBtn.textContent = 'Guardando...';

            fetch('api_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(js => {
                    if (js.success) {
                        var editModalEl = document.getElementById('editModal');
                        var editModal = bootstrap.Modal.getInstance(editModalEl);
                        if (editModal) editModal.hide();

                        if (window.refreshDetailModal) window.refreshDetailModal('<?php echo addslashes($table); ?>', <?php echo $id; ?>);
                        if (window.refreshSheet) window.refreshSheet();
                    } else {
                        alert(js.error || 'Error al actualizar');
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Actualizar';
                    }
                });
        });
    })();
    </script>
    <?php
    exit;
}

$new = isset($_GET['new']) && $_GET['new'] == '1';

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
if ($new) {
    // Render create form
    $cols = getTableColumns($table);
    if ($inline) {
      echo '<div><h5>Crear nuevo en ' . htmlspecialchars($table) . '</h5>';
      echo '<form id="formCreate">';
      foreach ($cols as $c) { if ($c=='id' || $c=='_row_hash' || $c=='created_at' || $c=='updated_at') continue;
        echo '<div class="mb-2"><label class="form-label">' . htmlspecialchars($c) . '</label>';
        echo '<input class="form-control" name="' . htmlspecialchars($c) . '"></div>';
      }
      echo '</form>';
      echo '<div class="mt-2 text-end"><button class="btn btn-secondary" id="cancelCreate">Cancelar</button> <button class="btn btn-primary" id="createBtnInline">Crear</button></div></div>';
    } else {
      ?>
      <div class="modal-header"><h5 class="modal-title">Crear nuevo en <?php echo htmlspecialchars($table); ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="formCreate">
          <?php foreach ($cols as $c): if ($c=='id' || $c=='_row_hash' || $c=='created_at' || $c=='updated_at') continue; ?>
            <div class="mb-2">
              <label class="form-label"><?php echo htmlspecialchars($c); ?></label>
              <input class="form-control" name="<?php echo htmlspecialchars($c); ?>">
            </div>
          <?php endforeach; ?>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="createBtn">Crear</button>
      </div>
      <?php
    }
    ?>
    <script>
    function afterCreateSuccess(){
      // refresh sheet list and clear detail pane
      if (window.refreshSheet) window.refreshSheet();
      if (window.loadDetail && !<?php echo $inline ? 'true' : 'false'; ?>) window.loadDetail();
    }
    document.getElementById('<?php echo $inline ? 'createBtnInline' : 'createBtn'; ?>').addEventListener('click', function(){
      const form = document.getElementById('formCreate');
      const data = new FormData(form);
      data.append('action','create'); data.append('table','<?php echo addslashes($table); ?>');
      fetch('api_action.php', { method:'POST', body: data })
        .then(r => r.json()).then(js => { if (js.success) { if (<?php echo $inline ? 'true' : 'false'; ?>) { window.refreshSheet(); document.getElementById('detailPane').innerHTML = '<div class="empty-note">Registro creado. Selecciona fila para ver detalles.</div>'; } else { var modalEl = document.getElementById('itemModal'); var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); bsModal.hide(); if (window.refreshSheet) window.refreshSheet(); } } else alert(js.error || 'Error'); });
    });
    document.getElementById('cancelCreate')?.addEventListener('click', function(){ document.getElementById('detailPane').innerHTML = '<div class="empty-note">Selecciona una fila...</div>'; });
    </script>
    <?php
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = getRowById($table, $id);
if (!$row) { http_response_code(404); exit('Registro no encontrado'); }

$cols = array_keys($row);
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
if ($inline) {
  echo '<div><div class="d-flex justify-content-between align-items-center"><h5>Detalle - ' . htmlspecialchars($table) . ' #' . $row['id'] . '</h5><div><button class="btn btn-sm btn-secondary" id="backToList">Volver</button></div></div>';
  echo '<table class="table mt-3"><tbody>';
  foreach ($cols as $c) {
    if ($c === '_row_hash') continue;
    echo '<tr><th>' . htmlspecialchars($c) . '</th><td>' . nl2br(htmlspecialchars($row[$c] ?? '')) . '</td></tr>';
  }
  echo '</tbody></table>';
  echo '<hr><h6>Imágenes</h6><div id="imagesList"></div>';
  if (has_role(['ADMIN','SUPER_ADMIN'])) {
    echo '<form id="imgFormInline" enctype="multipart/form-data"><div class="mb-2"><input type="file" name="image" accept="image/*" required></div><button type="button" class="btn btn-sm btn-secondary" id="uploadImgBtnInline">Subir imagen</button></form>';
  }
  if (has_role(['ADMIN','SUPER_ADMIN'])) {
    echo '<div class="mt-3"><button class="btn btn-danger" id="delBtnInline">Eliminar</button> <button class="btn btn-warning" id="deactBtnInline">Desactivar</button> <button class="btn btn-primary" id="editBtnInline">Editar</button></div>';
  }
  echo '</div>';
} else {
  ?>
  <div class="modal-header"><h5 class="modal-title">Detalle - <?php echo htmlspecialchars($table); ?> #<?php echo $row['id']; ?></h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <table class="table">
      <tbody>
        <?php foreach ($cols as $c): ?>
          <?php if ($c === '_row_hash') continue; ?>
          <tr>
            <th><?php echo htmlspecialchars($c); ?></th>
            <td><?php echo nl2br(htmlspecialchars($row[$c] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <hr>
    <h6>Imágenes</h6>
    <div id="imagesList">
      <!-- images will be fetched -->
    </div>
    <?php if (has_role(['ADMIN','SUPER_ADMIN'])): ?>
      <form id="imgForm" enctype="multipart/form-data">
        <div class="mb-2">
          <input type="file" name="image" accept="image/*" required>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" id="uploadImgBtn">Subir imagen</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="modal-footer">
    <?php if (has_role(['ADMIN','SUPER_ADMIN'])): ?>
      <a href="/history.php?table=<?php echo urlencode($table); ?>&id=<?php echo $row['id']; ?>" class="btn btn-info me-auto" target="_blank">Historial</a>
      <button class="btn btn-danger" id="delBtn">Eliminar</button>
      <button class="btn btn-warning" id="deactBtn">Desactivar</button>
      <button class="btn btn-primary" id="editBtn">Editar</button>
    <?php endif; ?>
    <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
  </div>
  <?php
}
?>

<script>
function fetchImages(){
  fetch('api_action.php?action=list_images&table=' + encodeURIComponent('<?php echo addslashes($table); ?>') + '&id=' + <?php echo $row['id']; ?>)
    .then(r => r.json()).then(js => {
      const el = document.getElementById('imagesList'); if (!el) return; el.innerHTML = '';
      js.forEach(img => {
        const div = document.createElement('div');
        div.innerHTML = '<a target="_blank" href="' + img.filepath + '"><img src="' + img.filepath + '" style="max-width:120px;max-height:80px;margin-right:8px"></a>';
        el.appendChild(div);
      });
    });
}
fetchImages();

function attachItemHandlers(root){
  root = root || document;
  if (root.__itemHandlersAttached) return; // prevent double attach
  root.__itemHandlersAttached = true;
  console.log('attachItemHandlers:', root);

  // use delegation where possible
  root.addEventListener('click', function(e){
    const t = e.target;
    // delete modal
    if (t.closest && t.closest('#delBtn')){
      if (!confirm('Eliminar este registro?')) return;
      const fd = new FormData(); fd.append('action','delete'); fd.append('table','<?php echo addslashes($table); ?>'); fd.append('id','<?php echo $row['id']; ?>');
      fetch('api_action.php', { method:'POST', body: fd }).then(r => r.json()).then(js => { if (js.success) { var modalEl = document.getElementById('itemModal'); var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); bsModal.hide(); if (window.refreshSheet) window.refreshSheet(); } else alert(js.error); });
      return;
    }
    // delete inline
    if (t.closest && t.closest('#delBtnInline')){
      if (!confirm('Eliminar este registro?')) return;
      const fd = new FormData(); fd.append('action','delete'); fd.append('table','<?php echo addslashes($table); ?>'); fd.append('id','<?php echo $row['id']; ?>');
      fetch('api_action.php', { method:'POST', body: fd }).then(r => r.json()).then(js => { if (js.success) { if (window.refreshSheet) window.refreshSheet(); document.getElementById('detailPane').innerHTML = '<div class="empty-note">Registro eliminado.</div>'; } else alert(js.error); });
      return;
    }

    // deactivate modal
    if (t.closest && t.closest('#deactBtn')){
      if (!confirm('Desactivar este registro?')) return;
      const fd = new FormData(); fd.append('action','deactivate'); fd.append('table','<?php echo addslashes($table); ?>'); fd.append('id','<?php echo $row['id']; ?>');
      fetch('api_action.php', { method:'POST', body: fd }).then(r => r.json()).then(js => { if (js.success) { var modalEl = document.getElementById('itemModal'); var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); bsModal.hide(); if (window.refreshSheet) window.refreshSheet(); } else alert(js.error); });
      return;
    }

    // deactivate inline
    if (t.closest && t.closest('#deactBtnInline')){
      if (!confirm('Desactivar este registro?')) return;
      const fd = new FormData(); fd.append('action','deactivate'); fd.append('table','<?php echo addslashes($table); ?>'); fd.append('id','<?php echo $row['id']; ?>');
      fetch('api_action.php', { method:'POST', body: fd }).then(r => r.json()).then(js => { if (js.success) { if (window.refreshSheet) window.refreshSheet(); document.getElementById('detailPane').innerHTML = '<div class="empty-note">Registro desactivado.</div>'; } else alert(js.error); });
      return;
    }

    // edit modal
    if (t.closest && t.closest('#editBtn')){
        e.preventDefault();
        
        const editModalContent = document.getElementById('editModalContent');
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));

        // Fetch the form content
        fetch(`item.php?table=<?php echo addslashes($table); ?>&id=<?php echo $row['id']; ?>&form=1`)
            .then(response => response.text())
            .then(html => {
                editModalContent.innerHTML = html;
                // Execute scripts in the fetched HTML to attach form handlers
                editModalContent.querySelectorAll('script').forEach(s => {
                    const newScript = document.createElement('script');
                    newScript.text = s.innerText;
                    document.body.appendChild(newScript).parentNode.removeChild(newScript);
                });
                editModal.show();
            });
        return;
    }

    // edit inline (This logic remains for now but is secondary)
    if (t.closest && t.closest('#editBtnInline')){
      const container = document.getElementById('detailPane');
      const fields = container.querySelectorAll('table tbody tr td');
      const fd = new FormData();
      fields.forEach(td => {
        const input = td.querySelector('input, select');
        if (input) {
          fd.append(input.name, input.value);
        }
      });
      fd.append('action', 'update');
      fd.append('table', '<?php echo addslashes($table); ?>');
      fd.append('id', '<?php echo $row['id']; ?>');
      
      fetch('api_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(js => {
          if (js.success) {
            if (window.refreshSheet) window.refreshSheet();
            document.getElementById('detailPane').innerHTML = '<div class="empty-note">Selecciona una fila...</div>';
          } else {
            alert(js.error || 'Error al actualizar');
          }
        });
      return;
    }

    // upload modal
    if (t.closest && t.closest('#uploadImgBtn')){
      console.log('Upload button clicked.');
      const form = root.querySelector('#imgForm');
      if (!form) { console.error('Image form not found'); return; }
      const fileInput = form.querySelector('input[type="file"]');
      
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
          alert('Por favor, selecciona una imagen para subir.');
          return;
      }
      
      const fd = new FormData(form);
      fd.append('table', '<?php echo addslashes($table); ?>');
      fd.append('id', '<?php echo $row['id']; ?>');
      
      console.log('Uploading file:', fileInput.files[0].name);
      
      fetch('upload_image.php', { method: 'POST', body: fd })
      .then(r => {
          console.log('Upload response status:', r.status);
          if (!r.ok) {
              return r.text().then(text => { throw new Error('Server error: ' + text) });
          }
          return r.json();
      })
      .then(js => {
          console.log('Upload response JSON:', js);
          if (js.success) {
              fetchImages(); // Refresh the image list
          } else {
              alert('Error al subir imagen: ' + js.error);
          }
      })
      .catch(err => {
          console.error('Upload fetch error:', err);
          alert('Ocurrió un error de red o del servidor al subir la imagen.');
      });
      return;
    }

    // back to list handler (inline)
    if (t.closest && t.closest('#backToList')){
      document.getElementById('detailPane').innerHTML = '<div class="empty-note">Selecciona una fila...</div>';
      document.querySelectorAll('.data-row').forEach(r=>r.classList.remove('table-active'));
      return;
    }
  }, false);

}

// run once for inlined page context
attachItemHandlers(document);

// A helper function to escape HTML for use in JS value attributes
function htmlspecialchars(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'.. '&#039;');
}
</script>