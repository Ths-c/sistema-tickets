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
    $confirmacion = trim($_POST['confirmacion'] ?? '');

    $tieneFiltro = in_array($_POST['f_estado'] ?? '', $estadosValidos, true)
        || (int) ($_POST['f_escuela_id'] ?? 0) > 0
        || fechaValida(trim($_POST['f_fecha_desde'] ?? ''))
        || fechaValida(trim($_POST['f_fecha_hasta'] ?? ''));

    if (!$tieneFiltro) {
        $error = 'Por seguridad, la cancelación masiva requiere al menos un filtro (estado, escuela o fecha). No se puede aplicar a todos los tickets del sistema de una sola vez.';
    } elseif (strtoupper($confirmacion) !== 'CANCELAR') {
        $error = 'Tenés que escribir "CANCELAR" en el campo de confirmación para ejecutar esta acción.';
    } else {
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
        <?php $hayFiltroActivo = $filtroEstado !== '' || $filtroEscuelaId > 0 || $filtroFechaDesde !== '' || $filtroFechaHasta !== ''; ?>
        <hr class="separador">
        <?php if (!$hayFiltroActivo): ?>
            <div class="aviso-acta" style="border-color:var(--amarillo); background:var(--amarillo-claro);">
                <span>⚠ Por seguridad, la cancelación masiva solo está disponible cuando hay <strong>al menos un filtro aplicado</strong> (estado, escuela o fecha). Esto evita cancelar todos los tickets del sistema por accidente.</span>
            </div>
        <?php elseif ($totalCancelable > 0): ?>
            <div class="alerta alerta-error" style="margin-bottom:0.75rem;">
                <strong>⚠ Acción irreversible.</strong> Vas a cancelar <strong><?= $totalCancelable ?> ticket(s)</strong> que coinciden con el filtro aplicado arriba
                (<?= e(implode(' · ', array_filter([
                    $filtroEstado ? 'estado: ' . str_replace('_',' ',$filtroEstado) : null,
                    $filtroEscuelaId ? 'escuela seleccionada' : null,
                    $filtroFechaDesde ? 'desde: ' . $filtroFechaDesde : null,
                    $filtroFechaHasta ? 'hasta: ' . $filtroFechaHasta : null,
                ])) ?: 'sin detalle') ?>). Cada uno queda registrado en su historial, pero <strong>no se puede deshacer en lote</strong>.
            </div>
            <form method="post" id="formCancelarMasivo" onsubmit="return confirm('Última confirmación: se van a cancelar <?= $totalCancelable ?> ticket(s) ahora mismo. ¿Continuar?')">
                <input type="hidden" name="accion" value="cancelar_masivo">
                <input type="hidden" name="f_estado" value="<?= e($filtroEstado) ?>">
                <input type="hidden" name="f_escuela_id" value="<?= (int) $filtroEscuelaId ?>">
                <input type="hidden" name="f_fecha_desde" value="<?= e($filtroFechaDesde) ?>">
                <input type="hidden" name="f_fecha_hasta" value="<?= e($filtroFechaHasta) ?>">
                <label for="confirmacionCancelar">Para habilitar el botón, escribí <strong>CANCELAR</strong> acá:</label>
                <input type="text" id="confirmacionCancelar" name="confirmacion" autocomplete="off"
                       placeholder="CANCELAR" style="max-width:220px;"
                       oninput="document.getElementById('btnCancelarMasivo').disabled = (this.value.trim().toUpperCase() !== 'CANCELAR');">
                <div class="acciones-fila">
                    <button type="submit" id="btnCancelarMasivo" class="boton-peligro boton-sm" style="margin-top:0;" disabled>
                        ✕ Cancelar los <?= $totalCancelable ?> ticket(s) filtrados
                    </button>
                </div>
            </form>
        <?php else: ?>
            <p class="texto-2">No hay tickets cancelables con el filtro actual (ya están todos cancelados).</p>
        <?php endif; ?>
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
