<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['solicitante', 'coordinador']);

$usuario = usuarioActual();
$pdo = obtenerConexion();
$tituloPagina = 'Mensajes';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'leer_todo') {
    $pdo->prepare(
        "UPDATE notificaciones SET leida = 1 WHERE usuario_id = :uid AND leida = 0 AND tipo = 'comentario'"
    )->execute(['uid' => $usuario['id']]);
    header('Location: mensajes.php');
    exit;
}

$mensajes = obtenerMensajes($pdo, $usuario['id'], 100);

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Mensajes</h1>
    <p>Comentarios nuevos en tus tickets. Los cambios de estado siguen apareciendo en la campana 🔔.</p>
</div>

<div class="tarjeta">
    <div class="tarjeta-titulo" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; flex-wrap:wrap;">
        <span>Bandeja de mensajes</span>
        <?php if ($mensajes): ?>
        <form method="post" style="margin:0;">
            <input type="hidden" name="accion" value="leer_todo">
            <button type="submit" class="boton-secundario boton-sm" style="margin:0;">Marcar todo como leído</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$mensajes): ?>
        <p class="texto-2">No tenés mensajes todavía. Acá vas a ver los comentarios nuevos en tus tickets.</p>
    <?php else: ?>
        <ul class="mensajes-lista">
        <?php foreach ($mensajes as $m): ?>
            <li>
                <a href="ticket_detalle.php?id=<?= (int) $m['ticket_id'] ?>"
                   class="mensaje-item <?= $m['leida'] == '1' ? 'mensaje-leido' : '' ?>">
                    <span class="mensaje-icono">💬</span>
                    <span class="mensaje-cuerpo">
                        <span class="mensaje-titulo"><?= e($m['ticket_titulo']) ?></span>
                        <span class="mensaje-texto"><?= e($m['mensaje']) ?></span>
                        <span class="mensaje-fecha"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></span>
                    </span>
                    <?php if ($m['leida'] == '0'): ?><span class="notif-punto"></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
