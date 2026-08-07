<?php
/** Se incluye al principio de cada página protegida. */
$usuario = usuarioActual();
$paginaActual = basename($_SERVER['PHP_SELF']);

function navLink(string $href, string $icono, string $etiqueta, string $paginaActual, int $badge = 0, string $badgeId = ''): string {
    $activo = ($paginaActual === $href) ? ' activo' : '';
    $idAttr = $badgeId !== '' ? " id=\"{$badgeId}\"" : '';
    $badgeHtml = $badge > 0
        ? "<span class=\"sidebar-badge\"{$idAttr}>" . ($badge > 99 ? '99+' : $badge) . '</span>'
        : ($badgeId !== '' ? "<span class=\"sidebar-badge\"{$idAttr} style=\"display:none\"></span>" : '');
    return "<a href=\"{$href}\" class=\"{$activo}\"><span class=\"icono\">{$icono}</span> {$etiqueta}{$badgeHtml}</a>";
}

$iniciales = '';
if ($usuario) {
    $iniciales = mb_strtoupper(mb_substr($usuario['nombre'], 0, 1) . mb_substr($usuario['apellido'], 0, 1));
}
$esAdmin = ($usuario['rol'] ?? '') === 'admin';
$etiquetasRol = [
    'admin'       => 'Administrador',
    'coordinador' => 'Coordinador',
    'tecnico'     => 'Técnico',
    'solicitante' => 'Solicitante',
];

// Conteo inicial de notificaciones para el badge (server-side, sin delay)
$sinLeerInicial = 0;
$sinLeerMensajes = 0;
$sistemaBloqueado = false;
$msgBanner = '';
if ($usuario) {
    $pdo = obtenerConexion();
    $sinLeerInicial   = contarNotificacionesCampana($pdo, $usuario['id'], $usuario['rol']);
    if (rolConBandejaMensajes($usuario['rol'])) {
        $sinLeerMensajes = contarMensajesSinLeer($pdo, $usuario['id']);
    }
    $sistemaBloqueado = ticketsBloqueados($pdo);
    $msgBanner = $sistemaBloqueado
        ? config($pdo, 'mensaje_bloqueo')
        : config($pdo, 'mensaje_habilitado');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina ?? 'Inicio') ?> · Soporte técnico distrital</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700;750&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/estilo.css">
</head>
<body>

<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('abierto')">
    ☰ Menú
</button>

<div class="layout">

<?php if ($usuario): ?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <a href="dashboard.php">
            <div class="sidebar-logo-titulo">Soporte técnico</div>
            <div class="sidebar-logo-sub">ETMH · Proyecto distrital</div>
        </a>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-seccion">Principal</div>
        <?= navLink('dashboard.php',    '⊞', 'Inicio',   $paginaActual) ?>
        <?= navLink('ticket_lista.php', '◫', 'Tickets',  $paginaActual) ?>

        <?php if ($usuario['rol'] === 'solicitante'): ?>
        <div class="sidebar-seccion">Acciones</div>
        <?= navLink('ticket_nuevo.php', '+', 'Nuevo ticket', $paginaActual) ?>
        <?php endif; ?>

        <?php if (rolConBandejaMensajes($usuario['rol'])): ?>
        <?= navLink('mensajes.php', '✉', 'Mensajes', $paginaActual, $sinLeerMensajes, 'sidebarBadgeMensajes') ?>
        <?php endif; ?>

        <?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true)): ?>
        <div class="sidebar-seccion">Gestión</div>
        <?= navLink('estadisticas.php', '↗', 'Estadísticas', $paginaActual) ?>
        <?= navLink('reportes.php',     '≡', 'Reportes',     $paginaActual) ?>
        <?php endif; ?>

        <?php if ($usuario['rol'] === 'admin'): ?>
        <div class="sidebar-seccion">Administración</div>
        <?= navLink('admin_usuarios.php',     '◉', 'Usuarios',         $paginaActual) ?>
        <?= navLink('admin_escuelas.php',     '◎', 'Escuelas',         $paginaActual) ?>
        <?= navLink('admin_tipos_escuela.php','◈', 'Tipos de escuela', $paginaActual) ?>
        <?= navLink('admin_categorias.php',   '◇', 'Categorías',       $paginaActual) ?>
        <?= navLink('admin_bloqueo.php',      '⊘', 'Control de acceso',$paginaActual) ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-pie">
        <div class="sidebar-usuario">
            <div class="sidebar-avatar"><?= e($iniciales) ?></div>
            <div class="sidebar-usuario-info">
                <div class="sidebar-usuario-nombre"><?= e($usuario['nombre'] . ' ' . $usuario['apellido']) ?></div>
                <div class="sidebar-usuario-rol"><?= e($etiquetasRol[$usuario['rol']] ?? $usuario['rol']) ?></div>
            </div>
        </div>
        <a href="logout.php" class="cerrar-sesion">↩ Cerrar sesión</a>
    </div>
</aside>
<?php endif; ?>

<!-- ── Campana de notificaciones ─────────────────────────── -->
<?php if ($usuario): ?>
<div class="campana-wrap" id="campanaWrap">
    <button class="campana-btn" id="campanaBtn"
            onclick="togglePanel(event)"
            aria-label="Notificaciones"
            aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="campana-badge" id="campanaBadge"
              style="display:<?= $sinLeerInicial > 0 ? 'flex' : 'none' ?>">
            <?= $sinLeerInicial > 99 ? '99+' : $sinLeerInicial ?>
        </span>
    </button>

    <div class="notif-panel" id="notifPanel" hidden>
        <div class="notif-panel-header">
            <span class="notif-panel-titulo">Notificaciones</span>
            <button class="notif-marcar-todo" id="marcarTodoBtn" onclick="marcarTodasLeidas()">
                Marcar todo como leído
            </button>
        </div>
        <div class="notif-lista" id="notifLista">
            <div class="notif-vacia">Cargando…</div>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- ─────────────────────────────────────────────────────── -->

