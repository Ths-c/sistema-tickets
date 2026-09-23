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
            <span class="icono-tema" id="solTema" role="button" tabindex="0" title="☀️" aria-label="Sol (easter egg oculto)">☀️</span>
            <input type="checkbox" id="switchTema" aria-label="Cambiar entre modo claro y oscuro">
            <span class="riel" aria-hidden="true"></span>
            <span class="icono-tema" aria-hidden="true">🌙</span>
        </label>
    </div>
</div>

<div id="dodoPeek" class="dodo-peek" aria-hidden="true">
    <img src="dodo-cabeza.png" alt="" class="dodo-peek-img" draggable="false">
</div>

<script>
(function(){
    var sw = document.getElementById('switchTema');
    if (sw) {
        sw.checked = document.documentElement.getAttribute('data-theme') === 'dark';
        sw.addEventListener('change', function(){
            if (sw.checked) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
            try { localStorage.setItem('cesde-tema', sw.checked ? 'oscuro' : 'claro'); } catch (e) {}
        });
    }

    // ── Easter egg: 3 toques rápidos al sol ☀️ → el Dodo se asoma ──
    var sol = document.getElementById('solTema');
    var peek = document.getElementById('dodoPeek');
    var toques = 0;
    var temporizador = null;
    var fallbackTimer = null;
    var VENTANA_MS = 2500;

    function asomarDodo() {
        if (!peek) return;
        // Reiniciar la animación aunque ya esté visible
        peek.classList.remove('visible');
        void peek.offsetWidth;
        peek.classList.add('visible');
        peek.setAttribute('aria-hidden', 'false');
        if (fallbackTimer) clearTimeout(fallbackTimer);
        fallbackTimer = setTimeout(function(){
            peek.classList.remove('visible');
            peek.setAttribute('aria-hidden', 'true');
        }, 5200);
    }

    if (peek) {
        peek.addEventListener('animationend', function(){
            peek.classList.remove('visible');
            peek.setAttribute('aria-hidden', 'true');
            if (fallbackTimer) clearTimeout(fallbackTimer);
        });
    }

    function contarToque() {
        toques++;
        if (temporizador) clearTimeout(temporizador);
        if (toques >= 3) {
            toques = 0;
            asomarDodo();
            return;
        }
        temporizador = setTimeout(function(){ toques = 0; }, VENTANA_MS);
    }

    if (sol) {
        sol.style.cursor = 'pointer';
        sol.addEventListener('click', function(e){
            e.stopPropagation(); // no alternar el switch al cazar el easter egg
            contarToque();
        });
        sol.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                e.stopPropagation();
                contarToque();
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
