<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$pdo = obtenerConexion();
$tituloPagina = 'Menú';

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Menú</h1>
    <p>Opciones y accesos de <?= e($usuario['nombre']) ?>.</p>
</div>

<div class="tarjeta">
    <div class="tarjeta-titulo">Mi cuenta</div>
    <p class="texto-2" style="margin:0;">Contenido pendiente de definir.</p>
</div>

<div class="tarjeta">
    <div class="tarjeta-titulo">Apariencia</div>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <div>
            <div style="font-weight:650;">Modo oscuro</div>
            <p class="texto-2" style="margin:0;">Cambiá entre el tema claro y el oscuro. La elección se guarda en este navegador.</p>
        </div>
        <label class="switch-tema">
            <span class="icono-tema" aria-hidden="true">☀️</span>
            <input type="checkbox" id="switchTema" aria-label="Cambiar entre modo claro y oscuro">
            <span class="riel" aria-hidden="true"></span>
            <span class="icono-tema" aria-hidden="true">🌙</span>
        </label>
    </div>
</div>

<script>
(function(){
    var sw = document.getElementById('switchTema');
    if (!sw) return;
    sw.checked = document.documentElement.getAttribute('data-theme') === 'dark';
    sw.addEventListener('change', function(){
        if (sw.checked) {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try { localStorage.setItem('cesde-tema', sw.checked ? 'oscuro' : 'claro'); } catch (e) {}
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
