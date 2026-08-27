<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$esAdmin = $usuario['rol'] === 'admin';
$pdo = obtenerConexion();
$ticketId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT t.*, e.nombre AS escuela_nombre, c.nombre AS categoria_nombre,
            CONCAT(sol.nombre, ' ', sol.apellido) AS solicitante_nombre,
            CONCAT(tec.nombre, ' ', tec.apellido) AS tecnico_nombre
     FROM tickets t
     JOIN escuelas e ON e.id = t.escuela_id
     JOIN categorias c ON c.id = t.categoria_id
     JOIN usuarios sol ON sol.id = t.solicitante_id
     LEFT JOIN usuarios tec ON tec.id = t.tecnico_id
     WHERE t.id = :id"
);
$stmt->execute(['id' => $ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    http_response_code(404);
    die('Ticket no encontrado.');
}

// --- Control de acceso: cada rol ve solo lo que le corresponde ---
$puedeVer = match ($usuario['rol']) {
    'admin', 'coordinador' => true,
    'solicitante' => $ticket['solicitante_id'] === $usuario['id'],
    'tecnico'     => $ticket['tecnico_id'] === $usuario['id'],
    default       => false,
};
if (!$puedeVer) {
    http_response_code(403);
    die('No tenés permiso para ver este ticket.');
}

$tituloPagina = 'Ticket #' . $ticket['id'];
$mensajeOk = null;
$error = null;

// Acta de equipo: se usa para exigir cada etapa obligatoria antes de avanzar el ticket
$acta = obtenerActaEquipo($pdo, $ticketId);

// --- Acciones (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'asignar' && in_array($usuario['rol'], ['admin', 'coordinador'], true)
            && ($esAdmin || !in_array($ticket['estado'], ['cerrado', 'cancelado'], true))) {
        if (!actaEtapaCompleta($acta, 'entrega')) {
            $error = 'Antes de asignar un técnico hay que completar el acta de entrega del equipo (la escuela tiene que haberlo entregado al proyecto).';
        } else {
        $tecnicoId = (int) ($_POST['tecnico_id'] ?? 0);
        if ($tecnicoId > 0) {
            $pdo->prepare(
                "UPDATE tickets SET tecnico_id = :tec, estado = 'asignado', fecha_asignacion = NOW() WHERE id = :id"
            )->execute(['tec' => $tecnicoId, 'id' => $ticketId]);
            registrarHistorial($pdo, $ticketId, $ticket['estado'], 'asignado', $usuario['id'], 'Asignado a técnico');
            crearNotificaciones($pdo, $ticketId, 'cambio_estado',
                "Ticket #{$ticketId} asignado a un técnico: \"{$ticket['titulo']}\"", $usuario['id']);
            $mensajeOk = 'Ticket asignado.';
        } else {
            $error = 'Elegí un técnico.';
        }
        }
    }

    if ($accion === 'iniciar'
            && ($esAdmin || ($usuario['rol'] === 'tecnico' && $ticket['tecnico_id'] === $usuario['id']))
            && $ticket['estado'] === 'asignado') {
        if (!actaEtapaCompleta($acta, 'asignacion')) {
            $error = 'Antes de empezar a trabajar hay que completar el acta de asignación (que el equipo pasó del proyecto al técnico).';
        } else {
        $pdo->prepare("UPDATE tickets SET estado = 'en_proceso' WHERE id = :id")->execute(['id' => $ticketId]);
        registrarHistorial($pdo, $ticketId, $ticket['estado'], 'en_proceso', $usuario['id'], 'El técnico comenzó a trabajar en el ticket');
        crearNotificaciones($pdo, $ticketId, 'cambio_estado',
            "Ticket #{$ticketId} pasó a \"En proceso\": \"{$ticket['titulo']}\"", $usuario['id']);
        $mensajeOk = 'Marcado como en proceso.';
        }
    }

    if ($accion === 'resolver'
            && ($esAdmin || ($usuario['rol'] === 'tecnico' && $ticket['tecnico_id'] === $usuario['id']))
            && $ticket['estado'] === 'en_proceso') {
        if (!actaEtapaCompleta($acta, 'resolucion')) {
            $error = 'Antes de marcar como resuelto hay que completar el acta de resolución (describir el trabajo realizado).';
        } else {
        $comentarioResolucion = trim($_POST['comentario_resolucion'] ?? '');
        $pdo->prepare("UPDATE tickets SET estado = 'resuelto', fecha_resolucion = NOW() WHERE id = :id")
            ->execute(['id' => $ticketId]);
        registrarHistorial($pdo, $ticketId, $ticket['estado'], 'resuelto', $usuario['id'], $comentarioResolucion ?: 'Marcado como resuelto');
        crearNotificaciones($pdo, $ticketId, 'cambio_estado',
            "Ticket #{$ticketId} marcado como resuelto: \"{$ticket['titulo']}\"", $usuario['id']);
        $mensajeOk = 'Ticket marcado como resuelto.';
        }
    }

    if ($accion === 'cerrar' && ($esAdmin || $ticket['estado'] === 'resuelto') && (
            ($usuario['rol'] === 'solicitante' && $ticket['solicitante_id'] === $usuario['id'])
            || in_array($usuario['rol'], ['admin', 'coordinador'], true)
        )) {
        if (!actaEtapaCompleta($acta, 'devolucion')) {
            $error = 'Antes de cerrar el ticket hay que completar el acta de devolución del equipo a la escuela.';
        } else {
        $puntaje = (int) ($_POST['puntaje'] ?? 0);
        $pdo->prepare("UPDATE tickets SET estado = 'cerrado', fecha_cierre = NOW() WHERE id = :id")
            ->execute(['id' => $ticketId]);
        registrarHistorial($pdo, $ticketId, $ticket['estado'], 'cerrado', $usuario['id'], 'Ticket cerrado');

        if ($puntaje >= 1 && $puntaje <= 5) {
            $pdo->prepare(
                "INSERT INTO evaluaciones (ticket_id, puntaje, comentario) VALUES (:tid, :p, :c)
                 ON DUPLICATE KEY UPDATE puntaje = :p2, comentario = :c2"
            )->execute([
                'tid' => $ticketId, 'p' => $puntaje, 'c' => trim($_POST['comentario_eval'] ?? ''),
                'p2' => $puntaje, 'c2' => trim($_POST['comentario_eval'] ?? ''),
            ]);
        }
        crearNotificaciones($pdo, $ticketId, 'cambio_estado',
            "Ticket #{$ticketId} cerrado: \"{$ticket['titulo']}\"", $usuario['id']);
        $mensajeOk = 'Ticket cerrado.';
        }
    }

    if ($accion === 'reabrir' && ($esAdmin || in_array($ticket['estado'], ['resuelto', 'cerrado'], true)) && (
            ($usuario['rol'] === 'solicitante' && $ticket['solicitante_id'] === $usuario['id'])
            || in_array($usuario['rol'], ['admin', 'coordinador'], true)
        )) {
        $motivo = trim($_POST['motivo_reapertura'] ?? '');
        $pdo->prepare(
            "UPDATE tickets SET estado = 'en_proceso', veces_reabierto = veces_reabierto + 1 WHERE id = :id"
        )->execute(['id' => $ticketId]);
        registrarHistorial($pdo, $ticketId, $ticket['estado'], 'en_proceso', $usuario['id'], 'Reabierto: ' . ($motivo ?: 'sin motivo especificado'));
        crearNotificaciones($pdo, $ticketId, 'cambio_estado',
            "Ticket #{$ticketId} reabierto: \"{$ticket['titulo']}\"", $usuario['id']);
        $mensajeOk = 'Ticket reabierto.';
    }

    if ($accion === 'cancelar' && in_array($usuario['rol'], ['admin', 'coordinador'], true)
            && ($esAdmin ? $ticket['estado'] !== 'cancelado' : !in_array($ticket['estado'], ['cerrado', 'cancelado'], true))) {
        $motivo = trim($_POST['motivo_cancelacion'] ?? '');
        $pdo->prepare("UPDATE tickets SET estado = 'cancelado' WHERE id = :id")->execute(['id' => $ticketId]);
        registrarHistorial($pdo, $ticketId, $ticket['estado'], 'cancelado', $usuario['id'], $motivo ?: 'Ticket cancelado');
        crearNotificaciones($pdo, $ticketId, 'cambio_estado',
            "Ticket #{$ticketId} cancelado: \"{$ticket['titulo']}\"", $usuario['id']);
        $mensajeOk = 'Ticket cancelado.';
    }

    // El solicitante ya no elige la prioridad al cargar el ticket: la define
    // (o la cambia) el coordinador o el administrador al revisarlo.
    if ($accion === 'cambiar_prioridad' && in_array($usuario['rol'], ['admin', 'coordinador'], true)) {
        $nuevaPrioridad = $_POST['prioridad'] ?? '';
        $prioridadesValidas = ['baja', 'media', 'alta', 'urgente'];
        if (!in_array($nuevaPrioridad, $prioridadesValidas, true)) {
            $error = 'Prioridad inválida.';
        } elseif ($nuevaPrioridad === $ticket['prioridad']) {
            $error = 'Esa ya es la prioridad actual.';
        } else {
            $pdo->prepare('UPDATE tickets SET prioridad = :p WHERE id = :id')
                ->execute(['p' => $nuevaPrioridad, 'id' => $ticketId]);
            $ticket['prioridad'] = $nuevaPrioridad;
            $mensajeOk = 'Prioridad actualizada a "' . ucfirst($nuevaPrioridad) . '".';
        }
    }

    // El administrador puede forzar el ticket a cualquier estado, sin pasar por el flujo normal,
    // pero las actas obligatorias siguen aplicando: no es una vía para saltearlas.
    if ($accion === 'forzar_estado' && $esAdmin) {
        $nuevoEstado = $_POST['nuevo_estado'] ?? '';
        $notaAdmin = trim($_POST['nota_admin'] ?? '');
        $estadosValidos = ['nuevo', 'asignado', 'en_proceso', 'resuelto', 'cerrado', 'cancelado'];
        $etapaRequeridaPorEstado = [
            'asignado'   => 'entrega',
            'en_proceso' => 'asignacion',
            'resuelto'   => 'resolucion',
            'cerrado'    => 'devolucion',
        ];

        if (!in_array($nuevoEstado, $estadosValidos, true)) {
            $error = 'Estado inválido.';
        } elseif ($nuevoEstado === $ticket['estado']) {
            $error = 'Elegí un estado distinto al actual.';
        } elseif (isset($etapaRequeridaPorEstado[$nuevoEstado]) && !actaEtapaCompleta($acta, $etapaRequeridaPorEstado[$nuevoEstado])) {
            $error = 'No se puede forzar el estado a "' . str_replace('_', ' ', $nuevoEstado) . '" porque falta completar el acta de '
                   . $etapaRequeridaPorEstado[$nuevoEstado] . ' (es obligatoria, incluso desde este panel).';
        } else {
            $campos = ['estado = :estado'];
            if ($nuevoEstado === 'asignado' && !$ticket['fecha_asignacion']) {
                $campos[] = 'fecha_asignacion = NOW()';
            }
            if ($nuevoEstado === 'resuelto' && !$ticket['fecha_resolucion']) {
                $campos[] = 'fecha_resolucion = NOW()';
            }
            if ($nuevoEstado === 'cerrado' && !$ticket['fecha_cierre']) {
                $campos[] = 'fecha_cierre = NOW()';
            }
            $pdo->prepare('UPDATE tickets SET ' . implode(', ', $campos) . ' WHERE id = :id')
                ->execute(['estado' => $nuevoEstado, 'id' => $ticketId]);
            registrarHistorial(
                $pdo, $ticketId, $ticket['estado'], $nuevoEstado, $usuario['id'],
                'Cambio manual de estado por administrador' . ($notaAdmin ? (': ' . $notaAdmin) : '')
            );
            crearNotificaciones($pdo, $ticketId, 'cambio_estado',
                "Ticket #{$ticketId} cambió a \"" . str_replace('_', ' ', $nuevoEstado) . "\": \"{$ticket['titulo']}\"",
                $usuario['id']);
            $mensajeOk = 'Estado actualizado manualmente a "' . str_replace('_', ' ', $nuevoEstado) . '".';
        }
    }

    // Subir un archivo adjunto (captura de pantalla, foto del equipo, etc.)
    if ($accion === 'subir_adjunto') {
        if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Elegí un archivo para subir.';
        } elseif ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Hubo un problema al subir el archivo.';
        } else {
            $archivo = $_FILES['archivo'];
            $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $tamanioMaximo = 8 * 1024 * 1024; // 8 MB

            if (!in_array($extension, $extensionesPermitidas, true)) {
                $error = 'Solo se permiten imágenes (jpg, png, gif, webp) o PDF.';
            } elseif ($archivo['size'] > $tamanioMaximo) {
                $error = 'El archivo no puede superar los 8 MB.';
            } else {
                $carpetaDestino = __DIR__ . '/../uploads/adjuntos/';
                $nombreUnico = 'ticket' . $ticketId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                if (move_uploaded_file($archivo['tmp_name'], $carpetaDestino . $nombreUnico)) {
                    $pdo->prepare(
                        'INSERT INTO adjuntos (ticket_id, nombre_archivo, ruta_archivo, usuario_id)
                         VALUES (:tid, :nombre, :ruta, :uid)'
                    )->execute([
                        'tid' => $ticketId,
                        'nombre' => basename($archivo['name']),
                        'ruta' => $nombreUnico,
                        'uid' => $usuario['id'],
                    ]);
                    $mensajeOk = 'Archivo adjuntado correctamente.';
                } else {
                    $error = 'No se pudo guardar el archivo en el servidor.';
                }
            }
        }
    }

    if ($accion === 'comentar') {
        $texto = trim($_POST['comentario'] ?? '');
        $visibilidad = ($_POST['visibilidad'] ?? 'publico') === 'interno' && $usuario['rol'] !== 'solicitante'
            ? 'interno' : 'publico';
        if ($texto !== '') {
            $pdo->prepare(
                "INSERT INTO comentarios (ticket_id, usuario_id, comentario, visibilidad) VALUES (:tid, :uid, :c, :v)"
            )->execute(['tid' => $ticketId, 'uid' => $usuario['id'], 'c' => $texto, 'v' => $visibilidad]);
            $textoCorto = mb_strlen($texto) > 60 ? mb_substr($texto, 0, 57) . '...' : $texto;
            crearNotificaciones($pdo, $ticketId, 'comentario',
                "Nuevo comentario en ticket #{$ticketId} \"{$ticket['titulo']}\": \"{$textoCorto}\"",
                $usuario['id']);
            $mensajeOk = 'Comentario agregado.';
        }
    }

    // ── Gestión de dispositivos (límite de 2 por ticket) ──
    if ($accion === 'agregar_dispositivo') {
        $puedeGestionar = $esAdmin || in_array($usuario['rol'], ['coordinador'], true) || $ticket['solicitante_id'] === $usuario['id'];
        if (!$puedeGestionar) {
            $error = 'No tenés permiso para agregar dispositivos a este ticket.';
        } elseif (in_array($ticket['estado'], ['cerrado', 'cancelado'], true)) {
            $error = 'No se pueden agregar dispositivos a un ticket cerrado o cancelado.';
        } else {
            try {
                $limiteDisp = limiteDispositivosPorTicket($pdo);
                $actualDisp = contarDispositivosTicket($pdo, $ticketId);
                if ($actualDisp >= $limiteDisp) {
                    $error = "Este ticket ya tiene el máximo de {$limiteDisp} dispositivos permitidos. Eliminá uno antes de agregar otro.";
                } else {
                    $tipo        = trim($_POST['tipo'] ?? '');
                    $marcaModelo = trim($_POST['marca_modelo'] ?? '') ?: null;
                    $serie       = trim($_POST['numero_serie'] ?? '') ?: null;
                    $desc        = trim($_POST['descripcion'] ?? '') ?: null;
                    if ($tipo === '') {
                        $error = 'Indicá el tipo de equipo (ej: Notebook, Proyector).';
                    } else {
                        $pdo->prepare(
                            'INSERT INTO ticket_dispositivos (ticket_id, tipo, marca_modelo, numero_serie, descripcion)
                             VALUES (:tid, :tipo, :marca, :serie, :desc)'
                        )->execute([
                            'tid'   => $ticketId,
                            'tipo'  => $tipo,
                            'marca' => $marcaModelo,
                            'serie' => $serie,
                            'desc'  => $desc,
                        ]);
                        $mensajeOk = 'Dispositivo agregado al ticket.';
                    }
                }
            } catch (PDOException $e) {
                // Si la tabla no existe aún
                if (str_contains($e->getMessage(), 'ticket_dispositivos')) {
                    $error = 'La función de dispositivos aún no está disponible (falta aplicar la migración SQL).';
                } else {
                    $error = 'No se pudo agregar el dispositivo.';
                }
            }
        }
    }

    if ($accion === 'eliminar_dispositivo') {
        $puedeGestionar = $esAdmin || in_array($usuario['rol'], ['coordinador'], true) || $ticket['solicitante_id'] === $usuario['id'];
        if (!$puedeGestionar) {
            $error = 'No tenés permiso para quitar dispositivos de este ticket.';
        } elseif (in_array($ticket['estado'], ['cerrado', 'cancelado'], true)) {
            $error = 'No se pueden quitar dispositivos de un ticket cerrado o cancelado.';
        } else {
            $dispId = (int) ($_POST['dispositivo_id'] ?? 0);
            if ($dispId > 0) {
                try {
                    $pdo->prepare('DELETE FROM ticket_dispositivos WHERE id = :did AND ticket_id = :tid')
                        ->execute(['did' => $dispId, 'tid' => $ticketId]);
                    $mensajeOk = 'Dispositivo quitado del ticket.';
                } catch (PDOException $e) {
                    $error = 'No se pudo quitar el dispositivo.';
                }
            }
        }
    }

    // Volver a leer el ticket actualizado tras cualquier acción
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();
}

