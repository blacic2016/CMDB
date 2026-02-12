<?php
/**
 * CMDB VILASECA - Panel de Monitoreo Zabbix
 * Ubicación: /var/www/html/Sonda/public/monitoreo.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/zabbix_api.php'; // Manejador centralizado de la API

// Protección de sesión obligatoria
require_login(); 

// --- Configuración y Variables de Estado ---
$host_count = '0';
$item_count = '0';
$api_error = null;
$problems_by_severity = [];

/**
 * 1. Obtener conteo de Hosts y Items desde la API de Zabbix (172.32.1.50)
 */
try {
    // Conteo de Hosts
    $res_hosts = call_zabbix_api('host.get', ['countOutput' => true]);
    if (isset($res_hosts['error'])) {
        throw new Exception($res_hosts['error']['data'] ?? "Error al consultar hosts.");
    }
    $host_count = $res_hosts['result'];

    // Conteo de Items monitoreados
    $res_items = call_zabbix_api('item.get', ['countOutput' => true, 'monitored' => true]);
    if (!isset($res_items['error'])) {
        $item_count = $res_items['result'];
    }

    /**
     * 2. Obtener Problemas por Severidad (Triggers en estado PROBLEM)
     */
    $res_triggers = call_zabbix_api('trigger.get', [
        'output' => ['priority'],
        'only_true' => 1, // Solo disparadores en estado de problema
        'monitored' => 1
    ]);

    if (isset($res_triggers['error'])) {
        throw new Exception($res_triggers['error']['data']);
    }

    // Inicializar matriz de severidades (Estándar Zabbix)
    $severities = [
        0 => ['name' => 'No clasificado', 'count' => 0, 'bg' => 'secondary'],
        1 => ['name' => 'Información',   'count' => 0, 'bg' => 'info'],
        2 => ['name' => 'Advertencia',   'count' => 0, 'bg' => 'warning'],
        3 => ['name' => 'Promedio',      'count' => 0, 'bg' => 'orange'],
        4 => ['name' => 'Alta',          'count' => 0, 'bg' => 'danger'],
        5 => ['name' => 'Desastre',      'count' => 0, 'bg' => 'maroon']
    ];

    foreach ($res_triggers['result'] as $trigger) {
        $priority = (int)$trigger['priority'];
        if (isset($severities[$priority])) {
            $severities[$priority]['count']++;
        }
    }
    $problems_by_severity = $severities;

} catch (Exception $e) {
    $api_error = $e->getMessage();
    error_log("Zabbix API Error: " . $api_error);
}

$page_title = 'Monitoreo';
require_once __DIR__ . '/partials/header.php'; 
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1>Panel de Monitoreo Zabbix</h1>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($api_error): ?>
                <div class="alert alert-danger shadow">
                    <h5><i class="icon fas fa-ban"></i> Error de Comunicación (Zabbix API)</h5>
                    El servidor <strong>172.32.1.50</strong> no respondió correctamente: <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php endif; ?>

            <div class="card card-danger card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-1"></i> Problemas Activos por Severidad</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach($problems_by_severity as $id => $sev): ?>
                            <?php if($sev['count'] > 0): ?>
                                <div class="col-lg-2 col-md-4 col-6">
                                    <div class="small-box bg-<?php echo $sev['bg']; ?>">
                                        <div class="inner">
                                            <h3><?php echo $sev['count']; ?></h3>
                                            <p><?php echo $sev['name']; ?></p>
                                        </div>
                                        <div class="icon"><i class="fas fa-bell"></i></div>
                                        <a href="problems.php?severity=<?php echo $id; ?>" class="small-box-footer">Ver detalles <i class="fas fa-arrow-circle-right"></i></a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <?php if (empty(array_filter($problems_by_severity, function($s) { return $s['count'] > 0; }))): ?>
                            <div class="col-12 text-center py-3">
                                <p class="text-success"><i class="fas fa-check-circle"></i> No se detectan problemas activos en este momento.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo htmlspecialchars($host_count); ?></h3>
                            <p>Equipos Monitoreados</p>
                        </div>
                        <div class="icon"><i class="fas fa-desktop"></i></div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo htmlspecialchars($item_count); ?></h3>
                            <p>Items Totales</p>
                        </div>
                        <div class="icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-primary">
                        <div class="inner">
                            <h3>+</h3>
                            <p>Nueva Tarea de Monitoreo</p>
                        </div>
                        <div class="icon"><i class="fas fa-plus-circle"></i></div>
                        <a href="crear_monitoreo.php" class="small-box-footer">Ir a creador <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>