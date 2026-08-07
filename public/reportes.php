<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin', 'coordinador']);

$pdo = obtenerConexion();
$tituloPagina = 'Reportes';

// Tickets por escuela
$porEscuela = $pdo->query(
    "SELECT e.nombre AS escuela, COUNT(t.id) AS cantidad
     FROM escuelas e LEFT JOIN tickets t ON t.escuela_id = e.id
     GROUP BY e.id, e.nombre ORDER BY cantidad DESC"
)->fetchAll();

// Tickets por técnico (con tiempo promedio de resolución en horas)
$porTecnico = $pdo->query(
    "SELECT CONCAT(u.nombre, ' ', u.apellido) AS tecnico,
            COUNT(t.id) AS asignados,
            SUM(t.estado = 'resuelto' OR t.estado = 'cerrado') AS resueltos,
            SUM(t.veces_reabierto) AS reaperturas,
            ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, t.fecha_asignacion, t.fecha_resolucion) END), 1) AS horas_prom_resolucion
     FROM usuarios u LEFT JOIN tickets t ON t.tecnico_id = u.id
     WHERE u.rol = 'tecnico'
     GROUP BY u.id, tecnico ORDER BY asignados DESC"
)->fetchAll();

// Tickets por categoría
$porCategoria = $pdo->query(
    "SELECT c.nombre AS categoria, COUNT(t.id) AS cantidad
     FROM categorias c LEFT JOIN tickets t ON t.categoria_id = c.id
     GROUP BY c.id, c.nombre ORDER BY cantidad DESC"
)->fetchAll();

// Satisfacción promedio
$satisfaccion = $pdo->query("SELECT ROUND(AVG(puntaje),2) AS prom, COUNT(*) AS total FROM evaluaciones")->fetch();

require __DIR__ . '/../includes/header.php';
?>

<h1>Reportes</h1>

<div class="tarjeta">
    <h2>Tickets por escuela</h2>
    <table>
        <thead><tr><th>Escuela</th><th>Tickets</th></tr></thead>
        <tbody>
        <?php foreach ($porEscuela as $r): ?>
            <tr><td><?= e($r['escuela']) ?></td><td><?= (int) $r['cantidad'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="tarjeta">
    <h2>Desempeño por técnico</h2>
    <table>
        <thead><tr><th>Técnico</th><th>Asignados</th><th>Resueltos</th><th>Reaperturas</th><th>Horas prom. resolución</th></tr></thead>
        <tbody>
        <?php foreach ($porTecnico as $r): ?>
            <tr>
                <td><?= e($r['tecnico']) ?></td>
                <td><?= (int) $r['asignados'] ?></td>
                <td><?= (int) $r['resueltos'] ?></td>
                <td><?= (int) $r['reaperturas'] ?></td>
                <td><?= $r['horas_prom_resolucion'] !== null ? e($r['horas_prom_resolucion']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="tarjeta">
    <h2>Tickets por categoría</h2>
    <table>
        <thead><tr><th>Categoría</th><th>Tickets</th></tr></thead>
        <tbody>
        <?php foreach ($porCategoria as $r): ?>
            <tr><td><?= e($r['categoria']) ?></td><td><?= (int) $r['cantidad'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="tarjeta">
    <h2>Satisfacción</h2>
    <?php if ($satisfaccion['total'] > 0): ?>
        <p>Promedio: <strong><?= e($satisfaccion['prom']) ?> / 5</strong> sobre <?= (int) $satisfaccion['total'] ?> evaluaciones.</p>
    <?php else: ?>
        <p class="texto-secundario">Todavía no hay evaluaciones registradas.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
