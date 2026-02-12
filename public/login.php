<?php
require_once __DIR__ . '/../config.php'; // Agregado para cargar constantes
require_once __DIR__ . '/../src/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = isset($_POST['username']) ? trim($_POST['username']) : '';
    $p = isset($_POST['password']) ? $_POST['password'] : '';

    if (login_user($u, $p)) {
        // Redirigir al dashboard.php que está en la misma carpeta
        header('Location: dashboard.php');
        exit();
    } else {
        $err = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Login - CMDB Vilaseca</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
  <div class="container">
    <h1>Login</h1>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Usuario</label>
        <input class="form-control" name="username" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Contraseña</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <button class="btn btn-primary">Ingresar</button>
    </form>
    <hr>
    <p>Usuarios demo: <strong>superadmin(ChangeMe123!), admin(AdminPass123!), user(UserView123!)</strong></p>
    <p><a href="<?php echo PUBLIC_URL_PREFIX; ?>/">Volver</a></p>
  </div>
</body>
</html>