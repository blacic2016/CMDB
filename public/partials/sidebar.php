<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../src/auth.php';
$user = current_user();
$cur = basename($_SERVER['SCRIPT_NAME']);
$current_sheet = $_GET['name'] ?? '';
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-light-primary elevation-4">
  <!-- Brand Logo -->
  <a href="<?php echo PUBLIC_URL_PREFIX; ?>/dashboard.php" class="brand-link">
    <span class="brand-text font-weight-light">CMDB Vilaseca</span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="info">
        <a href="#" class="d-block"><?php echo htmlspecialchars($user['username'] ?? 'Usuario'); ?></a>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="<?php echo PUBLIC_URL_PREFIX; ?>/dashboard.php" class="nav-link <?php echo $cur === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>
        
        <?php
          require_once __DIR__ . '/../../src/helpers.php';
          $sheet_tables = listSheetTables();
          $is_cmdb_page = ($cur === 'cmdb.php' || $cur === 'item_detail.php' || $cur === 'history.php');
        ?>
        <li class="nav-item <?php echo $is_cmdb_page ? 'menu-is-opening menu-open' : ''; ?>">
          <a href="#" class="nav-link <?php echo $is_cmdb_page ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-database"></i>
            <p>
              CMDB
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <?php foreach ($sheet_tables as $table): ?>
              <?php $sheet_name_clean = preg_replace('/^sheet_/', '', $table); ?>
              <li class="nav-item">
                <a href="<?php echo PUBLIC_URL_PREFIX; ?>/cmdb.php?name=<?php echo urlencode($table); ?>" class="nav-link <?php echo $current_sheet === $table ? 'active' : ''; ?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p><?php echo htmlspecialchars(ucfirst($sheet_name_clean)); ?></p>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>

        <li class="nav-item">
            <a href="<?php echo PUBLIC_URL_PREFIX; ?>/import.php" class="nav-link <?php echo $cur === 'import.php' ? 'active' : ''; ?>">
                <i class="nav-icon fas fa-file-excel"></i>
                <p>Importar Excel</p>
            </a>
        </li>
        <li class="nav-item">
          <a href="<?php echo PUBLIC_URL_PREFIX; ?>/distribrack.php" class="nav-link <?php echo $cur === 'distribrack.php' ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-th"></i>
            <p>Distribrack</p>
          </a>
        </li>
        <?php
          $is_monitoreo_page = in_array($cur, ['monitoreo.php', 'crear_monitoreo.php', 'problems.php']);
        ?>
        <li class="nav-item <?php echo $is_monitoreo_page ? 'menu-is-opening menu-open' : ''; ?>">
          <a href="#" class="nav-link <?php echo $is_monitoreo_page ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-chart-line"></i>
            <p>
              Monitoreo
              <i class="right fas fa-angle-left"></i>
            </p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?php echo PUBLIC_URL_PREFIX; ?>/monitoreo.php" class="nav-link <?php echo in_array($cur, ['monitoreo.php', 'problems.php']) ? 'active' : ''; ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Dashboard</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo PUBLIC_URL_PREFIX; ?>/crear_monitoreo.php" class="nav-link <?php echo $cur === 'crear_monitoreo.php' ? 'active' : ''; ?>">
                <i class="far fa-circle nav-icon"></i>
                <p>Crear Monitoreo</p>
              </a>
            </li>
          </ul>
        </li>
        <?php if (has_role(['SUPER_ADMIN'])): ?>
        <li class="nav-header">ADMINISTRACIÓN</li>
        <li class="nav-item">
          <a href="<?php echo PUBLIC_URL_PREFIX; ?>/sheet_configs.php" class="nav-link <?php echo $cur === 'sheet_configs.php' ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-cogs"></i>
            <p>Config. Claves</p>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </nav>
    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->
</aside>