<main class="pagina">
<div class="pagina-interior">

<?php if ($usuario && $msgBanner): ?>
    <?php if ($sistemaBloqueado): ?>
    <!-- Banner de sistema bloqueado — visible para TODOS -->
    <div class="banner-sistema banner-bloqueado">
        <span class="banner-icono">🔒</span>
        <div class="banner-cuerpo">
            <strong>Sistema temporalmente suspendido</strong>
            <span><?= e($msgBanner) ?></span>
        </div>
        <?php if ($esAdmin ?? false): ?>
        <a href="admin_bloqueo.php"
           style="flex-shrink:0; font-size:0.78rem; color:#991b1b; font-weight:650; text-decoration:underline; margin-left:0.5rem;">
            Gestionar →
        </a>
        <?php endif; ?>
    </div>
    <?php elseif (in_array($paginaActual, ['ticket_nuevo.php','dashboard.php'])): ?>
    <!-- Banner de disponibilidad — solo en páginas relevantes -->
    <div class="banner-sistema banner-habilitado">
        <span class="banner-icono">🟢</span>
        <div class="banner-cuerpo">
            <strong>Sistema habilitado</strong>
            <span><?= e($msgBanner) ?></span>
        </div>
        <?php if ($esAdmin ?? false): ?>
        <a href="admin_bloqueo.php"
           style="flex-shrink:0; font-size:0.78rem; color:#166534; font-weight:650; text-decoration:underline; margin-left:0.5rem;">
            Configurar →
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($usuario): ?>
<script>
// ── Notificaciones ────────────────────────────────────────
const USUARIO_ID = <?= (int) $usuario['id'] ?>;
let panelAbierto = false;

function togglePanel(e) {
    e.stopPropagation();
    panelAbierto = !panelAbierto;
    const panel = document.getElementById('notifPanel');
    const btn   = document.getElementById('campanaBtn');
    panel.hidden = !panelAbierto;
    btn.setAttribute('aria-expanded', panelAbierto);
    if (panelAbierto) {
        cargarNotificaciones();
    }
}

// Cerrar al hacer click fuera
document.addEventListener('click', e => {
    const wrap = document.getElementById('campanaWrap');
    if (wrap && !wrap.contains(e.target) && panelAbierto) {
        panelAbierto = false;
        document.getElementById('notifPanel').hidden = true;
        document.getElementById('campanaBtn').setAttribute('aria-expanded', false);
    }
});

function actualizarBadge(n) {
    const badge = document.getElementById('campanaBadge');
    if (!badge) return;
    if (n > 0) {
        badge.textContent = n > 99 ? '99+' : n;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

async function pollContador() {
    try {
        const r = await fetch('notificaciones.php?accion=contar');
        const d = await r.json();
        actualizarBadge(d.sin_leer ?? 0);
    } catch (_) {}
}

const TIENE_BANDEJA_MENSAJES = <?= rolConBandejaMensajes($usuario['rol']) ? 'true' : 'false' ?>;

function actualizarBadgeMensajes(n) {
    const badge = document.getElementById('sidebarBadgeMensajes');
    if (!badge) return;
    if (n > 0) {
        badge.textContent = n > 99 ? '99+' : n;
        badge.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
    }
}

async function pollMensajes() {
    if (!TIENE_BANDEJA_MENSAJES) return;
    try {
        const r = await fetch('notificaciones.php?accion=contar_mensajes');
        const d = await r.json();
        actualizarBadgeMensajes(d.sin_leer ?? 0);
    } catch (_) {}
}

async function cargarNotificaciones() {
    const lista = document.getElementById('notifLista');
    lista.innerHTML = '<div class="notif-vacia">Cargando…</div>';
    try {
        const r = await fetch('notificaciones.php?accion=listar');
        const d = await r.json();
        const notifs = d.notificaciones ?? [];
        if (!notifs.length) {
            lista.innerHTML = '<div class="notif-vacia">No tenés notificaciones.</div>';
            return;
        }
        lista.innerHTML = notifs.map(n => `
            <a href="ticket_detalle.php?id=${n.ticket_id}"
               class="notif-item ${n.leida == '1' ? 'notif-leida' : ''}"
               onclick="leerUna(${n.id}, event)">
                <span class="notif-tipo ${n.tipo === 'comentario' ? 'notif-tipo-comentario' : 'notif-tipo-estado'}">
                    ${n.tipo === 'comentario' ? '💬' : '⟳'}
                </span>
                <span class="notif-cuerpo">
                    <span class="notif-mensaje">${escHTML(n.mensaje)}</span>
                    <span class="notif-fecha">${n.fecha_fmt}</span>
                </span>
                ${n.leida == '0' ? '<span class="notif-punto"></span>' : ''}
            </a>
        `).join('');
    } catch (_) {
        lista.innerHTML = '<div class="notif-vacia">Error al cargar.</div>';
    }
}

async function marcarTodasLeidas() {
    await fetch('notificaciones.php?accion=leer', { method: 'POST' });
    actualizarBadge(0);
    await cargarNotificaciones();
}

async function leerUna(id, e) {
    // No cancela la navegación, solo registra en background
    await fetch('notificaciones.php?accion=leer_una', {
        method: 'POST',
        body: new URLSearchParams({ id })
    }).catch(() => {});
}

function escHTML(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Polling cada 30 segundos
pollContador();
pollMensajes();
setInterval(() => { pollContador(); pollMensajes(); }, 30000);
</script>
<?php endif; ?>
