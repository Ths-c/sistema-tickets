<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo     = obtenerConexion();
$usuario = usuarioActual();
$tituloPagina = 'Control de acceso al sistema';
$ok    = null;
$error = null;

$bloqueado = ticketsBloqueados($pdo);

// ── Acciones POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'bloquear') {
        setConfig($pdo, 'tickets_bloqueados',  '1');
        setConfig($pdo, 'bloqueo_fecha',        date('Y-m-d H:i:s'));
        setConfig($pdo, 'bloqueo_responsable',  $usuario['nombre'] . ' ' . $usuario['apellido']);
        $bloqueado = true;
        $ok = 'Sistema bloqueado. Los usuarios ya no pueden crear nuevos tickets.';
    }

    if ($accion === 'habilitar') {
        setConfig($pdo, 'tickets_bloqueados',  '0');
        setConfig($pdo, 'bloqueo_fecha',        null);
        setConfig($pdo, 'bloqueo_responsable',  null);
        $bloqueado = false;
        $ok = 'Sistema habilitado. Los usuarios pueden crear tickets nuevamente.';
    }

    if ($accion === 'guardar_mensajes') {
        $msgBloqueo    = trim($_POST['mensaje_bloqueo']    ?? '');
        $msgHabilitado = trim($_POST['mensaje_habilitado'] ?? '');

        if ($msgBloqueo === '' || $msgHabilitado === '') {
            $error = 'Ambos mensajes son obligatorios.';
        } else {
            setConfig($pdo, 'mensaje_bloqueo',    $msgBloqueo);
            setConfig($pdo, 'mensaje_habilitado', $msgHabilitado);
            $ok = 'Mensajes actualizados. Se verán de inmediato en todas las pantallas.';
        }
    }

    if ($accion === 'guardar_limite') {
        $limite = (int) ($_POST['limite_tickets'] ?? 0);
        if ($limite < 1) {
            $error = 'El límite tiene que ser un número mayor a 0.';
        } else {
            setConfig($pdo, 'limite_tickets_abiertos_escuela', (string) $limite);
            $ok = "Límite actualizado: cada escuela puede tener hasta {$limite} tickets abiertos al mismo tiempo.";
        }
    }

    if ($accion === 'guardar_limite_dispositivos') {
        $limiteDisp = (int) ($_POST['limite_dispositivos'] ?? 0);
        if ($limiteDisp < 1 || $limiteDisp > 10) {
            $error = 'El límite de dispositivos por ticket debe estar entre 1 y 10.';
        } else {
            setConfig($pdo, 'limite_dispositivos_por_ticket', (string) $limiteDisp);
            $ok = "Límite actualizado: cada ticket puede incluir hasta {$limiteDisp} dispositivo" . ($limiteDisp === 1 ? '' : 's') . ".";
        }
    }
}

// ── Leer configuración actual ─────────────────────────────────
$msgBloqueo     = config($pdo, 'mensaje_bloqueo');
$msgHabilitado  = config($pdo, 'mensaje_habilitado');
$bloqueoFecha   = config($pdo, 'bloqueo_fecha');
$bloqueoResp    = config($pdo, 'bloqueo_responsable');
$limiteActual   = limiteTicketsAbiertosEscuela($pdo);
$limiteDispositivosActual = limiteDispositivosPorTicket($pdo);

// Escuelas con bloqueo puntual activo (independiente del bloqueo global)
$escuelasBloqueadas = $pdo->query(
    "SELECT id, nombre, bloqueo_fecha, bloqueo_responsable
     FROM escuelas WHERE tickets_bloqueados = 1 ORDER BY nombre"
)->fetchAll();

// ── Métricas de contexto ──────────────────────────────────────
$totalAbiertos  = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado NOT IN ('cerrado','cancelado')")->fetchColumn();
$totalNuevos    = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado = 'nuevo'")->fetchColumn();
$totalEnProceso = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado = 'en_proceso'")->fetchColumn();
$tecnicos       = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='tecnico' AND activo=1")->fetchColumn();
$cargaPromedio  = $tecnicos > 0 ? round($totalAbiertos / $tecnicos, 1) : 0;

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Control de acceso al sistema</h1>
    <p>Habilitá o bloqueá la creación de nuevos tickets y personalizá los mensajes que ven todos los usuarios.</p>
</div>