// --- Historial (trazabilidad) ---
$historial = $pdo->prepare(
    "SELECT h.*, CONCAT(u.nombre, ' ', u.apellido) AS usuario_nombre
     FROM historial_estados h
     JOIN usuarios u ON u.id = h.usuario_id
     WHERE h.ticket_id = :id
     ORDER BY h.fecha ASC"
);
$historial->execute(['id' => $ticketId]);
$historial = $historial->fetchAll();

// --- Comentarios visibles según rol ---
$sqlComentarios = "SELECT cm.*, CONCAT(u.nombre, ' ', u.apellido) AS usuario_nombre, u.rol AS usuario_rol
                    FROM comentarios cm JOIN usuarios u ON u.id = cm.usuario_id
                    WHERE cm.ticket_id = :id";
if ($usuario['rol'] === 'solicitante') {
    $sqlComentarios .= " AND cm.visibilidad = 'publico'";
}
$sqlComentarios .= " ORDER BY cm.fecha ASC";
$comentarios = $pdo->prepare($sqlComentarios);
$comentarios->execute(['id' => $ticketId]);
$comentarios = $comentarios->fetchAll();

// Técnicos disponibles para asignar (solo admin/coordinador lo necesitan)
$tecnicos = [];
if (in_array($usuario['rol'], ['admin', 'coordinador'], true)) {
    $tecnicos = $pdo->query(
        "SELECT id, nombre, apellido, anio_curso FROM usuarios WHERE rol = 'tecnico' AND activo = 1 ORDER BY nombre"
    )->fetchAll();
}

