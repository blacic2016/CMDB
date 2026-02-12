<?php
/**
 * CMDB VILASECA - Crear Monitoreo en Zabbix
 * Ubicación: /var/www/html/Sonda/public/crear_monitoreo.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

// Protección de sesión
require_login(); 

$page_title = 'Crear Monitoreo';

// 1. Obtener tablas habilitadas
try {
    $pdo = getPDO();
    if (!$pdo) throw new Exception("No se pudo establecer conexión con la base de datos.");
    
    $stmt = $pdo->query("SELECT table_name FROM zabbix_cmdb_config WHERE is_enabled = 1 ORDER BY table_name ASC");
    $enabled_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error en Monitoreo: " . $e->getMessage());
    $enabled_tables = [];
    $error_db = "Error de conexión: " . $e->getMessage();
}

require_once __DIR__ . '/partials/header.php'; 
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .select2-container--default .select2-selection--multiple { border-color: #ced4da; }
    .select2-container .select2-selection--multiple { min-height: 38px; }
    .table-head-fixed th { position: sticky; top: 0; background: #eee; z-index: 10; }
    #action-bar { display: none; position: sticky; top: 20px; z-index: 1020; border-left: 5px solid #007bff; }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1>Crear Monitoreo en Zabbix</h1>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-database mr-1"></i> Selección de Origen CMDB</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error_db)): ?>
                        <div class="alert alert-danger"><?php echo $error_db; ?></div>
                    <?php elseif (empty($enabled_tables)): ?>
                        <div class="alert alert-warning">
                            <h5><i class="icon fas fa-exclamation-triangle"></i> No hay tablas configuradas</h5>
                            Habilita las tablas en la configuración de la CMDB.
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Selecciona tablas de la CMDB:</label>
                            <select class="form-control" id="cmdbTableSelector" multiple="multiple">
                                <?php foreach ($enabled_tables as $table): ?>
                                    <option value="<?php echo htmlspecialchars($table); ?>">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('sheet_', '', $table))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
  
<div id="monitoring-dashboard" style="display: none;">
    <div class="row">
        <div class="col-md-3">
            <div class="small-box bg-success shadow-sm">
                <div class="inner">
                    <h3 id="stat-monitored">0</h3>
                    <p>Monitoreados</p>
                </div>
                <div class="icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-secondary shadow-sm">
                <div class="inner">
                    <h3 id="stat-unmonitored">0</h3>
                    <p>No Monitoreados</p>
                </div>
                <div class="icon"><i class="fas fa-times-circle"></i></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h3 class="card-title text-sm"><i class="fas fa-filter mr-1"></i> Filtrar Vista</h3>
                </div>
                <div class="card-body p-2">
                    <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
    <label class="btn btn-outline-primary active">
        <input type="radio" name="status-filter" value="all"> Todos
    </label>
    <label class="btn btn-outline-success">
        <input type="radio" name="status-filter" value="Monitoreado"> Solo Monitoreados
    </label>
    <label class="btn btn-outline-secondary">
        <input type="radio" name="status-filter" value="No Monitoreado"> No Monitoreados
    </label>
</div>
                </div>
            </div>
        </div>
    </div>
</div>





            <div class="card card-body shadow-sm" id="action-bar">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong id="selected-count">0</strong> equipos seleccionados.
                    </div>
                    <div>
                        <button class="btn btn-primary" id="manage-monitoring-btn">
                            <i class="fas fa-cogs mr-1"></i> Configurar Mapeo
                        </button>
                        <button class="btn btn-success ml-2" id="run-bulk-creation">
                            <i class="fas fa-rocket mr-1"></i> Crear en Zabbix
                        </button>
                        <button class="btn btn-danger ml-2" id="run-bulk-delete">
                <i class="fas fa-trash-alt mr-1"></i> Borrar de Zabbix
            </button>
                    </div>
                </div>
            </div>

            <div id="results-container" class="mt-4"></div>
        </div>
    </section>
</div>

<div class="modal fade" id="zabbixMappingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Configuración de Mapeo: <span id="modal-cmdb-table-name"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="modal-form-content">
                <div class="text-center p-5"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success" id="save-mapping-btn">
                    <i class="fas fa-save mr-1"></i> Guardar Configuración
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(function() {
    // 1. Inicializar Select2
    $('#cmdbTableSelector').select2({ 
        placeholder: "Selecciona tablas...", 
        allowClear: true,
        width: '100%'
    });

    // 2. Cargar datos de las tablas al seleccionar
   // 2. Cargar datos de las tablas al seleccionar
$('#cmdbTableSelector').on('change', function() {
    const selectedTables = $(this).val();
    const container = $('#results-container');
    const dashboard = $('#monitoring-dashboard');
    
    // Si no hay nada seleccionado, limpiamos todo y ocultamos
    if (!selectedTables || selectedTables.length === 0) {
        container.html('');
        dashboard.fadeOut();
        $('#action-bar').fadeOut();
        return;
    }

    // Mostrar estado de carga
    container.html(`
        <div class="text-center p-5">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2">Sincronizando con Zabbix y calculando estadísticas...</p>
        </div>
    `);




// --- COPIAR DESDE AQUÍ ---
   // Lógica de Filtrado Inteligente para Monitoreado, Monitoreado (ID) y No Monitoreado
$(document).on('click', '[data-toggle="buttons"] .btn', function() {
    const filterValue = $(this).find('input').val().toLowerCase(); // 'all', 'monitoreado' o 'no monitoreado'
    
    setTimeout(() => {
        $('.table tbody tr').each(function() {
            // Obtenemos el texto del badge, lo pasamos a minúsculas y quitamos espacios
            const rowStatus = $(this).find('.badge').text().toLowerCase().trim();
            
            if (filterValue === 'all') {
                $(this).show();
            } 
            else if (filterValue === 'monitoreado') {
                // Si el filtro es "monitoreado", mostramos si el badge contiene la palabra "monitoreado"
                // PERO NO contiene la palabra "no". Esto atrapa "Monitoreado" y "Monitoreado (ID)"
                if (rowStatus.includes('monitoreado') && !rowStatus.includes('no')) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            } 
            else if (filterValue === 'no monitoreado') {
                // Si el filtro es "no monitoreado", buscamos la coincidencia exacta de "no monitoreado"
                if (rowStatus.includes('no monitoreado') || rowStatus === 'no monitoreado') {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            }
        });
    }, 60);
});





    const formData = new FormData();
    formData.append('action', 'get_cmdb_data_for_zabbix');
    selectedTables.forEach(t => formData.append('tables[]', t));

    fetch('api_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            container.html(''); // Limpiar el spinner

            if (data.success) {
                // 1. Renderizar cada tabla seleccionada
                for (const tableName in data.data) {
                    renderCmdbTable(tableName, data.data[tableName], container);
                }

                // 2. Actualizar los contadores del Dashboard
                const totalMonitored = $('.badge-success, .badge-info').length;
                const totalUnmonitored = $('.badge-secondary').length;

                $('#stat-monitored').text(totalMonitored);
                $('#stat-unmonitored').text(totalUnmonitored);
                
                // 3. Mostrar el dashboard con animación
                dashboard.fadeIn();

                // 4. Resetear el filtro visual a "Todos" por defecto
                $('input[name="status-filter"][value="all"]').parent().addClass('active').siblings().removeClass('active');
                
            } else {
                container.html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>' + data.error + '</div>');
                dashboard.fadeOut();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            container.html('<div class="alert alert-danger">Error crítico al conectar con el servidor.</div>');
        });
});




    // 3. Renderizar tablas CMDB
  function renderCmdbTable(name, info, container) {
    // Filtrar columnas internas (las que empiezan con _)
    const visibleColumns = info.columns.filter(c => !c.startsWith('_'));
    
    let html = `
        <div class="card card-outline card-info mt-3 shadow">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-table mr-1"></i>
                    <b>${name.replace('sheet_', '').toUpperCase()}</b>
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            </div>
            <div class="card-body table-responsive p-0" style="max-height: 450px;">
                <table class="table table-sm table-head-fixed table-hover" id="table-${name}">
                    <thead>
                        <tr>
                            <th style="width: 40px" class="text-center">
                                <input type="checkbox" class="select-all" data-target="table-${name}">
                            </th>
                            <th style="width: 200px">Estado en Zabbix</th>
                            ${visibleColumns.map(col => `<th>${col.toUpperCase()}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>`;
    
    if (info.rows.length === 0) {
        html += `<tr><td colspan="${visibleColumns.length + 2}" class="text-center p-3 text-muted">No hay datos disponibles</td></tr>`;
    }

    info.rows.forEach(row => {
        const status = row._zabbix_status || 'No Monitoreado';
        const zabbixId = row.zabbix_host_id;
        
        // Determinar color del badge
        let badgeClass = 'secondary';
        if (status === 'Monitoreado') badgeClass = 'success';
        if (status.includes('ID')) badgeClass = 'info';

        // Botón de borrado (solo si existe ID de Zabbix)
        const deleteBtn = zabbixId 
            ? `<button class="btn btn-xs btn-outline-danger delete-zabbix-host ml-2" 
                       data-id="${row.id}" 
                       data-zabbix-id="${zabbixId}" 
                       data-table="${name}"
                       title="Eliminar de Zabbix">
                    <i class="fas fa-trash-alt"></i>
               </button>` 
            : '';

        html += `
            <tr>
                <td class="text-center">
                    <input type="checkbox" class="select-row" data-id="${row.id}">
                </td>
                <td class="align-middle">
                    <div class="d-flex align-items-center">
                        <span class="badge badge-${badgeClass}">${status}</span>
                        ${deleteBtn}
                    </div>
                </td>
                ${visibleColumns.map(col => `<td>${row[col] || '<span class="text-muted">-</span>'}</td>`).join('')}
            </tr>`;
    });

    html += `</tbody></table></div></div>`;
    container.append(html);
}

    // 4. Gestión de Selección
    $(document).on('change', '.select-all', function() {
        const target = $(this).data('target');
        $(`#${target} .select-row`).prop('checked', this.checked).trigger('change');
    });

    $(document).on('change', '.select-row', function() {
        const count = $('.select-row:checked').length;
        $('#selected-count').text(count);
        count > 0 ? $('#action-bar').fadeIn() : $('#action-bar').fadeOut();
    });

    // 5. Configurar Mapeo (Cargar Modal)
    $(document).on('click', '#manage-monitoring-btn', function() {
        const tableName = $('#cmdbTableSelector').val()[0];
        if (!tableName) return;

        $('#modal-cmdb-table-name').text(tableName.toUpperCase());
        $('#zabbixMappingModal').modal('show');
        $('#modal-form-content').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div></div>');

        $.get('api_action.php', { action: 'get_mapping_form', table: tableName }, function(html) {
            $('#modal-form-content').html(html);
        });
    });

    // 6. Guardar Mapeo
    $('#save-mapping-btn').on('click', function() {
        const form = $('#mapping-form');
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

        $.post('api_action.php?action=save_zabbix_mapping', form.serialize(), function(res) {
            if (res.success) {
                Swal.fire('Guardado', 'Mapeo actualizado con éxito', 'success');
                $('#zabbixMappingModal').modal('hide');
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        }, 'json').always(() => btn.prop('disabled', false).text('Guardar Configuración'));
    });

    // 7. CREACIÓN MASIVA (BULK) EN ZABBIX
    $('#run-bulk-creation').on('click', async function() {
        const selectedRows = $('.select-row:checked');
        const total = selectedRows.length;
        const tableName = $('#cmdbTableSelector').val()[0];

        const confirm = await Swal.fire({
            title: '¿Crear hosts en Zabbix?',
            text: `Se procesarán ${total} equipos seleccionados.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, empezar'
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({
            title: 'Procesando API Zabbix...',
            html: 'Progreso: <b>0</b> de ' + total + '<br><small id="bulk-log">Iniciando...</small>',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        let ok = 0; let err = 0;

        for (let i = 0; i < total; i++) {
            const rowId = $(selectedRows[i]).data('id');
            try {
                // Envío al servidor
                const res = await $.post('api_action.php', { 
                    action: 'create_zabbix_host', 
                    table_name: tableName, 
                    row_id: rowId 
                });

                if (res.success) {
                    ok++;
                    console.log("Éxito ID " + rowId + ":", res.log);
                } else {
                    err++;
                    console.error("Fallo ID " + rowId + ":", res.log);
                }
                $('#bulk-log').text('Último: ' + (res.log || 'Procesado'));
            } catch (e) {
                err++;
                console.error("Error de conexión en ID " + rowId);
            }
            // Actualizar contador del modal
            Swal.getHtmlContainer().querySelector('b').textContent = (ok + err);
        }

        Swal.fire('Finalizado', `Completados: ${ok} | Errores: ${err}`, err > 0 ? 'warning' : 'success');
        $('#cmdbTableSelector').trigger('change'); // Refrescar tablas
    });

// Manejador para el botón de eliminar individual
$(document).on('click', '.delete-zabbix-host', function() {
    const btn = $(this);
    const rowId = btn.data('id');
    const zabbixId = btn.data('zabbix-id');
    const tableName = btn.data('table'); // Obtenido del atributo data-table que pusimos en render

    Swal.fire({
        title: '¿Eliminar de Zabbix?',
        text: "El monitoreo se detendrá y el host será borrado de Zabbix. Los datos en la CMDB permanecerán intactos.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, eliminar host',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            // Mostrar carga
            Swal.fire({
                title: 'Procesando...',
                html: 'Comunicando con la API de Zabbix',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            // Petición AJAX al backend
            $.post('api_action.php', {
                action: 'delete_zabbix_host',
                table_name: tableName,
                row_id: rowId,
                zabbix_host_id: zabbixId
            }, function(res) {
                if (res.success) {
                    Swal.fire('Eliminado', 'El host ha sido removido de Zabbix correctamente.', 'success');
                    // Refrescar la tabla para actualizar el estado visualmente
                    $('#cmdbTableSelector').trigger('change'); 
                } else {
                    Swal.fire('Error', 'No se pudo eliminar: ' + res.error, 'error');
                }
            }, 'json').fail(function() {
                Swal.fire('Error Critico', 'No se pudo contactar con el servidor (api_action.php).', 'error');
            });
        }
    });
});

// 8. BORRADO MASIVO EN ZABBIX
$('#run-bulk-delete').on('click', async function() {
    const selectedRows = $('.select-row:checked');
    const total = selectedRows.length;
    const tableName = $('#cmdbTableSelector').val()[0];

    // Filtrar solo los que realmente tienen un ID de Zabbix (evita errores)
    let hostsToDelete = [];
    selectedRows.each(function() {
        const zabbixId = $(this).closest('tr').find('.delete-zabbix-host').data('zabbix-id');
        if (zabbixId) {
            hostsToDelete.push({
                rowId: $(this).data('id'),
                zabbixId: zabbixId
            });
        }
    });

    if (hostsToDelete.length === 0) {
        Swal.fire('Atención', 'Ninguno de los equipos seleccionados está monitoreado en Zabbix.', 'info');
        return;
    }

    const confirm = await Swal.fire({
        title: '¿Borrado Masivo de Zabbix?',
        text: `Se eliminarán ${hostsToDelete.length} equipos del monitoreo. Esta acción no se puede deshacer en Zabbix.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, borrar todo'
    });

    if (!confirm.isConfirmed) return;

    Swal.fire({
        title: 'Eliminando de Zabbix...',
        html: 'Progreso: <b>0</b> de ' + hostsToDelete.length + '<br><small id="bulk-log">Iniciando proceso...</small>',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    let ok = 0; let err = 0;

    for (let i = 0; i < hostsToDelete.length; i++) {
        const item = hostsToDelete[i];
        try {
            const res = await $.post('api_action.php', { 
                action: 'delete_zabbix_host', 
                table_name: tableName, 
                row_id: item.rowId,
                zabbix_host_id: item.zabbixId
            });

            if (res.success) {
                ok++;
            } else {
                err++;
                console.error("Fallo al borrar ID " + item.rowId + ":", res.error);
            }
            $('#bulk-log').text('Último procesado: ID ' + item.rowId);
        } catch (e) {
            err++;
        }
        // Actualizar contador en el modal
        Swal.getHtmlContainer().querySelector('b').textContent = (ok + err);
    }

    await Swal.fire('Proceso Finalizado', `Hosts eliminados: ${ok} | Errores: ${err}`, err > 0 ? 'warning' : 'success');
    $('#cmdbTableSelector').trigger('change'); // Refrescar vista
});

function updateDashboardStats() {
    const totalMonitored = $('.badge-success, .badge-info').length;
    const totalUnmonitored = $('.badge-secondary').length;

    $('#stat-monitored').text(totalMonitored);
    $('#stat-unmonitored').text(totalUnmonitored);
    
    if (totalMonitored + totalUnmonitored > 0) {
        $('#monitoring-dashboard').fadeIn();
    }
}

// Lógica de Filtrado por Radio Buttons
$(document).on('change', 'input[name="status-filter"]', function() {
    const filter = $(this).val();
    
    $('.table tbody tr').each(function() {
        const rowStatus = $(this).find('.badge').text().trim();
        
        if (filter === 'all') {
            $(this).show();
        } else if (filter === 'Monitoreado') {
            // Incluye "Monitoreado" y "Monitoreado (ID)"
            rowStatus.includes('Monitoreado') ? $(this).show() : $(this).hide();
        } else {
            rowStatus === 'No Monitoreado' ? $(this).show() : $(this).hide();
        }
    });
});




});
</script>