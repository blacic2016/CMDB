<?php
/**
 * CMDB VILASECA - Visualizador de Activos
 * Ubicación: /var/www/html/Sonda/public/cmdb.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

// Protección de sesión
require_login(); 

// 1. Procesar nombre de la tabla para el título
$sheet_name_clean = 'CMDB';
$name = $_GET['name'] ?? '';

if ($name) {
    // Corregimos la regex: 'sheet_' por el nombre real de la tabla
    $sheet_name_clean = ucfirst(str_replace('sheet_', '', $name));
}

$page_title = $sheet_name_clean;

// 2. Cargar Header (Ruta corregida)
require_once __DIR__ . '/partials/header.php'; 

// 3. Lógica de Paginación y Filtrado
$rows = [];
$cols = [];
$total = 0;
$page = 1;
$perPage = 25;
$lastPage = 1;
$q = '';

if ($name && isValidTableName($name)) {
    $cols = getTableColumns($name);
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
    
    // Funciones en helpers.php que interactúan con la DB en 172.32.1.51
    $total = countTableRows($name, $filters, $params);
    $rows = fetchTableRows($name, $filters, $params, '', $perPage, $offset);
    $lastPage = (int)ceil($total / $perPage);
}
?>

<div class="content-wrapper">
    <section class="content">
        <div class="container-fluid pt-3">
            <?php if ($name && isValidTableName($name)): ?>
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="card-title">Inventario: <strong><?php echo htmlspecialchars($sheet_name_clean); ?></strong></h3>
                            <a href="item_create.php?table=<?php echo urlencode($name); ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-plus"></i> Nuevo Activo
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" class="row mb-3">
                            <input type="hidden" name="name" value="<?php echo htmlspecialchars($name); ?>">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input class="form-control" name="q" placeholder="Buscar..." value="<?php echo htmlspecialchars($q); ?>">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <?php foreach ($cols as $c): if ($c === '_row_hash') continue; ?>
                                            <th><?php echo htmlspecialchars(str_replace('_', ' ', $c)); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr><td colspan="100%" class="text-center text-muted">No se encontraron registros.</td></tr>
                                    <?php else: foreach ($rows as $r): ?>
                                        <tr class='data-row' data-id="<?php echo $r['id']; ?>" data-table="<?php echo htmlspecialchars($name); ?>" style="cursor: pointer;">
                                            <?php foreach ($cols as $c): if ($c === '_row_hash') continue; ?>
                                                <td><?php echo htmlspecialchars($r[$c] ?? ''); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($lastPage > 1): ?>
                            <nav class="mt-3">
                                <ul class="pagination pagination-sm justify-content-center">
                                    <?php for ($p = 1; $p <= $lastPage; $p++): 
                                        $query_params = http_build_query(array_merge($_GET, ['page' => $p]));
                                    ?>
                                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo $query_params; ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info shadow-sm">
                    <h5><i class="icon fas fa-info"></i> Bienvenido al CMDB</h5>
                    Selecciona una categoría del menú lateral para visualizar los activos de Sonda.
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Redirección al detalle al hacer clic en una fila
    document.querySelectorAll('.data-row').forEach(function(row) {
        row.addEventListener('click', function() {
            const id = this.dataset.id;
            const table = this.dataset.table;
            window.location.href = `item_detail.php?table=${encodeURIComponent(table)}&id=${encodeURIComponent(id)}`;
        });
    });
});
</script>