// Adjuntos del ticket (capturas de pantalla, fotos, PDFs)
$adjuntos = $pdo->prepare(
    "SELECT a.*, CONCAT(u.nombre, ' ', u.apellido) AS usuario_nombre
     FROM adjuntos a JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.ticket_id = :id ORDER BY a.fecha DESC"
);
$adjuntos->execute(['id' => $ticketId]);
$adjuntos = $adjuntos->fetchAll();

// Dispositivos del ticket (máximo 2 por ticket)
$limiteDispositivos = 2;
$dispositivos = [];
try {
    $limiteDispositivos = limiteDispositivosPorTicket($pdo);
    $dispositivos = obtenerDispositivosTicket($pdo, $ticketId);
} catch (PDOException $e) {
    // Tabla aún no existe → se muestra vacío sin romper la página
    $dispositivos = [];
}
$cantidadDispositivos = count($dispositivos);
$puedeGestionarDispositivos = $esAdmin || in_array($usuario['rol'], ['coordinador'], true) || $ticket['solicitante_id'] === $usuario['id'];
$ticketCerradoOCancelado = in_array($ticket['estado'], ['cerrado', 'cancelado'], true);

require __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['creado'])): ?>
    <div class="alerta alerta-ok">Ticket creado correctamente. Te avisamos por este sistema cuando lo asignen.</div>
