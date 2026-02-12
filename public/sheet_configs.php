<?php
/**
 * CMDB VILASECA - Configuración de Claves Únicas y Zabbix
 * Ubicación: /var/www/html/Sonda/public/sheet_configs.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';

// Protección de sesión: Solo SUPER_ADMIN puede gestionar claves
if (session_status() === PHP_SESSION_NONE) session_start();
require_login();
if (!has_role(['SUPER_ADMIN'])) {
    die("Acceso denegado: Se requieren permisos de Super Administrador.");
}

$page_title = 'Configuración de Claves Únicas';
require_once __DIR__ . '/partials/header.php';

$pdo = getPDO();
$tables = listSheetTables(); // Listar tablas que empiezan con 'sheet_'

// 1. Cargar configuraciones de claves únicas existentes
$configs = [];
$stmt = $pdo->query("SELECT table_name, unique_columns FROM sheet_configs");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $configs[$r['table_name']] = json_decode($r['unique_columns'], true) ?: [];
}

// 2. Cargar tablas habilitadas para el Monitoreo Zabbix
$zabbix_enabled_tables = [];
$stmt_zabbix = $pdo->query("SELECT table_name FROM zabbix_cmdb_config WHERE is_enabled = 1");
while ($r = $stmt_zabbix->fetch(PDO::FETCH_ASSOC)) {
    $zabbix_enabled_tables[] = $r['table_name'];
}
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Configuración Maestro de Tablas</h1>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <div class="card card-outline card-primary shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-heartbeat mr-2"></i> Tablas Visibles en Monitoreo Zabbix</h3>
                </div>
                <div class="card-body">
                    <form id="zabbixCmdbConfigForm">
                        <p class="text-muted">Marca las tablas que el equipo técnico de SONDA podrá utilizar para crear hosts en Zabbix automáticamente.</p>
                        <div class="row">
                            <?php foreach ($tables as $t): ?>
                                <div class="col-md-3 mb-2">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" 
                                               id="zbx_<?php echo $t; ?>" name="tables[]" value="<?php echo $t; ?>"
                                               <?php echo in_array($t, $zabbix_enabled_tables) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="zbx_<?php echo $t; ?>">
                                            <?php echo htmlspecialchars(str_replace('sheet_', '', $t)); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <hr>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save mr-1"></i> Actualizar Visibilidad Zabbix
                        </button>
                    </form>
                </div>
            </div>

            <h4 class="mb-3 mt-5 text-secondary"><i class="fas fa-key mr-2"></i> Definición de Claves Únicas para Importación</h4>
            <p class="text-muted">Selecciona las columnas que identifican de forma única a un activo. Esto evita duplicados durante la carga de archivos Excel.</p>

            <?php if (empty($tables)): ?>
                <div class="alert alert-info">No se detectaron tablas de inventario (prefijo sheet_) en la base de datos.</div>
            <?php else: ?>
                <?php foreach ($tables as $t): 
                    $cols = getTableColumns($t); 
                    $existing = $configs[$t] ?? []; 
                ?>
                    <div class="card card-outline card-secondary mb-3 shadow-sm">
                        <div class="card-header bg-light">
                            <h3 class="card-title text-bold text-uppercase"><?php echo str_replace('sheet_', '', $t); ?></h3>
                        </div>
                        <div class="card-body">
                            <form class="configForm" data-table="<?php echo htmlspecialchars($t); ?>">
                                <div class="row">
                                    <?php foreach ($cols as $c): 
                                        if (in_array($c, ['id','_row_hash','created_at','updated_at'])) continue; 
                                    ?>
                                        <div class="col-md-3 form-check mb-1">
                                            <input class="form-check-input" type="checkbox" value="<?php echo htmlspecialchars($c); ?>" 
                                                   id="<?php echo $t . '_' . $c; ?>" name="cols[]" 
                                                   <?php echo in_array($c, $existing) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="<?php echo $t . '_' . $c; ?>">
                                                <?php echo htmlspecialchars($c); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-3 d-flex align-items-center">
                                    <button type="submit" class="btn btn-success btn-sm mr-2">
                                        <i class="fas fa-check mr-1"></i> Guardar Claves
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm btnDelete">
                                        <i class="fas fa-trash-alt mr-1"></i> Limpiar
                                    </button>
                                    <?php if(!empty($existing)): ?>
                                        <span class="ml-3 badge badge-info p-2">
                                            <i class="fas fa-info-circle mr-1"></i> Activo: <?php echo implode(', ', $existing); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Guardar Configuración de visibilidad para Zabbix
    const zabbixForm = document.getElementById('zabbixCmdbConfigForm');
    if(zabbixForm) {
        zabbixForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_zabbix_cmdb_config');

            fetch('api_action.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toastr?.success('Configuración de Zabbix actualizada.');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(err => alert('Error de conexión con api_action.php'));
        });
    }

    // 2. Guardar Claves Únicas por Tabla
    document.querySelectorAll('.configForm').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const table = this.dataset.table;
            const data = new FormData(this);
            data.append('table', table);
            data.append('action', 'save');

            fetch('api_config_sheets.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(js => { 
                if (js.success) { 
                    location.reload(); 
                } else {
                    alert(js.error || 'Error al guardar'); 
                }
            })
            .catch(() => alert('Error de red al conectar con api_config_sheets.php'));
        });

        // 3. Eliminar Configuración de Claves
        form.querySelector('.btnDelete').addEventListener('click', function(){
            if (!confirm('¿Deseas eliminar la configuración de claves para esta tabla?')) return;
            const table = form.dataset.table; 
            const data = new FormData(); 
            data.append('table', table); 
            data.append('action','delete');

            fetch('api_config_sheets.php', { method:'POST', body: data })
            .then(r => r.json())
            .then(js => { 
                if (js.success) location.reload(); 
                else alert(js.error); 
            });
        });
    });
});
</script>