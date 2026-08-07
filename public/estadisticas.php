<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin', 'coordinador']);

$pdo = obtenerConexion();
$tituloPagina = 'Estadísticas';

$desde = $_GET['desde'] ?? date('Y-m-01', strtotime('-5 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// ── KPIs principales ─────────────────────────────────────────
$kpis = $pdo->prepare("
    SELECT
        COUNT(*)                                                                AS total,
        SUM(estado NOT IN ('cerrado','cancelado'))                             AS abiertos,
        SUM(estado IN ('cerrado','resuelto'))                                  AS resueltos,
        SUM(estado = 'cerrado')                                                AS cerrados,
        SUM(estado = 'cancelado')                                              AS cancelados,
        SUM(estado = 'nuevo')                                                  AS nuevos,
        SUM(estado = 'asignado')                                               AS asignados,
        SUM(estado = 'en_proceso')                                             AS en_proceso,
        SUM(prioridad = 'urgente')                                             AS urgentes,
        SUM(prioridad = 'alta')                                                AS alta,
        SUM(veces_reabierto > 0)                                               AS reabiertos,
        ROUND(AVG(CASE WHEN fecha_resolucion IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, fecha_creacion, fecha_resolucion) END),1) AS horas_prom,
        ROUND(AVG(CASE WHEN fecha_asignacion IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, fecha_creacion, fecha_asignacion) END),0) AS minutos_primera_respuesta
    FROM tickets
    WHERE DATE(fecha_creacion) BETWEEN :d AND :h
");
$kpis->execute(['d'=>$desde,'h'=>$hasta]);
$k = $kpis->fetch();

$total = max(1, (int)$k['total']);
$pctResueltos  = round(($k['resueltos']  / $total) * 100, 1);
$pctCancelados = round(($k['cancelados'] / $total) * 100, 1);
$pctAbiertos   = round(($k['abiertos']   / $total) * 100, 1);
$pctUrgentes   = round(($k['urgentes']   / $total) * 100, 1);
$pctReabiertos = round(($k['reabiertos'] / $total) * 100, 1);

$satisfaccion = $pdo->query("
    SELECT ROUND(AVG(puntaje),2) AS prom, COUNT(*) AS total,
           SUM(puntaje=5) AS cinco, SUM(puntaje=4) AS cuatro,
           SUM(puntaje=3) AS tres,  SUM(puntaje=2) AS dos, SUM(puntaje=1) AS uno
    FROM evaluaciones
")->fetch();

// ── Evolución mensual ────────────────────────────────────────
$porMes = $pdo->query("
    SELECT DATE_FORMAT(fecha_creacion,'%Y-%m') AS mes,
           DATE_FORMAT(fecha_creacion,'%b %Y') AS mes_label,
           COUNT(*) AS total,
           SUM(estado IN ('resuelto','cerrado')) AS resueltos,
           SUM(estado = 'cancelado')             AS cancelados
    FROM tickets
    WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mes, mes_label ORDER BY mes
")->fetchAll();

// ── Por estado ───────────────────────────────────────────────
$porEstado = $pdo->prepare("
    SELECT estado, COUNT(*) AS cantidad,
           ROUND(COUNT(*)*100/:total,1) AS pct
    FROM tickets
    WHERE DATE(fecha_creacion) BETWEEN :d AND :h
    GROUP BY estado ORDER BY cantidad DESC
");
$porEstado->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$porEstado = $porEstado->fetchAll();

// ── Por categoría ─────────────────────────────────────────────
$porCategoria = $pdo->prepare("
    SELECT c.nombre, COUNT(t.id) AS cantidad,
           SUM(t.estado IN ('resuelto','cerrado'))    AS resueltos,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR,t.fecha_creacion,t.fecha_resolucion) END),1) AS horas_prom,
           ROUND(COUNT(t.id)*100/:total, 1) AS pct
    FROM categorias c LEFT JOIN tickets t
         ON t.categoria_id = c.id AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    GROUP BY c.id, c.nombre ORDER BY cantidad DESC
");
$porCategoria->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$porCategoria = $porCategoria->fetchAll();

// ── Por prioridad ─────────────────────────────────────────────
$porPrioridad = $pdo->prepare("
    SELECT prioridad, COUNT(*) AS cantidad,
           SUM(estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(*)*100/:total,1) AS pct
    FROM tickets WHERE DATE(fecha_creacion) BETWEEN :d AND :h
    GROUP BY prioridad ORDER BY FIELD(prioridad,'urgente','alta','media','baja')
");
$porPrioridad->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$porPrioridad = $porPrioridad->fetchAll();

// ── Por tipo de escuela ───────────────────────────────────────
$porTipoEscuela = $pdo->prepare("
    SELECT COALESCE(te.nombre,'Sin tipo') AS tipo, COUNT(t.id) AS cantidad,
           SUM(t.estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(t.id)*100/:total,1) AS pct
    FROM tickets t
    JOIN escuelas e ON e.id = t.escuela_id
    LEFT JOIN tipos_escuela te ON te.id = e.tipo_id
    WHERE DATE(t.fecha_creacion) BETWEEN :d AND :h
    GROUP BY tipo ORDER BY cantidad DESC
");
$porTipoEscuela->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$porTipoEscuela = $porTipoEscuela->fetchAll();

// ── Desempeño técnicos ────────────────────────────────────────
$tecnicos = $pdo->prepare("
    SELECT CONCAT(u.apellido,', ',u.nombre) AS tecnico, u.anio_curso,
           COUNT(t.id)                                            AS asignados,
           SUM(t.estado IN ('resuelto','cerrado'))                AS resueltos,
           SUM(t.veces_reabierto)                                 AS reaperturas,
           ROUND(COUNT(t.id)*100/NULLIF(:total,0),1)             AS pct_carga,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR,t.fecha_asignacion,t.fecha_resolucion) END),1) AS horas_prom,
           (SELECT ROUND(AVG(ev.puntaje),1) FROM evaluaciones ev
            JOIN tickets tv ON tv.id=ev.ticket_id WHERE tv.tecnico_id=u.id) AS satisfaccion
    FROM usuarios u
    LEFT JOIN tickets t ON t.tecnico_id=u.id
         AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    WHERE u.rol='tecnico' AND u.activo=1
    GROUP BY u.id ORDER BY asignados DESC
");
$tecnicos->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$tecnicos = $tecnicos->fetchAll();

// ── Top escuelas ──────────────────────────────────────────────
$porEscuela = $pdo->prepare("
    SELECT e.nombre, COALESCE(te.nombre,'Sin tipo') AS tipo,
           COUNT(t.id) AS cantidad,
           SUM(t.estado IN ('resuelto','cerrado'))   AS resueltos,
           SUM(t.estado = 'cancelado')               AS cancelados,
           ROUND(COUNT(t.id)*100/:total,1)           AS pct,
           ROUND(SUM(t.estado IN ('resuelto','cerrado'))*100/NULLIF(COUNT(t.id),0),1) AS pct_resolucion
    FROM escuelas e
    LEFT JOIN tickets t ON t.escuela_id=e.id
         AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    LEFT JOIN tipos_escuela te ON te.id=e.tipo_id
    WHERE e.activa=1
    GROUP BY e.id ORDER BY cantidad DESC LIMIT 10
");
$porEscuela->execute(['d'=>$desde,'h'=>$hasta,'total'=>$total]);
$porEscuela = $porEscuela->fetchAll();

// ── Datos para Chart.js ───────────────────────────────────────
$mesesLabel  = array_column($porMes, 'mes_label');
$mesesTotal  = array_map('intval', array_column($porMes, 'total'));
$mesesResuel = array_map('intval', array_column($porMes, 'resueltos'));
$labEstados  = array_map(fn($e) => ucfirst(str_replace('_',' ',$e['estado'])), $porEstado);
$cntEstados  = array_map('intval', array_column($porEstado, 'cantidad'));
$labCats     = array_column($porCategoria, 'nombre');
$cntCats     = array_map('intval', array_column($porCategoria, 'cantidad'));
$labPrios    = array_map(fn($r) => ucfirst($r['prioridad']), $porPrioridad);
$cntPrios    = array_map('intval', array_column($porPrioridad, 'cantidad'));

$colores = ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16'];

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Estadísticas</h1>
    <p>Análisis completo del proyecto de soporte técnico — período <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></p>
</div>

<!-- Filtros + acciones -->
<div class="tarjeta" style="padding:1rem 1.5rem;">
    <form method="get" style="display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap;">
        <div>
            <label for="desde" style="margin-top:0;">Desde</label>
            <input type="date" id="desde" name="desde" value="<?= e($desde) ?>" style="width:auto;">
        </div>
        <div>
            <label for="hasta" style="margin-top:0;">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>" style="width:auto;">
        </div>
        <button type="submit" style="margin-top:0;">Aplicar filtro</button>
        <a href="estadisticas.php" class="boton boton-secundario" style="margin-top:0;">Restablecer</a>
        <a href="reporte_pdf.php?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           class="boton" style="margin-top:0; background:#dc2626;" target="_blank">
            ⬇ Descargar PDF
        </a>
    </form>
</div>

<!-- KPIs principales: fila 1 -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:1rem;">

    <?php
    $kpiCards = [
        ['Total de tickets',   $k['total'],      null,           '#0f172a'],
        ['Resueltos / Cerrados',"$k[resueltos]",  "{$pctResueltos}% del total",  '#16a34a'],
        ['Abiertos',           $k['abiertos'],   "{$pctAbiertos}% del total",   '#d97706'],
        ['Cancelados',         $k['cancelados'], "{$pctCancelados}% del total", '#6b7280'],
        ['Urgentes + Alta',    ($k['urgentes']+$k['alta']), "{$pctUrgentes}% urgentes",   '#dc2626'],
        ['Con reaperturas',    $k['reabiertos'], "{$pctReabiertos}% del total", '#7c3aed'],
    ];
    foreach ($kpiCards as [$label, $valor, $sub, $color]): ?>
    <div class="tarjeta" style="padding:1.1rem 1.25rem; margin-bottom:0;">
        <div style="font-size:2rem; font-weight:750; color:<?= $color ?>; letter-spacing:-0.03em; line-height:1;">
            <?= $valor ?>
        </div>
        <div style="font-size:0.78rem; color:var(--texto-2); margin-top:0.35rem; font-weight:550;"><?= $label ?></div>
        <?php if ($sub): ?>
        <div style="font-size:0.72rem; color:var(--texto-3); margin-top:2px;"><?= $sub ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- KPIs secundarios: tiempos + satisfacción -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.25rem;">
    <div class="tarjeta" style="margin-bottom:0; padding:1.1rem 1.25rem;">
        <div style="font-size:1.6rem; font-weight:700; color:#3b82f6;">
            <?= $k['horas_prom'] !== null ? $k['horas_prom'].'h' : '—' ?>
        </div>
        <div style="font-size:0.78rem; color:var(--texto-2); margin-top:0.35rem; font-weight:550;">Tiempo promedio de resolución</div>
        <?php if ($k['minutos_primera_respuesta']): ?>
        <div style="font-size:0.72rem; color:var(--texto-3); margin-top:2px;">
            Primera respuesta: <?= $k['minutos_primera_respuesta'] < 60
                ? $k['minutos_primera_respuesta'].'min'
                : round($k['minutos_primera_respuesta']/60,1).'h' ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="tarjeta" style="margin-bottom:0; padding:1.1rem 1.25rem;">
        <?php if ($satisfaccion['total'] > 0): ?>
        <div style="font-size:1.6rem; font-weight:700; color:#16a34a;">
            <?= $satisfaccion['prom'] ?>/5
        </div>
        <div style="font-size:0.78rem; color:var(--texto-2); margin-top:0.35rem; font-weight:550;">
            Satisfacción promedio
        </div>
        <div style="font-size:0.72rem; color:var(--texto-3); margin-top:2px;">
            <?= $satisfaccion['total'] ?> evaluaciones recibidas
        </div>
        <!-- Minibarra estrellas -->
        <div style="display:flex; gap:3px; margin-top:0.5rem; align-items:center;">
            <?php for($s=5;$s>=1;$s--): ?>
            <span style="font-size:0.7rem; color:var(--texto-3);"><?= $s ?>★</span>
            <div style="flex:1; background:var(--borde); border-radius:2px; height:5px;">
                <div style="width:<?= $satisfaccion['total']>0 ? round($satisfaccion[['uno','dos','tres','cuatro','cinco'][$s-1]]/$satisfaccion['total']*100) : 0 ?>%; background:#f59e0b; height:5px; border-radius:2px;"></div>
            </div>
            <?php endfor; ?>
        </div>
        <?php else: ?>
        <div style="font-size:1.6rem; font-weight:700; color:var(--texto-3);">—</div>
        <div style="font-size:0.78rem; color:var(--texto-2); margin-top:0.35rem;">Sin evaluaciones aún</div>
        <?php endif; ?>
    </div>

    <!-- Resumen de estados rápido -->
    <div class="tarjeta" style="margin-bottom:0; padding:1.1rem 1.25rem;">
        <div style="font-size:0.78rem; font-weight:650; color:var(--texto); margin-bottom:0.6rem;">Distribución de estados</div>
        <?php foreach ($porEstado as $row): ?>
        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:4px;">
            <span class="etiqueta estado-<?= $row['estado'] ?>" style="min-width:80px; text-align:center;">
                <?= ucfirst(str_replace('_',' ',$row['estado'])) ?>
            </span>
            <div style="flex:1; background:var(--borde); border-radius:999px; height:5px;">
                <div style="width:<?= $row['pct'] ?>%; height:5px; background:var(--acento); border-radius:999px;"></div>
            </div>
            <span style="font-size:0.72rem; color:var(--texto-3); min-width:36px; text-align:right;">
                <?= $row['pct'] ?>%
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Gráficos: evolución + dona estados -->
<div class="grid-2" style="margin-bottom:1.25rem;">
    <div class="tarjeta" style="margin-bottom:0;">
        <div class="tarjeta-titulo">Evolución mensual — últimos 12 meses</div>
        <canvas id="graficoMeses" height="200"></canvas>
    </div>
    <div class="tarjeta" style="margin-bottom:0;">
        <div class="tarjeta-titulo">Distribución por estado</div>
        <canvas id="graficoEstados" height="200"></canvas>
    </div>
</div>

<!-- Gráficos: categorías + prioridades -->
<div class="grid-2" style="margin-bottom:1.25rem;">
    <div class="tarjeta" style="margin-bottom:0;">
        <div class="tarjeta-titulo">Tickets por categoría</div>
        <canvas id="graficoCategorias" height="220"></canvas>
    </div>
    <div class="tarjeta" style="margin-bottom:0;">
        <div class="tarjeta-titulo">Tickets por prioridad</div>
        <canvas id="graficoPrioridades" height="220"></canvas>
    </div>
</div>

<!-- Tabla: categorías con estadísticas completas -->
<div class="tarjeta" style="margin-bottom:1.25rem;">
    <div class="tarjeta-titulo">Análisis por categoría</div>
    <div class="tabla-wrap">
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Tickets</th>
                <th>% del total</th>
                <th>Distribución</th>
                <th>Resueltos</th>
                <th>% Resolución</th>
                <th>Hs prom.</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($porCategoria as $i => $cat):
            $pctRes = $cat['cantidad'] > 0 ? round($cat['resueltos']/$cat['cantidad']*100,1) : 0;
        ?>
        <tr>
            <td class="negrita"><?= e($cat['nombre']) ?></td>
            <td class="texto-2"><?= (int)$cat['cantidad'] ?></td>
            <td>
                <span style="font-weight:650; color:<?= $colores[$i % count($colores)] ?>;">
                    <?= $cat['pct'] ?>%
                </span>
            </td>
            <td style="min-width:120px;">
                <div style="background:var(--borde); border-radius:999px; height:6px;">
                    <div style="width:<?= $cat['pct'] ?>%; height:6px; background:<?= $colores[$i % count($colores)] ?>; border-radius:999px;"></div>
                </div>
            </td>
            <td class="texto-2"><?= (int)$cat['resueltos'] ?></td>
            <td>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <div style="background:var(--borde); border-radius:999px; height:5px; width:50px;">
                        <div style="width:<?= $pctRes ?>%; height:5px; background:var(--verde); border-radius:999px;"></div>
                    </div>
                    <span class="texto-sm" style="color:<?= $pctRes >= 70 ? 'var(--verde)' : ($pctRes >= 40 ? 'var(--amarillo)' : 'var(--rojo)') ?>; font-weight:650;">
                        <?= $pctRes ?>%
                    </span>
                </div>
            </td>
            <td class="texto-2"><?= $cat['horas_prom'] !== null ? $cat['horas_prom'].'h' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Tabla: por prioridad -->
<div class="tarjeta" style="margin-bottom:1.25rem;">
    <div class="tarjeta-titulo">Análisis por prioridad</div>
    <div class="tabla-wrap">
    <table>
        <thead>
            <tr><th>Prioridad</th><th>Cantidad</th><th>% del total</th><th>Distribución</th><th>Resueltos</th><th>% Resolución</th></tr>
        </thead>
        <tbody>
        <?php
        $colPrios = ['urgente'=>'#dc2626','alta'=>'#f59e0b','media'=>'#3b82f6','baja'=>'#94a3b8'];
        foreach ($porPrioridad as $p):
            $pctRes = $p['cantidad'] > 0 ? round($p['resueltos']/$p['cantidad']*100,1) : 0;
        ?>
        <tr>
            <td><span class="prioridad-<?= $p['prioridad'] ?>" style="font-weight:650;">
                <?= ucfirst($p['prioridad']) ?>
            </span></td>
            <td class="texto-2"><?= (int)$p['cantidad'] ?></td>
            <td><span style="font-weight:650; color:<?= $colPrios[$p['prioridad']] ?? '#64748b' ?>;"><?= $p['pct'] ?>%</span></td>
            <td style="min-width:120px;">
                <div style="background:var(--borde); border-radius:999px; height:6px;">
                    <div style="width:<?= $p['pct'] ?>%; height:6px; background:<?= $colPrios[$p['prioridad']] ?? '#64748b' ?>; border-radius:999px;"></div>
                </div>
            </td>
            <td class="texto-2"><?= (int)$p['resueltos'] ?></td>
            <td>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <div style="background:var(--borde); border-radius:999px; height:5px; width:50px;">
                        <div style="width:<?= $pctRes ?>%; height:5px; background:var(--verde); border-radius:999px;"></div>
                    </div>
                    <span class="texto-sm" style="color:<?= $pctRes>=70?'var(--verde)':($pctRes>=40?'var(--amarillo)':'var(--rojo)') ?>; font-weight:650;">
                        <?= $pctRes ?>%
                    </span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Tabla: desempeño técnicos -->
<div class="tarjeta" style="margin-bottom:1.25rem;">
    <div class="tarjeta-titulo">Desempeño por técnico</div>
    <div class="tabla-wrap">
    <table>
        <thead>
            <tr>
                <th>Técnico</th><th>Año</th><th>Asignados</th>
                <th>% Carga</th><th>Resueltos</th>
                <th>% Resolución</th><th>Reaperturas</th>
                <th>Hs prom.</th><th>Satisfacción</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tecnicos as $t):
            $pctRes  = $t['asignados'] > 0 ? round($t['resueltos']/$t['asignados']*100,1) : 0;
            $pctCarga = (float)($t['pct_carga'] ?? 0);
        ?>
        <tr>
            <td class="negrita"><?= e($t['tecnico']) ?></td>
            <td class="texto-2"><?= e($t['anio_curso'] ?? '—') ?></td>
            <td class="texto-2"><?= (int)$t['asignados'] ?></td>
            <td>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <div style="background:var(--borde); border-radius:999px; height:5px; width:50px; flex-shrink:0;">
                        <div style="width:<?= $pctCarga ?>%; height:5px; background:var(--acento); border-radius:999px;"></div>
                    </div>
                    <span class="texto-sm"><?= $pctCarga ?>%</span>
                </div>
            </td>
            <td class="texto-2"><?= (int)$t['resueltos'] ?></td>
            <td>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <div style="background:var(--borde); border-radius:999px; height:5px; width:50px; flex-shrink:0;">
                        <div style="width:<?= $pctRes ?>%; height:5px; background:var(--verde); border-radius:999px;"></div>
                    </div>
                    <span class="texto-sm" style="color:<?= $pctRes>=70?'var(--verde)':($pctRes>=40?'var(--amarillo)':'var(--rojo)') ?>; font-weight:650;">
                        <?= $pctRes ?>%
                    </span>
                </div>
            </td>
            <td class="texto-2"><?= (int)$t['reaperturas'] ?></td>
            <td class="texto-2"><?= $t['horas_prom'] !== null ? $t['horas_prom'].'h' : '—' ?></td>
            <td>
                <?php if ($t['satisfaccion']): ?>
                    <span style="color:<?= $t['satisfaccion']>=4?'var(--verde)':($t['satisfaccion']>=3?'var(--amarillo)':'var(--rojo)') ?>; font-weight:650;">
                        ★ <?= $t['satisfaccion'] ?>/5
                    </span>
                <?php else: ?>
                    <span class="texto-3">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Tabla: escuelas -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Actividad por escuela</div>
    <div class="tabla-wrap">
    <table>
        <thead>
            <tr><th>Escuela</th><th>Tipo</th><th>Tickets</th><th>% del total</th><th>Distribución</th><th>Resueltos</th><th>% Resolución</th></tr>
        </thead>
        <tbody>
        <?php foreach ($porEscuela as $i => $esc): ?>
        <tr>
            <td class="negrita"><?= e($esc['nombre']) ?></td>
            <td class="texto-2"><?= e($esc['tipo']) ?></td>
            <td class="texto-2"><?= (int)$esc['cantidad'] ?></td>
            <td><span style="font-weight:650; color:<?= $colores[$i%count($colores)] ?>;"><?= $esc['pct'] ?>%</span></td>
            <td style="min-width:120px;">
                <div style="background:var(--borde); border-radius:999px; height:6px;">
                    <div style="width:<?= $esc['pct'] ?>%; height:6px; background:<?= $colores[$i%count($colores)] ?>; border-radius:999px;"></div>
                </div>
            </td>
            <td class="texto-2"><?= (int)$esc['resueltos'] ?></td>
            <td>
                <span style="font-weight:650; color:<?= $esc['pct_resolucion']>=70?'var(--verde)':($esc['pct_resolucion']>=40?'var(--amarillo)':'var(--rojo)') ?>;">
                    <?= $esc['pct_resolucion'] ?? 0 ?>%
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#475569';

const colores = <?= json_encode($colores) ?>;

// ── Evolución mensual: barras apiladas ──────────────────────
new Chart(document.getElementById('graficoMeses'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($mesesLabel) ?>,
        datasets: [
            {
                label: 'Resueltos',
                data: <?= json_encode($mesesResuel) ?>,
                backgroundColor: '#22c55e',
                borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 4, bottomRight: 4 },
                borderSkipped: false,
            },
            {
                label: 'Otros',
                data: <?= json_encode(array_map(fn($i) => $mesesTotal[$i] - $mesesResuel[$i], array_keys($mesesTotal))) ?>,
                backgroundColor: '#3b82f6',
                borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: false,
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } },
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } }
        }
    }
});

