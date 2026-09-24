<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$pdo = obtenerConexion();
$tituloPagina = 'Menú';

// ── Changelog: último PDF dejado por el programador en /changelog ──
$changelogDir = __DIR__ . '/../changelog';
$changelogArchivo = null;
if (is_dir($changelogDir)) {
    foreach (scandir($changelogDir) as $file) {
        if (!str_ends_with(strtolower($file), '.pdf')) continue;
        if (str_contains($file, '/') || str_contains($file, '\\')) continue;
        $ruta = $changelogDir . '/' . $file;
        if (!is_file($ruta)) continue;
        if ($changelogArchivo === null || filemtime($ruta) > filemtime($changelogDir . '/' . $changelogArchivo)) {
            $changelogArchivo = $file;
        }
    }
}
$changelogFecha = $changelogArchivo !== null
    ? date('d/m/Y', filemtime($changelogDir . '/' . $changelogArchivo))
    : null;

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Menú</h1>
    <p>Opciones y accesos de <?= e($usuario['nombre']) ?>.</p>
</div>

<div class="tarjeta">
    <div class="tarjeta-titulo">Mi cuenta</div>
    <p class="texto-2" style="margin:0 0 0.5rem;">
        <?= e($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
        <?php if (!empty($usuario['email'])): ?>· <?= e($usuario['email']) ?><?php endif; ?>
    </p>

    <hr class="separador">

    <h3>Novedades del sistema</h3>
    <?php if ($changelogArchivo !== null): ?>
        <p class="texto-2" style="margin:0 0 0.75rem;">
            Última actualización: <strong><?= e($changelogFecha) ?></strong>.
            Descargá el detalle de cambios en PDF.
        </p>
        <a href="changelog_descargar.php" class="boton boton-secundario boton-sm mt-0">⬇ Descargar changelog (PDF)</a>
    <?php else: ?>
        <p class="texto-2" style="margin:0;">
            Todavía no hay un changelog publicado. Va a aparecer acá luego de la próxima actualización.
        </p>
    <?php endif; ?>

    <hr class="separador">

    <h3>¿Tenés una pregunta?</h3>
    <p class="texto-2" style="margin:0 0 0.75rem;">
        Escribila acá y se abrirá tu correo con el mensaje listo para enviar a soporte.
    </p>
    <form id="formPregunta" novalidate>
        <label for="pregunta_asunto">Asunto</label>
        <input type="text" id="pregunta_asunto" name="asunto" required maxlength="100"
               placeholder="Ej: Duda sobre un ticket" autocomplete="off">
        <label for="pregunta_mensaje">Tu pregunta</label>
        <textarea id="pregunta_mensaje" name="mensaje" required maxlength="1500" rows="4"
                  placeholder="Contanos tu duda con el mayor detalle posible"></textarea>
        <div class="acciones-fila">
            <button type="submit">✉ Enviar pregunta</button>
        </div>
    </form>
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

    // ── Pregunta a soporte: abre el correo del usuario vía mailto ──
    var formPregunta = document.getElementById('formPregunta');
    if (formPregunta) {
        var inputAsunto = document.getElementById('pregunta_asunto');
        var inputMensaje = document.getElementById('pregunta_mensaje');
        var EMAIL_SOPORTE = 'cesdemh2026@gmail.com';
        var FIRMA = <?= json_encode(
            '— ' . ($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')
            . ' (DNI: ' . ($usuario['dni'] ?? '—') . ')'
            . (!empty($usuario['email']) ? ' <' . $usuario['email'] . '>' : ''),
            JSON_UNESCAPED_UNICODE
        ) ?>;
        formPregunta.addEventListener('submit', function(e){
            e.preventDefault();
            var asunto = inputAsunto.value.trim();
            var mensaje = inputMensaje.value.trim();
            if (asunto === '' || mensaje === '') {
                alert('Completá el asunto y tu pregunta antes de enviar.');
                return;
            }
            var cuerpo = mensaje + '\n\n' + FIRMA;
            window.location.href = 'mailto:' + EMAIL_SOPORTE
                + '?subject=' + encodeURIComponent('[CESDE] ' + asunto)
                + '&body=' + encodeURIComponent(cuerpo);
        });
    }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
