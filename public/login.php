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
            <div class="campo-password">
                <input type="password" id="password" name="password" required placeholder="Tu contraseña" autocomplete="current-password">
                <button type="button" class="btn-mostrar-password" id="togglePassword" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña" tabindex="0">
                    <svg class="icono-ojo" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icono-ojo-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.53 9.53a3 3 0 1 0 4.95 3.95"/><path d="M1 1l22 22"/><path d="M10.58 8.59A10.07 10.07 0 0 1 12 4c7 0 11 8 11 8a18.45 18.45 0 0 1-2.16 3.19"/></svg>
                </button>
            </div>

            <button type="submit">Ingresar</button>
        </form>
    </div>
</div>
<script>
(function() {
    var btn = document.getElementById('togglePassword');
    var input = document.getElementById('password');
    if (!btn || !input) return;
    var ojo = btn.querySelector('.icono-ojo');
    var ojoOff = btn.querySelector('.icono-ojo-off');
    btn.addEventListener('click', function() {
        var mostrar = input.type === 'password';
        input.type = mostrar ? 'text' : 'password';
        btn.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        btn.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
        btn.setAttribute('title', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        if (ojo && ojoOff) {
            ojo.style.display = mostrar ? 'none' : 'block';
            ojoOff.style.display = mostrar ? 'block' : 'none';
        }
        input.focus();
    });
})();
</script>
</body>
</html>