<?php endif; ?>
<?php if ($mensajeOk): ?><div class="alerta alerta-ok"><?= e($mensajeOk) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="pagina-header">
    <div style="display:flex; align-items:baseline; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.4rem;">
        <h1>#<?= (int) $ticket['id'] ?> · <?= e($ticket['titulo']) ?></h1>
        <span class="etiqueta estado-<?= e($ticket['estado']) ?>"><?= ucfirst(str_replace('_', ' ', $ticket['estado'])) ?></span>

        <?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true)): ?>
            <form method="post" style="margin:0; display:flex; align-items:center; gap:0.35rem;">
                <input type="hidden" name="accion" value="cambiar_prioridad">
                <label for="prioridad" class="texto-3 texto-sm" style="margin:0;">Prioridad:</label>
                <select id="prioridad" name="prioridad" class="prioridad-<?= e($ticket['prioridad']) ?>"
                        style="width:auto; padding:0.25rem 0.5rem; font-size:0.8rem; margin:0;"
                        onchange="this.form.submit()">
                    <?php foreach (['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $ticket['prioridad'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php else: ?>
            <span class="prioridad-<?= e($ticket['prioridad']) ?> texto-sm">Prioridad <?= e($ticket['prioridad']) ?></span>
        <?php endif; ?>
    </div>
    <?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true)): ?>
        <p class="texto-3 texto-sm" style="margin:0 0 0.5rem;">La prioridad la definen coordinación y administración, no el solicitante.</p>
    <?php endif; ?>
    <?php if (in_array($usuario['rol'], ['admin', 'coordinador', 'tecnico'], true)): ?>
        <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>" class="boton boton-secundario boton-sm" style="margin-top:0.5rem;">
            📄 Constancia de entrega/recepción
            <span class="etapa-badge <?= actaEtapasCompletas($acta) === 4 ? 'etapa-badge-ok' : 'etapa-badge-pendiente' ?>" style="margin-left:0.4rem;">
                <?= actaEtapasCompletas($acta) ?>/4
            </span>
        </a>
    <?php endif; ?>
