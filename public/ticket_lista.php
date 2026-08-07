<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$pdo = obtenerConexion();
$tituloPagina = 'Tickets';
$esAdmin = $usuario['rol'] === 'admin';
$mensajeOk = null;
$error = null;

$estadosValidos = ['nuevo', 'asignado', 'en_proceso', 'resuelto', 'cerrado', 'cancelado'];

// ── Filtros: estado es para todos los roles; escuela y fecha son ────
// exclusivos del panel del administrador.
$filtroEstado      = $_GET['estado'] ?? '';
$filtroEscuelaId   = $esAdmin ? (int) ($_GET['escuela_id'] ?? 0) : 0;
$filtroFechaDesde  = $esAdmin ? trim($_GET['fecha_desde'] ?? '') : '';
$filtroFechaHasta  = $esAdmin ? trim($_GET['fecha_hasta'] ?? '') : '';

function fechaValida(string $f): bool
{
    if ($f === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

$condiciones = [];
$parametros  = [];

// Alcance según el rol: cada uno ve solo lo que le corresponde
if ($usuario['rol'] === 'solicitante') {
    $condiciones[] = 't.solicitante_id = :uid';
    $parametros['uid'] = $usuario['id'];
} elseif ($usuario['rol'] === 'tecnico') {
    $condiciones[] = 't.tecnico_id = :uid';
    $parametros['uid'] = $usuario['id'];
}
// admin y coordinador ven todo

if (in_array($filtroEstado, $estadosValidos, true)) {
    $condiciones[] = 't.estado = :estado';
    $parametros['estado'] = $filtroEstado;
}
if ($esAdmin && $filtroEscuelaId > 0) {
    $condiciones[] = 't.escuela_id = :escuela_id';
    $parametros['escuela_id'] = $filtroEscuelaId;
}
if ($esAdmin && fechaValida($filtroFechaDesde)) {
    $condiciones[] = 'DATE(t.fecha_creacion) >= :fecha_desde';
    $parametros['fecha_desde'] = $filtroFechaDesde;
}
if ($esAdmin && fechaValida($filtroFechaHasta)) {
    $condiciones[] = 'DATE(t.fecha_creacion) <= :fecha_hasta';
    $parametros['fecha_hasta'] = $filtroFechaHasta;
}

$where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

// ── Cancelación masiva (solo admin), aplicada al filtro actual ──────
if ($esAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar_masivo') {
    $condicionesCancelar = ['t.estado != "cancelado"'];
    $paramsCancelar = [];

    if (in_array($_POST['f_estado'] ?? '', $estadosValidos, true)) {
        $condicionesCancelar[] = 't.estado = :estado';
        $paramsCancelar['estado'] = $_POST['f_estado'];
    }
    $fEscuela = (int) ($_POST['f_escuela_id'] ?? 0);
    if ($fEscuela > 0) {
        $condicionesCancelar[] = 't.escuela_id = :escuela_id';
        $paramsCancelar['escuela_id'] = $fEscuela;
    }
    $fDesde = trim($_POST['f_fecha_desde'] ?? '');
    if (fechaValida($fDesde)) {
        $condicionesCancelar[] = 'DATE(t.fecha_creacion) >= :fecha_desde';
        $paramsCancelar['fecha_desde'] = $fDesde;
    }
    $fHasta = trim($_POST['f_fecha_hasta'] ?? '');
    if (fechaValida($fHasta)) {
        $condicionesCancelar[] = 'DATE(t.fecha_creacion) <= :fecha_hasta';
        $paramsCancelar['fecha_hasta'] = $fHasta;
    }

    $whereCancelar = 'WHERE ' . implode(' AND ', $condicionesCancelar);

    $stmtIds = $pdo->prepare("SELECT t.id, t.estado, t.titulo FROM tickets t $whereCancelar");
    $stmtIds->execute($paramsCancelar);
    $ticketsACancelar = $stmtIds->fetchAll();

    if (!$ticketsACancelar) {
        $error = 'No hay tickets que coincidan con el filtro para cancelar.';
    } else {
        $upd = $pdo->prepare("UPDATE tickets SET estado = 'cancelado' WHERE id = :id");
        foreach ($ticketsACancelar as $t) {
            $upd->execute(['id' => $t['id']]);
            registrarHistorial(
                $pdo, (int) $t['id'], $t['estado'], 'cancelado', $usuario['id'],
                'Cancelación masiva por administrador (filtro aplicado)'
            );
            crearNotificaciones(
                $pdo, (int) $t['id'], 'cambio_estado',
                "Ticket #{$t['id']} cancelado: \"{$t['titulo']}\"", $usuario['id']
            );
        }
        $mensajeOk = count($ticketsACancelar) . ' ticket(s) cancelado(s) correctamente.';
    }
}

// ── Escuelas para el selector del filtro (solo admin) ───────────────
$escuelasFiltro = [];
if ($esAdmin) {
    $escuelasFiltro = $pdo->query('SELECT id, nombre FROM escuelas ORDER BY nombre')->fetchAll();
}

// ── Cuenta de tickets cancelables con el filtro actual (para el botón) ──
$totalCancelable = 0;
if ($esAdmin) {
    $condicionesPreview = $condiciones ?: [];
    $condicionesPreview[] = 't.estado != "cancelado"';
    $wherePreview = 'WHERE ' . implode(' AND ', $condicionesPreview);
    $stmtPreview = $pdo->prepare("SELECT COUNT(*) FROM tickets t $wherePreview");
    $stmtPreview->execute($parametros);
    $totalCancelable = (int) $stmtPreview->fetchColumn();
}

$sql = "SELECT t.id, t.titulo, t.prioridad, t.estado, t.fecha_creacion,
               e.nombre AS escuela, c.nombre AS categoria,
               CONCAT(tec.nombre, ' ', tec.apellido) AS tecnico
        FROM tickets t
        JOIN escuelas e ON e.id = t.escuela_id
        JOIN categorias c ON c.id = t.categoria_id
        LEFT JOIN usuarios tec ON tec.id = t.tecnico_id
        $where
        ORDER BY t.fecha_creacion DESC
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
$tickets = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Tickets</h1>

<?php if ($mensajeOk): ?><div class="alerta alerta-ok"><?= e($mensajeOk) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="tarjeta" style="margin-bottom:1rem;">
    <form method="get" class="form-filtros">
        <div>
            <label for="estado">Estado</label>
            <select id="estado" name="estado">
                <option value="">Todos</option>
                <?php foreach ($estadosValidos as $est): ?>
                    <option value="<?= e($est) ?>" <?= $filtroEstado === $est ? 'selected' : '' ?>>
                        <?= e(ucfirst(str_replace('_', ' ', $est))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($esAdmin): ?>
        <div>
            <label for="escuela_id">Escuela</label>
            <select id="escuela_id" name="escuela_id">
                <option value="0">Todas</option>
                <?php foreach ($escuelasFiltro as $esc): ?>
                    <option value="<?= (int) $esc['id'] ?>" <?= $filtroEscuelaId === (int) $esc['id'] ? 'selected' : '' ?>>
                        <?= e($esc['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="fecha_desde">Desde</label>
            <input type="date" id="fecha_desde" name="fecha_desde" value="<?= e($filtroFechaDesde) ?>">
        </div>
        <div>
            <label for="fecha_hasta">Hasta</label>
            <input type="date" id="fecha_hasta" name="fecha_hasta" value="<?= e($filtroFechaHasta) ?>">
        </div>
        <?php endif; ?>

        <div class="form-filtros-acciones">
            <button type="submit" class="boton-sm">Filtrar</button>
            <?php if ($esAdmin && ($filtroEstado || $filtroEscuelaId || $filtroFechaDesde || $filtroFechaHasta)): ?>
                <a href="ticket_lista.php" class="boton boton-secundario boton-sm">Limpiar filtros</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($esAdmin): ?>
        <hr class="separador">
        <form method="post" onsubmit="return confirm('Vas a cancelar <?= $totalCancelable ?> ticket(s) que coinciden con el filtro actual. Esta acción queda registrada en el historial de cada ticket. ¿Confirmar?')">
            <input type="hidden" name="accion" value="cancelar_masivo">
            <input type="hidden" name="f_estado" value="<?= e($filtroEstado) ?>">
            <input type="hidden" name="f_escuela_id" value="<?= (int) $filtroEscuelaId ?>">
            <input type="hidden" name="f_fecha_desde" value="<?= e($filtroFechaDesde) ?>">
            <input type="hidden" name="f_fecha_hasta" value="<?= e($filtroFechaHasta) ?>">
            <button type="submit" class="boton-peligro boton-sm" style="margin-top:0;" <?= $totalCancelable === 0 ? 'disabled' : '' ?>>
                ✕ Cancelar los <?= $totalCancelable ?> ticket(s) filtrados
            </button>
        </form>
        <p class="texto-3" style="margin-top:0.5rem;">
            Cancela todos los tickets que coinciden con el filtro de arriba (excepto los ya cancelados). Si no elegís ningún filtro, se aplica a <strong>todos</strong> los tickets del sistema.
        </p>
    <?php endif; ?>
</div>

<div class="tarjeta">
    <?php if (!$tickets): ?>
        <p class="texto-secundario">No hay tickets para mostrar.</p>
    <?php else: ?>
    <div class="tabla-wrap"><table>
        <thead>
            <tr>
                <th style="width:52px">#</th>
                <th>Título</th>
                <th>Escuela</th>
                <th>Categoría</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Técnico</th>
                <th>Creado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr onclick="window.location='ticket_detalle.php?id=<?= (int) $t['id'] ?>'" style="cursor:pointer;">
                <td>#<?= (int) $t['id'] ?></td>
                <td><?= e($t['titulo']) ?></td>
                <td><?= '<span class="texto-2">'.e($t['escuela']).'</span>' ?></td>
                <td><?= e($t['categoria']) ?></td>
                <td class="prioridad-<?= e($t['prioridad']) ?>"><?= e(ucfirst($t['prioridad'])) ?></td>
                <td><span class="etiqueta estado-<?= e($t['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $t['estado']))) ?></span></td>
                <td><?= e($t['tecnico'] ?? '—') ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($t['fecha_creacion']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