// ── Por estado: dona ────────────────────────────────────────
new Chart(document.getElementById('graficoEstados'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($labEstados) ?>,
        datasets: [{ data: <?= json_encode($cntEstados) ?>, backgroundColor: colores, borderWidth: 3, borderColor: '#fff' }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/<?= $total ?>*100)}%)`
                }
            }
        },
        cutout: '68%'
    }
});

// ── Por categoría: barras horizontales ──────────────────────
new Chart(document.getElementById('graficoCategorias'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labCats) ?>,
        datasets: [{
            data: <?= json_encode($cntCats) ?>,
            backgroundColor: colores.slice(0,<?= count($labCats) ?>),
            borderRadius: 5,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.x} tickets (${Math.round(ctx.parsed.x/<?= $total ?>*100)}%)` } }
        },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
            y: { grid: { display: false } }
        }
    }
});

// ── Por prioridad: dona ─────────────────────────────────────
new Chart(document.getElementById('graficoPrioridades'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($labPrios) ?>,
        datasets: [{ data: <?= json_encode($cntPrios) ?>,
            backgroundColor: ['#dc2626','#f59e0b','#3b82f6','#94a3b8'],
            borderWidth: 3, borderColor: '#fff' }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/<?= $total ?>*100)}%)`
                }
            }
        },
        cutout: '68%'
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