<?php if ($ok):    ?><div class="alerta alerta-ok"><?= e($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<!-- Estado actual + acción principal -->
<div class="tarjeta" style="border-left: 5px solid <?= $bloqueado ? 'var(--rojo)' : 'var(--verde)' ?>; margin-bottom:1.25rem;">
    <div style="display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;">
        <div style="flex:1;">
            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
                <span style="
                    display:inline-flex; align-items:center; gap:0.4rem;
                    padding:0.35rem 0.9rem; border-radius:999px; font-size:0.85rem; font-weight:700;
                    background:<?= $bloqueado ? 'var(--rojo-claro)' : 'var(--verde-claro)' ?>;
                    color:<?= $bloqueado ? 'var(--rojo)' : 'var(--verde)' ?>;">
                    <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block;
                                 <?= !$bloqueado ? 'animation:pulse 2s infinite;' : '' ?>"></span>
                    <?= $bloqueado ? 'SISTEMA BLOQUEADO' : 'SISTEMA HABILITADO' ?>
                </span>
            </div>
            <?php if ($bloqueado && $bloqueoFecha): ?>
                <p class="texto-2" style="margin:0;">
                    Bloqueado el <?= date('d/m/Y \a \l\a\s H:i', strtotime($bloqueoFecha)) ?>
                    <?= $bloqueoResp ? " por <strong>" . e($bloqueoResp) . "</strong>" : '' ?>.
                </p>
            <?php elseif (!$bloqueado): ?>
                <p class="texto-2" style="margin:0;">Los usuarios pueden crear nuevos tickets con normalidad.</p>
            <?php endif; ?>
        </div>

        <div style="display:flex; gap:0.75rem; flex-shrink:0;">
            <?php if (!$bloqueado): ?>
                <form method="post" onsubmit="return confirm('¿Confirmar bloqueo? Los usuarios no podrán crear tickets hasta que vuelvas a habilitarlo.')">
                    <input type="hidden" name="accion" value="bloquear">
                    <button type="submit" class="boton-peligro boton" style="margin:0;">
                        🔒 Bloquear sistema
                    </button>
                </form>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('¿Confirmar habilitación? Los usuarios podrán crear tickets nuevamente.')">
                    <input type="hidden" name="accion" value="habilitar">
                    <button type="submit" class="boton" style="margin:0; background:var(--verde);">
                        ✓ Habilitar sistema
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Métricas de contexto para la decisión -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:1rem; margin-bottom:1.25rem;">
    <?php
    $contexto = [
        ['Tickets abiertos',  $totalAbiertos,  $totalAbiertos > 20 ? 'var(--rojo)' : ($totalAbiertos > 10 ? 'var(--amarillo)' : 'var(--verde)')],
        ['Sin asignar',       $totalNuevos,    $totalNuevos > 10 ? 'var(--rojo)' : ($totalNuevos > 5 ? 'var(--amarillo)' : 'var(--verde)')],
        ['En proceso',        $totalEnProceso, 'var(--acento)'],
        ['Técnicos activos',  $tecnicos,       'var(--verde)'],
        ['Tickets por técnico', $cargaPromedio, $cargaPromedio > 8 ? 'var(--rojo)' : ($cargaPromedio > 4 ? 'var(--amarillo)' : 'var(--verde)')],
    ];
    foreach ($contexto as [$label, $valor, $color]): ?>
    <div class="tarjeta" style="margin-bottom:0; padding:1rem 1.1rem; text-align:center;">
        <div style="font-size:1.8rem; font-weight:750; color:<?= $color ?>; letter-spacing:-0.02em;"><?= $valor ?></div>
        <div class="texto-3" style="font-size:0.75rem; margin-top:3px;"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($totalAbiertos > 15 || $cargaPromedio > 6): ?>
<div class="alerta alerta-error" style="margin-bottom:1.25rem;">
    <strong>Alta carga detectada:</strong>
    <?= $totalAbiertos ?> tickets abiertos con <?= $tecnicos ?> técnicos disponibles
    (<?= $cargaPromedio ?> tickets/técnico en promedio).
    Considerá bloquear temporalmente el ingreso de nuevos pedidos.
</div>
<?php endif; ?>


<!-- Límite de tickets abiertos por escuela -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Límite de tickets abiertos por escuela</div>
    <p class="texto-2" style="margin-bottom:1rem;">
        Cuando una escuela llega a este número de tickets sin cerrar (no cerrados ni cancelados),
        sus solicitantes no van a poder crear tickets nuevos hasta que se resuelvan o cancelen algunos.
    </p>
    <form method="post" style="display:flex; align-items:flex-end; gap:0.75rem; flex-wrap:wrap;">
        <input type="hidden" name="accion" value="guardar_limite">
        <div style="min-width:160px;">
            <label for="limite_tickets">Tickets abiertos máximos</label>
            <input type="number" id="limite_tickets" name="limite_tickets" min="1" step="1"
                   value="<?= (int) $limiteActual ?>">
        </div>
        <div class="acciones-fila" style="margin:0;">
            <button type="submit">Guardar límite</button>
        </div>
    </form>
</div>

<!-- Límite de dispositivos por ticket -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Límite de dispositivos por ticket</div>
    <p class="texto-2" style="margin-bottom:1rem;">
        Cada ticket puede incluir como máximo esta cantidad de dispositivos/equipos. El valor por defecto es <strong>2</strong>.
        Si un ticket ya tiene este número de dispositivos, no se podrán agregar más hasta quitar alguno.
        El límite se controla tanto al crear el ticket como al agregar dispositivos después, desde el detalle del ticket.
    </p>
    <form method="post" style="display:flex; align-items:flex-end; gap:0.75rem; flex-wrap:wrap;">
        <input type="hidden" name="accion" value="guardar_limite_dispositivos">
        <div style="min-width:160px;">
            <label for="limite_dispositivos">Dispositivos máximos por ticket</label>
            <input type="number" id="limite_dispositivos" name="limite_dispositivos" min="1" max="10" step="1"
                   value="<?= (int) $limiteDispositivosActual ?>">
        </div>
        <div class="acciones-fila" style="margin:0;">
            <button type="submit">Guardar límite</button>
        </div>
        <span class="texto-3" style="align-self:center; margin-left:0.5rem;">Actual: <?= (int)$limiteDispositivosActual ?> por ticket</span>
    </form>
    <p class="texto-3" style="margin-top:0.6rem;">
        Tip: dejalo en 2 para cumplir la política solicitada de “dos dispositivos dentro de los tickets de las escuelas”.
        Podés subirlo hasta 10 si alguna escuela necesita reportar más equipos a la vez.
    </p>
</div>

<!-- Escuelas bloqueadas puntualmente -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Escuelas con creación de tickets bloqueada</div>
    <p class="texto-2" style="margin-bottom:1rem;">
        Además del bloqueo global de arriba, podés cerrarle la creación de tickets a una escuela puntual
        desde <a href="admin_escuelas.php" style="font-weight:650;">Escuelas</a>, sin afectar al resto del distrito.
    </p>
    <?php if ($escuelasBloqueadas): ?>
        <div class="tabla-wrap">
        <table>
            <thead><tr><th>Escuela</th><th>Bloqueada desde</th><th>Responsable</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($escuelasBloqueadas as $esc): ?>
                <tr>
                    <td class="negrita"><?= e($esc['nombre']) ?></td>
                    <td class="texto-2"><?= $esc['bloqueo_fecha'] ? date('d/m/Y H:i', strtotime($esc['bloqueo_fecha'])) : '—' ?></td>
                    <td class="texto-2"><?= e($esc['bloqueo_responsable'] ?? '—') ?></td>
                    <td><a href="admin_escuelas.php" class="boton boton-secundario boton-sm">Gestionar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="texto-2">Ninguna escuela tiene la creación de tickets bloqueada puntualmente en este momento.</p>
    <?php endif; ?>
</div>

<!-- Editor de mensajes -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Mensajes personalizables</div>
    <p class="texto-2" style="margin-bottom:1rem;">
        Estos textos aparecen en un banner visible para <strong>todos los usuarios</strong> en cada página del sistema.
        Podés actualizarlos en cualquier momento sin necesidad de bloquear o habilitar el sistema.
    </p>

    <form method="post">
        <input type="hidden" name="accion" value="guardar_mensajes">

        <label for="msg_bloqueo">
            🔴 Mensaje cuando el sistema está <strong>bloqueado</strong>
        </label>
        <textarea id="msg_bloqueo" name="mensaje_bloqueo" rows="4"
                  placeholder="Explicá a los usuarios por qué no pueden crear tickets y qué deben hacer..."><?= e($msgBloqueo) ?></textarea>
        <p class="texto-3" style="margin-top:0.25rem;">
            Este mensaje aparece en un banner rojo en todas las páginas cuando el sistema está bloqueado.
        </p>

        <label for="msg_habilitado">
            🟢 Mensaje cuando el sistema está <strong>habilitado</strong>
        </label>
        <textarea id="msg_habilitado" name="mensaje_habilitado" rows="3"
                  placeholder="Informá el horario de atención, tiempos de respuesta esperados..."><?= e($msgHabilitado) ?></textarea>
        <p class="texto-3" style="margin-top:0.25rem;">
            Este mensaje aparece en un banner verde discreto en la pantalla de creación de tickets.
        </p>

        <div class="acciones-fila">
            <button type="submit">Guardar mensajes</button>
        </div>
    </form>
</div>

<!-- Vista previa -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Vista previa de los banners</div>
    <p class="texto-2" style="margin-bottom:1rem;">Así verán los mensajes todos los usuarios en sus pantallas:</p>

    <p style="font-size:0.8rem; font-weight:600; color:var(--texto-2); margin-bottom:0.5rem;">Cuando el sistema está BLOQUEADO:</p>
    <div class="banner-sistema banner-bloqueado" style="margin-bottom:1rem;">
        <span class="banner-icono">🔒</span>
        <div class="banner-cuerpo">
            <strong>Sistema temporalmente suspendido</strong>
            <span id="preview_bloqueo"><?= e($msgBloqueo) ?></span>
        </div>
    </div>

    <p style="font-size:0.8rem; font-weight:600; color:var(--texto-2); margin-bottom:0.5rem;">Cuando el sistema está HABILITADO:</p>
    <div class="banner-sistema banner-habilitado">
        <span class="banner-icono">🟢</span>
        <div class="banner-cuerpo">
            <strong>Sistema habilitado</strong>
            <span id="preview_habilitado"><?= e($msgHabilitado) ?></span>
        </div>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.5; transform: scale(1.3); }
}
</style>

<script>
// Actualiza la vista previa en tiempo real mientras se escribe
document.getElementById('msg_bloqueo').addEventListener('input', function() {
    document.getElementById('preview_bloqueo').textContent = this.value;
});
document.getElementById('msg_habilitado').addEventListener('input', function() {
    document.getElementById('preview_habilitado').textContent = this.value;
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
