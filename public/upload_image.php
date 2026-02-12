<?php
// filepath: /var/www/html/VILASECA/CMDB/public/zabbix_triggers.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/zabbix_api.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

// Formatea duración
function format_zabbix_duration($seconds) {
    if (!is_numeric($seconds) || $seconds < 0) return "0s";
    if ($seconds < 60) return "{$seconds}s";
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    if ($m < 60) return "{$m}m {$s}s";
    $h = floor($m / 60);
    if ($h < 24) return "{$h}h " . ($m % 60) . "m";
    $d = floor($h / 24);
    return "{$d}d " . ($h % 24) . "h";
}

// Configuración de severidades
$severities = [
    0 => ['name' => 'Not classified', 'bg' => 'secondary'], 
    1 => ['name' => 'Information', 'bg' => 'info'],
    2 => ['name' => 'Warning', 'bg' => 'warning'], 
    3 => ['name' => 'Average', 'bg' => 'orange'],
    4 => ['name' => 'High', 'bg' => 'danger'], 
    5 => ['name' => 'Disaster', 'bg' => 'maroon']
];

// Validar severity
$severity_id = isset($_GET['severity']) ? (int)$_GET['severity'] : -1;
if ($severity_id < 0 || $severity_id > 5) {
    http_response_code(400);
    die("Nivel de severidad no válido.");
}

$severity_name = $severities[$severity_id]['name'];
$problems = [];
$api_error = null;

// Llamada a Zabbix API
$response = call_zabbix_api('trigger.get', [
    'output' => ['triggerid', 'description', 'priority', 'lastchange'],
    'selectHosts' => ['hostid', 'name', 'host'],
    'only_true' => 1, 
    'monitored' => 1,
    'expandDescription' => 1,
    'filter' => [
        'priority' => $severity_id,
        'value' => 1
    ],
    'sortfield' => 'lastchange',
    'sortorder' => 'DESC'
]);

if (!is_array($response) || isset($response['error'])) {
    $api_error = is_array($response['error'] ?? null) 
        ? ($response['error']['data'] ?? $response['error']['message'] ?? 'Error desconocido') 
        : 'Error de conexión con Zabbix';
} else {
    $problems = $response['result'] ?? [];
}

$page_title = 'Zabbix - ' . htmlspecialchars($severity_name);
require_once __DIR__ . '/../partials/header.php'; 
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Severidad: <span class="badge bg-<?php echo $severities[$severity_id]['bg']; ?>">
                            <?php echo htmlspecialchars($severity_name); ?>
                        </span>
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="monitoreo.php">Monitoreo</a></li>
                        <li class="breadcrumb-item active">Detalle</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <?php if ($api_error): ?>
                <div class="alert alert-danger shadow">
                    <h5><i class="icon fas fa-ban"></i> Error de Zabbix</h5>
                    <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php else: ?>
                <div class="card card-outline card-<?php echo $severities[$severity_id]['bg']; ?> shadow">
                    <div class="card-header border-0">
                        <h3 class="card-title text-bold">Triggers Activos en Sonda CMDB</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-valign-middle m-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%">Duración</th>
                                        <th style="width: 25%">Host / Equipo</th>
                                        <th>Incidencia</th>
                                        <th style="width: 10%" class="text-center">Enlace</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($problems)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center p-5">
                                                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                                                <p class="text-muted">No hay problemas reportados con esta severidad.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($problems as $problem): 
                                            // Validar que exista hosts
                                            if (empty($problem['hosts']) || !is_array($problem['hosts'])) {
                                                continue;
                                            }
                                            $host = $problem['hosts'][0];
                                            $hostName = !empty($host['name']) ? $host['name'] : ($host['host'] ?? 'Desconocido');
                                            $duration = time() - (int)$problem['lastchange'];
                                            $zabbix_url = defined('ZABBIX_URL') ? ZABBIX_URL : 'http://172.32.1.51/zabbix';
                                        ?>
                                            <tr>
                                                <td class="text-nowrap">
                                                    <span class="badge badge-light border shadow-sm">
                                                        <i class="far fa-clock mr-1"></i> <?php echo format_zabbix_duration($duration); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-server text-muted mr-2"></i>
                                                        <span class="text-bold"><?php echo htmlspecialchars($hostName); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="text-dark"><?php echo htmlspecialchars($problem['description']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="<?php echo htmlspecialchars($zabbix_url); ?>/zabbix.php?action=problem.view&filter_hostids%5B%5D=<?php echo urlencode($host['hostid']); ?>&filter_set=1" 
                                                       target="_blank" rel="noopener noreferrer" class="btn btn-default btn-xs shadow-sm">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>