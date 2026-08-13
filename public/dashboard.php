<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$pdo     = obtenerConexion();
$tituloPagina = 'Inicio';

if ($usuario['rol'] === 'solicitante') {
    $stmt = $pdo->prepare("SELECT estado, COUNT(*) AS cantidad FROM tickets WHERE solicitante_id = :uid GROUP BY estado");
    $stmt->execute(['uid' => $usuario['id']]);
} elseif ($usuario['rol'] === 'tecnico') {
    $stmt = $pdo->prepare("SELECT estado, COUNT(*) AS cantidad FROM tickets WHERE tecnico_id = :uid GROUP BY estado");
    $stmt->execute(['uid' => $usuario['id']]);
} else {
    $stmt = $pdo->query("SELECT estado, COUNT(*) AS cantidad FROM tickets GROUP BY estado");
}
$totales = array_column($stmt->fetchAll(), 'cantidad', 'estado');

$sinAsignar = 0;
if (in_array($usuario['rol'], ['admin', 'coordinador'], true)) {
    $sinAsignar = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado = 'nuevo'")->fetchColumn();
}

// Últimos 5 tickets relevantes para el usuario
// (consulta preparada con parámetro bindeado en todos los casos, nunca
// se interpola una variable directamente dentro del texto del SQL)
switch ($usuario['rol']) {
    case 'solicitante':
        $stmtRecientes = $pdo->prepare(
            "SELECT t.id, t.titulo, t.estado, t.prioridad, t.fecha_creacion, e.nombre AS escuela
             FROM tickets t JOIN escuelas e ON e.id = t.escuela_id
             WHERE t.solicitante_id = :uid ORDER BY t.fecha_creacion DESC LIMIT 5"
        );
        $stmtRecientes->execute(['uid' => $usuario['id']]);
        break;
    case 'tecnico':
        $stmtRecientes = $pdo->prepare(
            "SELECT t.id, t.titulo, t.estado, t.prioridad, t.fecha_creacion, e.nombre AS escuela
             FROM tickets t JOIN escuelas e ON e.id = t.escuela_id
             WHERE t.tecnico_id = :uid ORDER BY t.fecha_creacion DESC LIMIT 5"
        );
        $stmtRecientes->execute(['uid' => $usuario['id']]);
        break;
    default:
        $stmtRecientes = $pdo->query(
            "SELECT t.id, t.titulo, t.estado, t.prioridad, t.fecha_creacion, e.nombre AS escuela
             FROM tickets t JOIN escuelas e ON e.id = t.escuela_id
             ORDER BY t.fecha_creacion DESC LIMIT 5"
        );
}
$recientes = $stmtRecientes->fetchAll();

require __DIR__ . '/../includes/header.php';

$etiquetasRol = [
    'admin'       => 'Administrador del sistema',
    'coordinador' => 'Coordinador del proyecto',
    'tecnico'     => 'Técnico de soporte (alumno CESDE)',
    'solicitante' => 'Solicitante',
];
?>

<div class="pagina-header">
    <h1>Bienvenido, <?= e($usuario['nombre']) ?></h1>
    <p><?= e($etiquetasRol[$usuario['rol']] ?? '') ?></p>
</div>

<?php if (in_array($usuario['rol'], ['admin', 'coordinador'], true) && $sinAsignar > 0): ?>
    <div class="alerta alerta-error">
        Hay <strong><?= $sinAsignar ?> ticket<?= $sinAsignar > 1 ? 's' : '' ?></strong> sin asignar.
        <a href="ticket_lista.php?estado=nuevo">Ver y asignar →</a>
    </div>
<?php endif; ?>

<div class="metricas">
<?php
$config = [
    'nuevo'      => ['Nuevos',      '#94a3b8'],
    'asignado'   => ['Asignados',   '#3b82f6'],
    'en_proceso' => ['En proceso',  '#6366f1'],
    'resuelto'   => ['Resueltos',   '#22c55e'],
    'cerrado'    => ['Cerrados',    '#64748b'],
    'cancelado'  => ['Cancelados',  '#ef4444'],
];
foreach ($config as $estado => [$etiqueta, $color]):
    $n = $totales[$estado] ?? 0;
?>
    <div class="metrica-card">
        <div class="numero" style="color:<?= $color ?>"><?= $n ?></div>
        <div class="etiqueta-metrica">
            <span class="etiqueta estado-<?= $estado ?>"><?= $etiqueta ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($recientes): ?>
<div class="tarjeta">
    <div class="tarjeta-titulo">Actividad reciente</div>
    <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Título</th>
                    <th>Escuela</th>
                    <th>Prioridad</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recientes as $t): ?>
                <tr onclick="window.location='ticket_detalle.php?id=<?= (int)$t['id'] ?>'">
                    <td class="texto-2">#<?= (int)$t['id'] ?></td>
                    <td class="negrita"><?= e($t['titulo']) ?></td>
                    <td class="texto-2"><?= e($t['escuela']) ?></td>
                    <td class="prioridad-<?= e($t['prioridad']) ?>"><?= ucfirst(e($t['prioridad'])) ?></td>
                    <td><span class="etiqueta estado-<?= e($t['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $t['estado']))) ?></span></td>
                    <td class="texto-2 texto-sm"><?= date('d/m/Y H:i', strtotime($t['fecha_creacion'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="acciones-fila">
        <a href="ticket_lista.php" class="boton">Ver todos los tickets</a>
        <?php if ($usuario['rol'] === 'solicitante'): ?>
            <a href="ticket_nuevo.php" class="boton boton-secundario">Reportar un problema</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
