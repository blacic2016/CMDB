<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/zabbix_api.php';
require_login();

function format_zabbix_duration($seconds) {
    if ($seconds < 60) return "{$seconds}s";
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    if ($m < 60) return "{$m}m {$s}s";
    $h = floor($m / 60);
    $m = $m % 60;
    if ($h < 24) return "{$h}h {$m}m";
    $d = floor($h / 24);
    $h = $h % 24;
    return "{$d}d {$h}h";
}

$severity_id = isset($_GET['severity']) ? (int)$_GET['severity'] : -1;
$severities = [
    0 => ['name' => 'Not classified', 'bg' => 'secondary'], 1 => ['name' => 'Information', 'bg' => 'info'],
    2 => ['name' => 'Warning', 'bg' => 'warning'], 3 => ['name' => 'Average', 'bg' => 'orange'],
    4 => ['name' => 'High', 'bg' => 'danger'], 5 => ['name' => 'Disaster', 'bg' => 'maroon']
];

if (!isset($severities[$severity_id])) {
    die("Invalid severity level.");
}

$severity_name = $severities[$severity_id]['name'];
$problems = [];
$api_error = null;

// Using trigger.get with only_true=1 is the modern way to get problems and allows host selection.
$response = call_zabbix_api('trigger.get', [
    'output' => 'extend',
    'selectHosts' => ['host'],
    'only_true' => 1, // Only triggers in PROBLEM state
    'expandDescription' => 1,
    'filter' => ['priority' => $severity_id],
    'sortfield' => 'lastchange',
    'sortorder' => 'DESC'
]);

if (isset($response['error'])) {
    $api_error = $response['error'];
} else {
    // We rename the result to 'problems' to keep the template logic the same
    $problems = $response['result'];
}

$page_title = 'Problemas: ' . htmlspecialchars($severity_name);
require_once __DIR__ . '/partials/header.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Problemas de Severidad: <span class="badge bg-<?php echo $severities[$severity_id]['bg']; ?>"><?php echo htmlspecialchars($severity_name); ?></span></h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?php echo PUBLIC_URL_PREFIX; ?>/monitoreo.php">Monitoreo</a></li>
                        <li class="breadcrumb-item active">Detalle de Problemas</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <?php if ($api_error): ?>
                <div class="alert alert-danger">
                    <h5><i class="icon fas fa-ban"></i> Error de API</h5>
                    <?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body p-0">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Duración</th>
                                    <th>Host</th>
                                    <th>Problema</th>
                                    <th>Severidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($problems)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No hay problemas para este nivel de severidad.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($problems as $problem): ?>
                                        <tr>
                                            <td><?php echo format_zabbix_duration(time() - $problem['lastchange']); ?></td>
                                            <td><?php echo htmlspecialchars($problem['hosts'][0]['host'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($problem['description']); ?></td>
                                            <td><span class="badge bg-<?php echo $severities[$problem['priority']]['bg']; ?>"><?php echo htmlspecialchars($severities[$problem['priority']]['name']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
