<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$esAdmin = $usuario['rol'] === 'admin';
$pdo = obtenerConexion();
$ticketId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT t.*, e.nombre AS escuela_nombre, e.localidad AS escuela_localidad, c.nombre AS categoria_nombre,
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

$puedeVer = match ($usuario['rol']) {
    'admin', 'coordinador' => true,
    'solicitante' => $ticket['solicitante_id'] === $usuario['id'],
    'tecnico'     => $ticket['tecnico_id'] === $usuario['id'],
    default       => false,
};
if (!$puedeVer) {
    http_response_code(403);
    die('No tenés permiso para ver esta constancia.');
}

$puedeEditar = $esAdmin || $usuario['rol'] === 'coordinador'
    || ($usuario['rol'] === 'tecnico' && $ticket['tecnico_id'] === $usuario['id']);

$mensajeOk = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar) {
    $datos = [
        'ticket_id'                    => $ticketId,
        'equipo_tipo'                   => trim($_POST['equipo_tipo'] ?? ''),
        'equipo_marca_modelo'           => trim($_POST['equipo_marca_modelo'] ?? ''),
        'equipo_numero_serie'           => trim($_POST['equipo_numero_serie'] ?? ''),
        'accesorios'                    => trim($_POST['accesorios'] ?? ''),

        'entrega_fecha'                 => $_POST['entrega_fecha'] ?? null,
        'entrega_estado_equipo'         => trim($_POST['entrega_estado_equipo'] ?? ''),
        'entrega_nombre_escuela'        => trim($_POST['entrega_nombre_escuela'] ?? ''),
        'entrega_cargo_escuela'         => trim($_POST['entrega_cargo_escuela'] ?? ''),
        'entrega_nombre_receptor'       => trim($_POST['entrega_nombre_receptor'] ?? ''),

        'asignacion_fecha'              => $_POST['asignacion_fecha'] ?? null,
        'asignacion_nombre_tecnico'     => trim($_POST['asignacion_nombre_tecnico'] ?? ''),
        'asignacion_observaciones'      => trim($_POST['asignacion_observaciones'] ?? ''),

        'resolucion_fecha'              => $_POST['resolucion_fecha'] ?? null,
        'resolucion_trabajo_realizado'  => trim($_POST['resolucion_trabajo_realizado'] ?? ''),
        'resolucion_estado_equipo'      => trim($_POST['resolucion_estado_equipo'] ?? ''),

        'devolucion_fecha'              => $_POST['devolucion_fecha'] ?? null,
        'devolucion_nombre_tecnico'     => trim($_POST['devolucion_nombre_tecnico'] ?? ''),
        'devolucion_estado_equipo'      => trim($_POST['devolucion_estado_equipo'] ?? ''),
        'devolucion_nombre_escuela'     => trim($_POST['devolucion_nombre_escuela'] ?? ''),
        'devolucion_cargo_escuela'      => trim($_POST['devolucion_cargo_escuela'] ?? ''),

        'usuario_id'                    => $usuario['id'],
    ];
    foreach (['entrega_fecha', 'asignacion_fecha', 'resolucion_fecha', 'devolucion_fecha'] as $campoFecha) {
        $datos[$campoFecha] = $datos[$campoFecha] !== '' ? str_replace('T', ' ', $datos[$campoFecha]) : null;
    }

    $pdo->prepare(
        "INSERT INTO actas_equipo (
            ticket_id, equipo_tipo, equipo_marca_modelo, equipo_numero_serie, accesorios,
            entrega_fecha, entrega_estado_equipo, entrega_nombre_escuela, entrega_cargo_escuela, entrega_nombre_receptor,
            asignacion_fecha, asignacion_nombre_tecnico, asignacion_observaciones,
            resolucion_fecha, resolucion_trabajo_realizado, resolucion_estado_equipo,
            devolucion_fecha, devolucion_nombre_tecnico, devolucion_estado_equipo, devolucion_nombre_escuela, devolucion_cargo_escuela,
            usuario_id
        ) VALUES (
            :ticket_id, :equipo_tipo, :equipo_marca_modelo, :equipo_numero_serie, :accesorios,
            :entrega_fecha, :entrega_estado_equipo, :entrega_nombre_escuela, :entrega_cargo_escuela, :entrega_nombre_receptor,
            :asignacion_fecha, :asignacion_nombre_tecnico, :asignacion_observaciones,
            :resolucion_fecha, :resolucion_trabajo_realizado, :resolucion_estado_equipo,
            :devolucion_fecha, :devolucion_nombre_tecnico, :devolucion_estado_equipo, :devolucion_nombre_escuela, :devolucion_cargo_escuela,
            :usuario_id
        )
        ON DUPLICATE KEY UPDATE
            equipo_tipo = VALUES(equipo_tipo), equipo_marca_modelo = VALUES(equipo_marca_modelo),
            equipo_numero_serie = VALUES(equipo_numero_serie), accesorios = VALUES(accesorios),
            entrega_fecha = VALUES(entrega_fecha), entrega_estado_equipo = VALUES(entrega_estado_equipo),
            entrega_nombre_escuela = VALUES(entrega_nombre_escuela), entrega_cargo_escuela = VALUES(entrega_cargo_escuela),
            entrega_nombre_receptor = VALUES(entrega_nombre_receptor),
            asignacion_fecha = VALUES(asignacion_fecha), asignacion_nombre_tecnico = VALUES(asignacion_nombre_tecnico),
            asignacion_observaciones = VALUES(asignacion_observaciones),
            resolucion_fecha = VALUES(resolucion_fecha), resolucion_trabajo_realizado = VALUES(resolucion_trabajo_realizado),
            resolucion_estado_equipo = VALUES(resolucion_estado_equipo),
            devolucion_fecha = VALUES(devolucion_fecha), devolucion_nombre_tecnico = VALUES(devolucion_nombre_tecnico),
            devolucion_estado_equipo = VALUES(devolucion_estado_equipo), devolucion_nombre_escuela = VALUES(devolucion_nombre_escuela),
            devolucion_cargo_escuela = VALUES(devolucion_cargo_escuela),
            usuario_id = VALUES(usuario_id)"
    )->execute($datos);

    $mensajeOk = 'Constancia guardada.';
}