</div>

<!-- Metadatos del ticket -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Información del ticket</div>
    <p style="margin-bottom:1rem; line-height:1.65;"><?= nl2br(e($ticket['descripcion'])) ?></p>
    <div class="grid-2" style="gap:0.6rem 1.5rem;">
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Escuela</div>
            <div class="texto-sm"><?= e($ticket['escuela_nombre']) ?></div>
        </div>
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Categoría</div>
            <div class="texto-sm"><?= e($ticket['categoria_nombre']) ?></div>
        </div>
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Reportado por</div>
            <div class="texto-sm"><?= e($ticket['solicitante_nombre']) ?></div>
        </div>
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Técnico asignado</div>
            <div class="texto-sm"><?= $ticket['tecnico_nombre'] ? e($ticket['tecnico_nombre']) : '<span class="texto-3">Sin asignar</span>' ?></div>
        </div>
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Creado</div>
            <div class="texto-sm"><?= date('d/m/Y H:i', strtotime($ticket['fecha_creacion'])) ?></div>
        </div>
        <?php if ($ticket['veces_reabierto'] > 0): ?>
        <div>
            <div class="texto-3" style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;">Reabierto</div>
            <div class="texto-sm"><?= (int) $ticket['veces_reabierto'] ?> vez</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dispositivos del ticket (máximo 2) -->
