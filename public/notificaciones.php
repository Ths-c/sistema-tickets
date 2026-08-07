<?php
/**
 * Endpoint de notificaciones y mensajes.
 *
 * Campanita (cambios de estado; para solicitante/coordinador excluye comentarios):
 *   GET  ?accion=contar         → { sin_leer: N }
 *   GET  ?accion=listar         → últimas notificaciones de la campanita
 *   POST ?accion=leer           → marca como leídas las de la campanita
 *   POST ?accion=leer_una       → marca una notificación puntual (body: id=N)
 *
 * Bandeja de mensajes (solo comentarios; solo solicitante/coordinador):
 *   GET  ?accion=contar_mensajes  → { sin_leer: N }
 *   GET  ?accion=listar_mensajes  → mensajes (comentarios) del usuario
 *   POST ?accion=leer_mensajes    → marca todos los mensajes como leídos
 */
require_once __DIR__ . '/../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');

if (!usuarioActual()) {
    http_response_code(401);
    echo json_encode(['error' => 'no autenticado']);
    exit;
}

$usuario = usuarioActual();
$pdo     = obtenerConexion();
$accion  = $_REQUEST['accion'] ?? 'contar';

// ── Campanita ────────────────────────────────────────────────
if ($accion === 'contar') {
    echo json_encode(['sin_leer' => contarNotificacionesCampana($pdo, $usuario['id'], $usuario['rol'])]);
    exit;
}

if ($accion === 'listar') {
    $notifs = obtenerNotificacionesCampana($pdo, $usuario['id'], $usuario['rol'], 15);
    foreach ($notifs as &$n) {
        $n['fecha_fmt'] = date('d/m/Y H:i', strtotime($n['fecha']));
    }
    echo json_encode(['notificaciones' => $notifs]);
    exit;
}

if ($accion === 'leer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (rolConBandejaMensajes($usuario['rol'])) {
        // No marcar como leídos los mensajes (comentarios): esos se leen desde su propia bandeja.
        $pdo->prepare(
            "UPDATE notificaciones SET leida = 1 WHERE usuario_id = :uid AND leida = 0 AND tipo = 'cambio_estado'"
        )->execute(['uid' => $usuario['id']]);
    } else {
        $pdo->prepare('UPDATE notificaciones SET leida = 1 WHERE usuario_id = :uid AND leida = 0')
            ->execute(['uid' => $usuario['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'leer_una' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('UPDATE notificaciones SET leida = 1 WHERE id = :id AND usuario_id = :uid')
        ->execute(['id' => $id, 'uid' => $usuario['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Bandeja de mensajes (solo solicitante/coordinador) ────────
if (in_array($accion, ['contar_mensajes', 'listar_mensajes', 'leer_mensajes'], true)) {
    if (!rolConBandejaMensajes($usuario['rol'])) {
        http_response_code(403);
        echo json_encode(['error' => 'este rol no tiene bandeja de mensajes']);
        exit;
    }

    if ($accion === 'contar_mensajes') {
        echo json_encode(['sin_leer' => contarMensajesSinLeer($pdo, $usuario['id'])]);
        exit;
    }

    if ($accion === 'listar_mensajes') {
        $mensajes = obtenerMensajes($pdo, $usuario['id'], 100);
        foreach ($mensajes as &$m) {
            $m['fecha_fmt'] = date('d/m/Y H:i', strtotime($m['fecha']));
        }
        echo json_encode(['mensajes' => $mensajes]);
        exit;
    }

    if ($accion === 'leer_mensajes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare(
            "UPDATE notificaciones SET leida = 1 WHERE usuario_id = :uid AND leida = 0 AND tipo = 'comentario'"
        )->execute(['uid' => $usuario['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'accion desconocida']);