$acta = obtenerActaEquipo($pdo, $ticketId);

$v = fn(string $campo, string $default = '') => e($acta[$campo] ?? $default);
$fechaInputValor = fn(?string $valor) => $valor ? str_replace(' ', 'T', substr($valor, 0, 16)) : '';

$etapaOk = fn(string $etapa) => actaEtapaCompleta($acta, $etapa);

// ---------------------------------------------------------------------
// VISTA DE IMPRESIÓN (se convierte en PDF con "Guardar como PDF" del navegador)
// ---------------------------------------------------------------------
if (isset($_GET['imprimir'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Constancia ticket #<?= (int) $ticket['id'] ?></title>
        <style>
            @page { size: A4; margin: 16mm; }
            * { box-sizing: border-box; }
            body { font-family: Georgia, "Times New Roman", serif; color: #1a1a1a; font-size: 12.5px; line-height: 1.42; }
            h1 { text-align: center; font-size: 16px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: 0.5px; }
            h2 { font-size: 13px; border-bottom: 1px solid #333; padding-bottom: 3px; margin: 16px 0 8px; }
            .subt { text-align: center; font-size: 11.5px; color: #444; margin: 0 0 14px; }
            .fila { display: flex; gap: 16px; margin-bottom: 5px; }
            .fila .campo { flex: 1; }
            .campo strong { display: block; font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: 0.3px; }
            .renglon { border-bottom: 1px solid #999; min-height: 16px; padding-top: 1px; }
            .firmas { display: flex; gap: 24px; margin-top: 18px; }
            .firma { flex: 1; text-align: center; }
            .firma .linea { border-top: 1px solid #333; margin-top: 28px; padding-top: 4px; font-size: 10.5px; }
            .nota { font-size: 10.5px; color: #555; margin-top: 4px; }
            .botonera { text-align: center; margin-bottom: 14px; }
            .botonera button { font-size: 14px; padding: 8px 18px; cursor: pointer; }
            @media print { .botonera { display: none; } }
        </style>
    </head>
    <body>
        <div class="botonera">
            <button onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button>
        </div>

        <h1>Constancia de entrega y recepción de equipo</h1>
        <p class="subt">Soporte técnico distrital · Escuela Técnica de Monte Hermoso<br>Ticket #<?= (int) $ticket['id'] ?> — <?= e($ticket['titulo']) ?></p>

        <div class="fila">
            <div class="campo"><strong>Escuela</strong><div class="renglon"><?= e($ticket['escuela_nombre']) ?> — <?= e($ticket['escuela_localidad']) ?></div></div>
            <div class="campo"><strong>Categoría del ticket</strong><div class="renglon"><?= e($ticket['categoria_nombre']) ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Tipo de equipo</strong><div class="renglon"><?= $v('equipo_tipo') ?: '&nbsp;' ?></div></div>
            <div class="campo"><strong>Marca / modelo</strong><div class="renglon"><?= $v('equipo_marca_modelo') ?: '&nbsp;' ?></div></div>
            <div class="campo"><strong>N° de serie / inventario</strong><div class="renglon"><?= $v('equipo_numero_serie') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Accesorios</strong><div class="renglon"><?= $v('accesorios') ?: '&nbsp;' ?></div></div>
        </div>

        <h2>1. Entrega del equipo (Escuela → Proyecto)</h2>
        <div class="fila">
            <div class="campo"><strong>Fecha y hora</strong><div class="renglon"><?= !empty($acta['entrega_fecha']) ? e(date('d/m/Y H:i', strtotime($acta['entrega_fecha']))) : '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Estado del equipo al momento de la entrega</strong><div class="renglon"><?= $v('entrega_estado_equipo') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="firmas">
            <div class="firma">
                <div class="linea"><?= $v('entrega_nombre_escuela') ?: '&nbsp;' ?></div>
                <div class="nota">Quien entrega (escuela) — <?= $v('entrega_cargo_escuela') ?: 'cargo' ?></div>
            </div>
            <div class="firma">
                <div class="linea"><?= $v('entrega_nombre_receptor') ?: '&nbsp;' ?></div>
                <div class="nota">Quien recibe por el proyecto</div>
            </div>
        </div>

        <h2>2. Asignación a técnico (Proyecto → Técnico)</h2>
        <div class="fila">
            <div class="campo"><strong>Fecha y hora</strong><div class="renglon"><?= !empty($acta['asignacion_fecha']) ? e(date('d/m/Y H:i', strtotime($acta['asignacion_fecha']))) : '&nbsp;' ?></div></div>
            <div class="campo"><strong>Técnico asignado</strong><div class="renglon"><?= $v('asignacion_nombre_tecnico') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Observaciones</strong><div class="renglon"><?= $v('asignacion_observaciones') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="firmas">
            <div class="firma">
                <div class="linea"><?= $v('asignacion_nombre_tecnico') ?: '&nbsp;' ?></div>
                <div class="nota">Técnico que recibe el equipo</div>
            </div>
        </div>

        <h2>3. Resolución (trabajo realizado)</h2>
        <div class="fila">
            <div class="campo"><strong>Fecha y hora</strong><div class="renglon"><?= !empty($acta['resolucion_fecha']) ? e(date('d/m/Y H:i', strtotime($acta['resolucion_fecha']))) : '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Trabajo realizado</strong><div class="renglon"><?= $v('resolucion_trabajo_realizado') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Estado del equipo al finalizar</strong><div class="renglon"><?= $v('resolucion_estado_equipo') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="firmas">
            <div class="firma">
                <div class="linea"><?= $v('asignacion_nombre_tecnico') ?: e($ticket['tecnico_nombre'] ?? '') ?></div>
                <div class="nota">Técnico responsable</div>
            </div>
        </div>

        <h2>4. Devolución del equipo (Proyecto → Escuela originante)</h2>
        <div class="fila">
            <div class="campo"><strong>Fecha y hora</strong><div class="renglon"><?= !empty($acta['devolucion_fecha']) ? e(date('d/m/Y H:i', strtotime($acta['devolucion_fecha']))) : '&nbsp;' ?></div></div>
        </div>
        <div class="fila">
            <div class="campo"><strong>Estado del equipo al momento de la devolución</strong><div class="renglon"><?= $v('devolucion_estado_equipo') ?: '&nbsp;' ?></div></div>
        </div>
        <div class="firmas">
            <div class="firma">
                <div class="linea"><?= $v('devolucion_nombre_tecnico') ?: '&nbsp;' ?></div>
                <div class="nota">Técnico que devuelve el equipo</div>
            </div>
            <div class="firma">
                <div class="linea"><?= $v('devolucion_nombre_escuela') ?: '&nbsp;' ?></div>
                <div class="nota">Quien recibe (escuela) — <?= $v('devolucion_cargo_escuela') ?: 'cargo' ?></div>
            </div>
        </div>

        <p class="nota" style="margin-top:18px;">Todas las partes firman conformes con la información consignada en esta constancia, en el marco del proyecto distrital de soporte técnico de la Escuela Técnica de Monte Hermoso.</p>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------------
// VISTA NORMAL (edición / consulta)
// ---------------------------------------------------------------------
$tituloPagina = 'Constancia de equipo · Ticket #' . $ticket['id'];
require __DIR__ . '/../includes/header.php';

$etapas = [
    'entrega'    => '1. Entrega',
    'asignacion' => '2. Asignación',
    'resolucion' => '3. Resolución',
    'devolucion' => '4. Devolución',
];
?>

<h1>Constancia de entrega y recepción — Ticket #<?= (int) $ticket['id'] ?></h1>
<p class="texto-secundario"><?= e($ticket['titulo']) ?> · <?= e($ticket['escuela_nombre']) ?></p>

<?php if ($mensajeOk): ?><div class="alerta alerta-ok"><?= e($mensajeOk) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="tarjeta" style="margin-bottom:1rem;">
    <div class="tarjeta-titulo">Progreso de la constancia (<?= actaEtapasCompletas($acta) ?>/4)</div>
    <p class="texto-2" style="margin-bottom:0.75rem;">
        Cada etapa es obligatoria para poder avanzar el ticket: sin el acta de entrega no se puede asignar técnico,
        sin la de asignación no se puede pasar a "en proceso", sin la de resolución no se puede marcar como resuelto,
        y sin la de devolución no se puede cerrar el ticket.
    </p>
    <div class="etapas-acta">
        <?php foreach ($etapas as $clave => $titulo): ?>
            <a href="#etapa-<?= $clave ?>" class="etapa-acta-pill <?= $etapaOk($clave) ? 'etapa-ok' : 'etapa-pendiente' ?>">
                <?= $etapaOk($clave) ? '✓' : '○' ?> <?= e($titulo) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="acciones-fila" style="margin-top:0; margin-bottom:1.25rem;">
    <a href="constancia_pdf.php?id=<?= (int) $ticket['id'] ?>" class="boton" style="background:#dc2626;" target="_blank">
        ⬇ Descargar constancia PDF
    </a>
    <a href="ticket_detalle.php?id=<?= (int) $ticket['id'] ?>" class="boton boton-secundario">← Volver al ticket</a>
</div>

<?php if (!$puedeEditar): ?>
    <div class="tarjeta">
        <p class="texto-secundario">Solo podés consultar e imprimir esta constancia. La completan el técnico, el coordinador o el administrador.</p>
    </div>
<?php else: ?>
<form method="post">
    <div class="tarjeta">
        <h2>Datos del equipo</h2>
        <div class="grid-2">
            <div>
                <label for="equipo_tipo">Tipo de equipo</label>
                <input type="text" id="equipo_tipo" name="equipo_tipo" placeholder="Ej: Notebook, proyector, impresora" value="<?= $v('equipo_tipo') ?>">
            </div>
            <div>
                <label for="equipo_marca_modelo">Marca / modelo</label>
                <input type="text" id="equipo_marca_modelo" name="equipo_marca_modelo" value="<?= $v('equipo_marca_modelo') ?>">
            </div>
        </div>
        <div class="grid-2">
            <div>
                <label for="equipo_numero_serie">N° de serie / inventario</label>
                <input type="text" id="equipo_numero_serie" name="equipo_numero_serie" value="<?= $v('equipo_numero_serie') ?>">
            </div>
            <div>
                <label for="accesorios">Accesorios entregados</label>
                <input type="text" id="accesorios" name="accesorios" placeholder="Ej: cargador, mouse, funda" value="<?= $v('accesorios') ?>">
            </div>
        </div>
    </div>

    <div class="tarjeta" id="etapa-entrega">
        <h2>
            1. Entrega del equipo (Escuela → Proyecto)
            <span class="etapa-badge <?= $etapaOk('entrega') ? 'etapa-badge-ok' : 'etapa-badge-pendiente' ?>"><?= $etapaOk('entrega') ? 'Completa' : 'Pendiente' ?></span>
        </h2>
        <p class="texto-2" style="margin-bottom:0.75rem;">Se completa cuando la escuela entrega físicamente el equipo al proyecto, antes de asignarlo a un técnico.</p>
        <div class="grid-2">
            <div>
                <label for="entrega_fecha">Fecha y hora de entrega</label>
                <input type="datetime-local" id="entrega_fecha" name="entrega_fecha" value="<?= e($fechaInputValor($acta['entrega_fecha'] ?? null)) ?>">
            </div>
            <div>
                <label for="entrega_nombre_receptor">Quien recibe (por el proyecto)</label>
                <input type="text" id="entrega_nombre_receptor" name="entrega_nombre_receptor"
                       value="<?= $v('entrega_nombre_receptor', $usuario['nombre'] . ' ' . $usuario['apellido']) ?>">
            </div>
        </div>
        <label for="entrega_estado_equipo">Estado del equipo al momento de la entrega</label>
        <textarea id="entrega_estado_equipo" name="entrega_estado_equipo" placeholder="Ej: con detalles estéticos en la tapa, enciende pero no carga"><?= $v('entrega_estado_equipo') ?></textarea>
        <div class="grid-2">
            <div>
                <label for="entrega_nombre_escuela">Quien entrega (por la escuela)</label>
                <input type="text" id="entrega_nombre_escuela" name="entrega_nombre_escuela" value="<?= $v('entrega_nombre_escuela') ?>">
            </div>
            <div>
                <label for="entrega_cargo_escuela">Cargo</label>
                <input type="text" id="entrega_cargo_escuela" name="entrega_cargo_escuela" placeholder="Ej: directora, secretario" value="<?= $v('entrega_cargo_escuela') ?>">
            </div>
        </div>
    </div>

    <div class="tarjeta" id="etapa-asignacion">
        <h2>
            2. Asignación a técnico (Proyecto → Técnico)
            <span class="etapa-badge <?= $etapaOk('asignacion') ? 'etapa-badge-ok' : 'etapa-badge-pendiente' ?>"><?= $etapaOk('asignacion') ? 'Completa' : 'Pendiente' ?></span>
        </h2>
        <p class="texto-2" style="margin-bottom:0.75rem;">Se completa cuando el equipo pasa de manos del proyecto al técnico que va a repararlo.</p>
        <div class="grid-2">
            <div>
                <label for="asignacion_fecha">Fecha y hora de asignación</label>
                <input type="datetime-local" id="asignacion_fecha" name="asignacion_fecha" value="<?= e($fechaInputValor($acta['asignacion_fecha'] ?? null)) ?>">
            </div>
            <div>
                <label for="asignacion_nombre_tecnico">Técnico que recibe el equipo</label>
                <input type="text" id="asignacion_nombre_tecnico" name="asignacion_nombre_tecnico"
                       value="<?= $v('asignacion_nombre_tecnico', $ticket['tecnico_nombre'] ?? '') ?>">
            </div>
        </div>
        <label for="asignacion_observaciones">Observaciones (opcional)</label>
        <textarea id="asignacion_observaciones" name="asignacion_observaciones" placeholder="Cualquier detalle relevante de la asignación"><?= $v('asignacion_observaciones') ?></textarea>
    </div>

    <div class="tarjeta" id="etapa-resolucion">
        <h2>
            3. Resolución (trabajo realizado)
            <span class="etapa-badge <?= $etapaOk('resolucion') ? 'etapa-badge-ok' : 'etapa-badge-pendiente' ?>"><?= $etapaOk('resolucion') ? 'Completa' : 'Pendiente' ?></span>
        </h2>
        <p class="texto-2" style="margin-bottom:0.75rem;">La completa el técnico antes de marcar el ticket como resuelto.</p>
        <div class="grid-2">
            <div>
                <label for="resolucion_fecha">Fecha y hora</label>
                <input type="datetime-local" id="resolucion_fecha" name="resolucion_fecha" value="<?= e($fechaInputValor($acta['resolucion_fecha'] ?? null)) ?>">
            </div>
            <div>
                <label for="resolucion_estado_equipo">Estado del equipo al finalizar</label>
                <input type="text" id="resolucion_estado_equipo" name="resolucion_estado_equipo" value="<?= $v('resolucion_estado_equipo') ?>">
            </div>
        </div>
        <label for="resolucion_trabajo_realizado">Trabajo realizado</label>
        <textarea id="resolucion_trabajo_realizado" name="resolucion_trabajo_realizado" placeholder="Ej: se reemplazó la batería y se actualizó el sistema"><?= $v('resolucion_trabajo_realizado') ?></textarea>
    </div>

    <div class="tarjeta" id="etapa-devolucion">
        <h2>
            4. Devolución del equipo (Proyecto → Escuela originante)
            <span class="etapa-badge <?= $etapaOk('devolucion') ? 'etapa-badge-ok' : 'etapa-badge-pendiente' ?>"><?= $etapaOk('devolucion') ? 'Completa' : 'Pendiente' ?></span>
        </h2>
        <p class="texto-2" style="margin-bottom:0.75rem;">Se completa cuando el equipo ya reparado vuelve a la escuela que originó el ticket, antes de cerrarlo.</p>
        <div class="grid-2">
            <div>
                <label for="devolucion_fecha">Fecha y hora de devolución</label>
                <input type="datetime-local" id="devolucion_fecha" name="devolucion_fecha" value="<?= e($fechaInputValor($acta['devolucion_fecha'] ?? null)) ?>">
            </div>
            <div>
                <label for="devolucion_nombre_tecnico">Técnico que devuelve</label>
                <input type="text" id="devolucion_nombre_tecnico" name="devolucion_nombre_tecnico"
                       value="<?= $v('devolucion_nombre_tecnico', $ticket['tecnico_nombre'] ?? '') ?>">
            </div>
        </div>
        <label for="devolucion_estado_equipo">Estado del equipo al momento de la devolución</label>
        <textarea id="devolucion_estado_equipo" name="devolucion_estado_equipo"><?= $v('devolucion_estado_equipo') ?></textarea>
        <div class="grid-2">
            <div>
                <label for="devolucion_nombre_escuela">Quien recibe (por la escuela)</label>
                <input type="text" id="devolucion_nombre_escuela" name="devolucion_nombre_escuela" value="<?= $v('devolucion_nombre_escuela') ?>">
            </div>
            <div>
                <label for="devolucion_cargo_escuela">Cargo</label>
                <input type="text" id="devolucion_cargo_escuela" name="devolucion_cargo_escuela" value="<?= $v('devolucion_cargo_escuela') ?>">
            </div>
        </div>
    </div>

    <button type="submit">Guardar constancia</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