<div class="tarjeta" id="seccion-dispositivos">
    <div class="tarjeta-titulo" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
        <span>Dispositivos incluidos</span>
        <span class="etiqueta <?= $cantidadDispositivos >= $limiteDispositivos ? 'estado-cancelado' : 'estado-nuevo' ?>" style="font-size:0.78rem;">
            <?= $cantidadDispositivos ?>/<?= $limiteDispositivos ?> dispositivos
        </span>
    </div>
    <p class="texto-2" style="margin-bottom:0.75rem; font-size:0.88rem;">
        Cada ticket puede incluir hasta <strong><?= $limiteDispositivos ?> dispositivos</strong>. Si necesitás reportar más equipos, creá un ticket adicional.
    </p>

    <?php if (empty($dispositivos)): ?>
        <p class="texto-3" style="margin-bottom:0.85rem; font-style:italic;">Aún no se cargaron dispositivos en este ticket.</p>
    <?php else: ?>
        <div style="display:grid; gap:0.75rem; margin-bottom:1rem;">
        <?php foreach ($dispositivos as $idx => $d): ?>
            <div style="border:1px solid var(--borde); background:var(--fondo); border-radius:8px; padding:0.9rem 1rem; display:flex; gap:1rem; align-items:flex-start; justify-content:space-between;">
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:0.95rem; margin-bottom:0.25rem;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--acento); color:#fff; font-size:0.72rem; margin-right:0.35rem;"><?= $idx+1 ?></span>
                        <?= e($d['tipo']) ?>
                    </div>
                    <div class="texto-2 texto-sm" style="line-height:1.5;">
                        <?php if (!empty($d['marca_modelo'])): ?> <strong>Marca/modelo:</strong> <?= e($d['marca_modelo']) ?><br><?php endif; ?>
                        <?php if (!empty($d['numero_serie'])): ?> <strong>N° serie/inventario:</strong> <?= e($d['numero_serie']) ?><br><?php endif; ?>
                        <?php if (!empty($d['descripcion'])): ?> <strong>Falla:</strong> <?= e($d['descripcion']) ?><br><?php endif; ?>
                        <span class="texto-3">Agregado el <?= date('d/m/Y H:i', strtotime($d['fecha_creacion'])) ?></span>
                    </div>
                </div>
                <?php if ($puedeGestionarDispositivos && !$ticketCerradoOCancelado): ?>
                    <form method="post" onsubmit="return confirm('¿Quitar este dispositivo del ticket?')" style="margin:0;">
                        <input type="hidden" name="accion" value="eliminar_dispositivo">
                        <input type="hidden" name="dispositivo_id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="boton boton-secundario boton-sm" style="padding:0.25rem 0.6rem; font-size:0.78rem; background:#fff; border-color:var(--rojo); color:var(--rojo);">Quitar</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($puedeGestionarDispositivos && !$ticketCerradoOCancelado): ?>
        <?php if ($cantidadDispositivos >= $limiteDispositivos): ?>
            <div class="alerta" style="background:var(--rojo-claro); color:var(--rojo); border:1px solid var(--rojo); font-size:0.88rem; padding:0.6rem 0.85rem; margin:0;">
                Llegaste al máximo de <?= $limiteDispositivos ?> dispositivos para este ticket. Si necesitás reportar más equipos, creá un ticket nuevo.
            </div>
        <?php else: ?>
            <form method="post" style="background:#fff; border:1px solid var(--borde); border-radius:8px; padding:1rem;">
                <input type="hidden" name="accion" value="agregar_dispositivo">
                <div style="font-weight:650; font-size:0.9rem; margin-bottom:0.6rem;">Agregar dispositivo</div>
                <div class="grid-2" style="gap:0.75rem;">
                    <div>
                        <label for="disp_tipo" style="font-size:0.82rem; margin-bottom:2px;">Tipo de equipo *</label>
                        <input type="text" id="disp_tipo" name="tipo" required maxlength="100" placeholder="Ej: Notebook, Proyector, Impresora">
                    </div>
                    <div>
                        <label for="disp_marca" style="font-size:0.82rem; margin-bottom:2px;">Marca / modelo</label>
                        <input type="text" id="disp_marca" name="marca_modelo" maxlength="150" placeholder="Ej: Lenovo ThinkPad">
                    </div>
                </div>
                <div class="grid-2" style="gap:0.75rem; margin-top:0.6rem;">
                    <div>
                        <label for="disp_serie" style="font-size:0.82rem; margin-bottom:2px;">N° de serie / inventario</label>
                        <input type="text" id="disp_serie" name="numero_serie" maxlength="100" placeholder="Ej: SN12345">
                    </div>
                    <div>
                        <label for="disp_desc" style="font-size:0.82rem; margin-bottom:2px;">Descripción de la falla</label>
                        <input type="text" id="disp_desc" name="descripcion" maxlength="255" placeholder="Ej: No enciende">
                    </div>
                </div>
                <div class="acciones-fila" style="margin-top:0.75rem;">
                    <button type="submit" class="boton-sm">Agregar dispositivo</button>
                    <span class="texto-3" style="margin-left:0.5rem;"><?= $cantidadDispositivos ?>/<?= $limiteDispositivos ?> usados</span>
                </div>
            </form>
        <?php endif; ?>
    <?php elseif ($ticketCerradoOCancelado): ?>
        <p class="texto-3" style="font-size:0.82rem; margin:0;">Este ticket está <?= e($ticket['estado']) ?> y ya no se pueden agregar o quitar dispositivos.</p>
    <?php else: ?>
        <p class="texto-3" style="font-size:0.82rem; margin:0;">Solo el solicitante del ticket, coordinadores y administradores pueden gestionar los dispositivos.</p>
    <?php endif; ?>
</div>

