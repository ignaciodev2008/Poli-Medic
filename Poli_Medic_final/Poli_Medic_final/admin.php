<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/includes/config.php';
$error = '';
if (isset($_POST['salir']) || isset($_GET['salir'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']); }
    session_destroy();
    header('Location: admin.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';
    $query = $pdo->prepare('SELECT id, clave_hash FROM administradores WHERE usuario=?');
    $query->execute([$usuario]);
    $admin = $query->fetch();
    if ($admin && password_verify($clave, $admin['clave_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_usuario'] = $usuario;
        header('Location: admin.php');
        exit;
    }
    $error = 'Usuario o contraseña incorrectos.';
}
if (empty($_SESSION['admin_id'])):
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ingreso administrativo | Poli Medic</title>
<style>:root{--paper:#fbfaf6;--surface:#fffefa;--ink:#203b35;--muted:#667a72;--green:#1d6555;--line:#dce5dc}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:var(--paper);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink)}.login{width:min(100%,430px);background:var(--surface);border:1px solid var(--line);border-radius:22px;padding:34px;box-shadow:0 18px 55px #173d4610}.eyebrow{color:var(--green);font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase}h1{font-size:29px;line-height:1.15;margin:10px 0}p{color:var(--muted)}label{display:block;margin:16px 0 6px;font-weight:650}input{width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:white;color:#203b35}button{margin-top:22px;width:100%;border:0;border-radius:10px;padding:13px;background:var(--green);color:white;font:inherit;font-weight:700;cursor:pointer}.error{color:#a3332e;background:#f8e9e7;padding:10px;border-radius:8px}.back{display:block;text-align:center;margin-top:18px;color:var(--green)}</style></head><body><main class="login"><div class="eyebrow">Poli Medic</div><h1>Ingreso administrativo</h1><p>Ingresá con las credenciales del panel de administración.</p><?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><form method="post"><label for="usuario">Usuario</label><input id="usuario" name="usuario" autocomplete="username" required><label for="clave">Contraseña</label><input id="clave" name="clave" type="password" autocomplete="current-password" required><button type="submit">Ingresar al panel</button></form><a class="back" href="index.php">← Volver al portal del cliente</a></main></body></html>
<?php exit; endif;
$html = file_get_contents(__DIR__ . '/includes/admin-view.html');
$html = str_replace('<body>', '<body data-admin-user="' . htmlspecialchars((string)($_SESSION['admin_usuario'] ?? 'Administración'), ENT_QUOTES, 'UTF-8') . '">', $html);
echo $html;
