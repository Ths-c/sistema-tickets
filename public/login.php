<?php
require_once __DIR__ . '/../config/sesion.php';

if (usuarioActual()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dni      = trim($_POST['dni'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($dni === '' || $password === '') {
        $error = 'Completá tu DNI y la contraseña.';
    } else {
        $usuario = intentarLogin($dni, $password);
        if ($usuario) {
            $_SESSION['usuario'] = $usuario;
            header('Location: dashboard.php');
            exit;
        }
        $error = 'DNI o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar · Soporte técnico distrital</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700;750&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/estilo.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-titulo">CESDE - Centro de Soporte Digital Educativo</div>
            <div class="login-logo-sub">CESDE - Centro de Soporte Digital Educativo</div>
        </div>

        <?php if ($error): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <label for="dni">DNI</label>
            <input type="text" id="dni" name="dni" required autofocus inputmode="numeric"
                   placeholder="Sin puntos, ej: 30111222"
                   value="<?= e($_POST['dni'] ?? '') ?>">

            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required placeholder="••••••••">

            <button type="submit">Ingresar</button>
        </form>
    </div>
</div>
</body>
</html>