<!-- Acciones según estado y rol -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Acciones</div>

    <?php if (($ticket['estado'] === 'nuevo' && in_array($usuario['rol'], ['admin', 'coordinador'], true)) || $esAdmin): ?>
        <?php if (!actaEtapaCompleta($acta, 'entrega')): ?>
            <div class="aviso-acta">
                <span>⚠ Falta completar el <strong>acta de entrega</strong> del equipo (paso obligatorio antes de asignar un técnico).</span>
                <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>#etapa-entrega" class="boton boton-secundario boton-sm">Completar acta →</a>
            </div>
        <?php endif; ?>
        <?php if (actaEtapaCompleta($acta, 'entrega')): ?>
        <form method="post">
            <input type="hidden" name="accion" value="asignar">
            <label for="tecnico_id"><?= $ticket['tecnico_id'] ? 'Reasignar a otro técnico' : 'Asignar a técnico' ?></label>
            <select id="tecnico_id" name="tecnico_id" required style="max-width:360px;">
                <option value="">Seleccioná un técnico</option>
                <?php foreach ($tecnicos as $tec): ?>
                    <option value="<?= (int) $tec['id'] ?>" <?= $ticket['tecnico_id'] === $tec['id'] ? 'selected' : '' ?>>
                        <?= e($tec['apellido'] . ', ' . $tec['nombre']) ?><?= $tec['anio_curso'] ? ' — ' . e($tec['anio_curso']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="acciones-fila"><button type="submit">Asignar</button></div>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ticket['estado'] === 'asignado' && ($esAdmin || ($usuario['rol'] === 'tecnico' && $ticket['tecnico_id'] === $usuario['id']))): ?>
        <?php if (!actaEtapaCompleta($acta, 'asignacion')): ?>
            <div class="aviso-acta">
                <span>⚠ Falta completar el <strong>acta de asignación</strong> (que el equipo pasó del proyecto al técnico) antes de empezar a trabajar.</span>
                <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>#etapa-asignacion" class="boton boton-secundario boton-sm">Completar acta →</a>
            </div>
        <?php endif; ?>
        <?php if (actaEtapaCompleta($acta, 'asignacion')): ?>
        <form method="post">
            <input type="hidden" name="accion" value="iniciar">
            <div class="acciones-fila"><button type="submit">Marcar como "en proceso"</button></div>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ticket['estado'] === 'en_proceso' && ($esAdmin || ($usuario['rol'] === 'tecnico' && $ticket['tecnico_id'] === $usuario['id']))): ?>
        <?php if (!actaEtapaCompleta($acta, 'resolucion')): ?>
            <div class="aviso-acta">
                <span>⚠ Falta completar el <strong>acta de resolución</strong> (describir el trabajo realizado) antes de marcar como resuelto.</span>
                <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>#etapa-resolucion" class="boton boton-secundario boton-sm">Completar acta →</a>
            </div>
        <?php endif; ?>
        <?php if (actaEtapaCompleta($acta, 'resolucion')): ?>
        <form method="post">
            <input type="hidden" name="accion" value="resolver">
            <label for="comentario_resolucion">¿Qué se hizo para resolverlo?</label>
            <textarea id="comentario_resolucion" name="comentario_resolucion" placeholder="Describí la solución aplicada"></textarea>
            <div class="acciones-fila"><button type="submit">Marcar como resuelto</button></div>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ticket['estado'] === 'resuelto'): ?>
        <?php if (($usuario['rol'] === 'solicitante' && $ticket['solicitante_id'] === $usuario['id']) || in_array($usuario['rol'], ['admin', 'coordinador'], true)): ?>
            <?php if (!actaEtapaCompleta($acta, 'devolucion')): ?>
                <div class="aviso-acta">
                    <span>⚠ Falta completar el <strong>acta de devolución</strong> del equipo a la escuela antes de cerrar el ticket.</span>
                    <?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true)): ?>
                    <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>#etapa-devolucion" class="boton boton-secundario boton-sm">Completar acta →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (actaEtapaCompleta($acta, 'devolucion')): ?>
            <form method="post">
                <input type="hidden" name="accion" value="cerrar">
                <label for="puntaje">Calificar la atención (opcional)</label>
                <select id="puntaje" name="puntaje" style="max-width:260px;">
                    <option value="">Sin calificar</option>
                    <option value="5">5 — Excelente</option>
                    <option value="4">4 — Buena</option>
                    <option value="3">3 — Regular</option>
                    <option value="2">2 — Mala</option>
                    <option value="1">1 — Muy mala</option>
                </select>
                <textarea name="comentario_eval" placeholder="Comentario sobre la atención (opcional)"></textarea>
                <div class="acciones-fila"><button type="submit">Confirmar y cerrar ticket</button></div>
            </form>
            <?php endif; ?>
            <hr class="separador">
            <form method="post">
                <input type="hidden" name="accion" value="reabrir">
                <label for="motivo_reapertura">El problema persiste — reabrir</label>
                <textarea id="motivo_reapertura" name="motivo_reapertura" placeholder="Describí por qué hay que reabrirlo"></textarea>
                <div class="acciones-fila"><button type="submit" class="boton-secundario">Reabrir ticket</button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ticket['estado'] === 'cerrado' && (($usuario['rol'] === 'solicitante' && $ticket['solicitante_id'] === $usuario['id']) || in_array($usuario['rol'], ['admin', 'coordinador'], true))): ?>
        <form method="post">
            <input type="hidden" name="accion" value="reabrir">
            <label for="motivo_reapertura">Reabrir este ticket</label>
            <textarea id="motivo_reapertura" name="motivo_reapertura" placeholder="Describí por qué hay que reabrirlo"></textarea>
            <div class="acciones-fila"><button type="submit">Reabrir ticket</button></div>
        </form>
    <?php endif; ?>

    <?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true)
            && ($esAdmin ? $ticket['estado'] !== 'cancelado' : !in_array($ticket['estado'], ['cerrado', 'cancelado'], true))): ?>
        <hr class="separador">
        <form method="post">
            <input type="hidden" name="accion" value="cancelar">
            <label for="motivo_cancelacion">Cancelar ticket</label>
            <textarea id="motivo_cancelacion" name="motivo_cancelacion" placeholder="Motivo: no corresponde, ticket duplicado, etc."></textarea>
            <div class="acciones-fila"><button type="submit" class="boton-peligro">Cancelar ticket</button></div>
        </form>
    <?php endif; ?>

    <?php if (in_array($ticket['estado'], ['cerrado', 'cancelado'], true) && !$esAdmin): ?>
        <p class="texto-2">Este ticket está <?= e($ticket['estado']) ?> y no admite más acciones.</p>
    <?php endif; ?>
</div>

<!-- Panel exclusivo del administrador -->
<?php if ($esAdmin): ?>
<div class="panel-admin">
    <div class="tarjeta-titulo">Panel de administrador</div>
    <p class="texto-2" style="margin-bottom:0.75rem;">
        Cambiá el estado del ticket directamente, sin pasar por el flujo normal. El cambio queda registrado en el historial.
        <strong>Las actas obligatorias siguen aplicando</strong>: no vas a poder forzar a "Asignado" sin el acta de entrega,
        a "En proceso" sin la de asignación, a "Resuelto" sin la de resolución, ni a "Cerrado" sin la de devolución.
    </p>
    <?php if (actaEtapasCompletas($acta) < 4): ?>
        <div class="aviso-acta">
            <span>📄 Estado de la constancia: <strong><?= actaEtapasCompletas($acta) ?>/4</strong> etapas completas.</span>
            <a href="constancia_equipo.php?id=<?= (int) $ticket['id'] ?>" class="boton boton-secundario boton-sm">Completar constancia →</a>
        </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="accion" value="forzar_estado">
        <div class="grid-2" style="gap:1rem; align-items:end;">
            <div>
                <label for="nuevo_estado" class="mt-0">Nuevo estado</label>
                <select id="nuevo_estado" name="nuevo_estado" required>
                    <?php foreach (['nuevo', 'asignado', 'en_proceso', 'resuelto', 'cerrado', 'cancelado'] as $est): ?>
                        <option value="<?= e($est) ?>" <?= $ticket['estado'] === $est ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $est)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="nota_admin" class="mt-0">Nota para el historial (opcional)</label>
                <input type="text" id="nota_admin" name="nota_admin" placeholder="Motivo del cambio">
            </div>
        </div>
        <div class="acciones-fila"><button type="submit">Aplicar cambio</button></div>
    </form>
</div>
<?php endif; ?>

<!-- Historial de trazabilidad -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Historial de seguimiento</div>
    <ul class="historial-lista">
    <?php foreach ($historial as $i => $h): ?>
        <li class="historial-item">
            <div class="historial-punto <?= $i === 0 ? 'primer-estado' : '' ?>"></div>
            <div class="historial-contenido">
                <div class="historial-fila-top">
                    <span class="historial-cambio">
                        <?php if ($h['estado_anterior']): ?>
                            <span class="etiqueta estado-<?= e($h['estado_anterior']) ?>"><?= ucfirst(str_replace('_', ' ', $h['estado_anterior'])) ?></span>
                            <span class="texto-3"> → </span>
                        <?php endif; ?>
                        <span class="etiqueta estado-<?= e($h['estado_nuevo']) ?>"><?= ucfirst(str_replace('_', ' ', $h['estado_nuevo'])) ?></span>
                    </span>
                    <span class="historial-fecha"><?= date('d/m/Y H:i', strtotime($h['fecha'])) ?></span>
                </div>
                <div class="historial-usuario texto-3"><?= e($h['usuario_nombre']) ?></div>
                <?php if ($h['comentario']): ?>
                    <div class="historial-nota"><?= e($h['comentario']) ?></div>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<!-- Adjuntos -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Adjuntos</div>
    <?php if ($adjuntos): ?>
        <div class="adjuntos-grid">
        <?php foreach ($adjuntos as $a): ?>
            <?php $esImagen = in_array(strtolower(pathinfo($a['ruta_archivo'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true); ?>
            <div class="adjunto-card">
                <?php if ($esImagen): ?>
                    <a href="adjunto_ver.php?id=<?= (int) $a['id'] ?>" target="_blank">
                        <img src="adjunto_ver.php?id=<?= (int) $a['id'] ?>" alt="<?= e($a['nombre_archivo']) ?>">
                    </a>
                <?php else: ?>
                    <div style="height:60px; display:flex; align-items:center; justify-content:center; background:var(--fondo); font-size:1.8rem;">📄</div>
                <?php endif; ?>
                <div class="adjunto-card-info">
                    <a href="adjunto_ver.php?id=<?= (int) $a['id'] ?>" target="_blank" class="adjunto-card-nombre"><?= e($a['nombre_archivo']) ?></a>
                    <div class="adjunto-card-meta"><?= e($a['usuario_nombre']) ?> · <?= date('d/m/Y', strtotime($a['fecha'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="texto-2" style="margin-bottom:0.75rem;">Sin archivos adjuntos todavía.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="subir_adjunto">
        <label for="archivo" class="mt-0" style="margin-top:0.75rem;">Subir imagen o PDF (máx. 8 MB)</label>
        <input type="file" id="archivo" name="archivo" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
        <div class="acciones-fila"><button type="submit">Subir adjunto</button></div>
    </form>
</div>

<!-- Comentarios -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Comentarios</div>
    <?php if (!$comentarios): ?>
        <p class="texto-2" style="margin-bottom:0.75rem;">Todavía no hay comentarios.</p>
    <?php endif; ?>
    <?php foreach ($comentarios as $c): ?>
        <div class="comentario-item">
            <div class="comentario-cabecera">
                <span class="comentario-autor"><?= e($c['usuario_nombre']) ?></span>
                <?php if ($c['visibilidad'] === 'interno'): ?>
                    <span class="badge-interno">Interno</span>
                <?php endif; ?>
                <span class="comentario-fecha"><?= date('d/m/Y H:i', strtotime($c['fecha'])) ?></span>
            </div>
            <div class="comentario-cuerpo"><?= nl2br(e($c['comentario'])) ?></div>
        </div>
    <?php endforeach; ?>

    <form method="post" style="margin-top:1rem;">
        <input type="hidden" name="accion" value="comentar">
        <label for="comentario">Agregar comentario</label>
        <textarea id="comentario" name="comentario" required placeholder="Escribí tu comentario aquí…"></textarea>
        <?php if ($usuario['rol'] !== 'solicitante'): ?>
            <label for="visibilidad">Visibilidad</label>
            <select id="visibilidad" name="visibilidad" style="max-width:320px;">
                <option value="publico">Público — lo ve el solicitante</option>
                <option value="interno">Interno — solo equipo de soporte</option>
            </select>
        <?php endif; ?>
        <div class="acciones-fila"><button type="submit">Comentar</button></div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
