<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin', 'coordinador']);
require_once __DIR__ . '/../lib/fpdf.php';

$pdo  = obtenerConexion();
$user = usuarioActual();

$desde = $_GET['desde'] ?? date('Y-m-01', strtotime('-5 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$total_real = 0;

// ── Datos ────────────────────────────────────────────────────
$kpis = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(estado NOT IN ('cerrado','cancelado'))      AS abiertos,
           SUM(estado IN ('cerrado','resuelto'))           AS resueltos,
           SUM(estado='cancelado')                         AS cancelados,
           SUM(estado='nuevo')                             AS nuevos,
           SUM(estado='en_proceso')                        AS en_proceso,
           SUM(prioridad='urgente')                        AS urgentes,
           SUM(prioridad='alta')                           AS alta,
           SUM(veces_reabierto > 0)                        AS reabiertos,
           ROUND(AVG(CASE WHEN fecha_resolucion IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR,fecha_creacion,fecha_resolucion) END),1) AS horas_prom,
           ROUND(AVG(CASE WHEN fecha_asignacion IS NOT NULL
               THEN TIMESTAMPDIFF(MINUTE,fecha_creacion,fecha_asignacion) END),0) AS min_primera_resp
    FROM tickets WHERE DATE(fecha_creacion) BETWEEN :d AND :h
");
$kpis->execute(['d'=>$desde,'h'=>$hasta]);
$k = $kpis->fetch();
$total_real = max(1, (int)$k['total']);
$pct = fn($n) => $total_real > 0 ? round(($n / $total_real) * 100, 1) : 0;

$satisf = $pdo->query("
    SELECT ROUND(AVG(puntaje),2) AS prom, COUNT(*) AS total,
           SUM(puntaje=5) AS p5, SUM(puntaje=4) AS p4,
           SUM(puntaje=3) AS p3, SUM(puntaje=2) AS p2, SUM(puntaje=1) AS p1
    FROM evaluaciones
")->fetch();

$porEstado = $pdo->prepare("
    SELECT estado, COUNT(*) AS n, ROUND(COUNT(*)*100/:t,1) AS pct
    FROM tickets WHERE DATE(fecha_creacion) BETWEEN :d AND :h
    GROUP BY estado ORDER BY n DESC
");
$porEstado->execute(['d'=>$desde,'h'=>$hasta,'t'=>$total_real]);
$porEstado = $porEstado->fetchAll();

$porCategoria = $pdo->prepare("
    SELECT c.nombre, COUNT(t.id) AS n,
           SUM(t.estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(t.id)*100/:t,1) AS pct,
           ROUND(SUM(t.estado IN ('resuelto','cerrado'))*100/NULLIF(COUNT(t.id),0),1) AS pct_res,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR,t.fecha_creacion,t.fecha_resolucion) END),1) AS horas_prom
    FROM categorias c
    LEFT JOIN tickets t ON t.categoria_id=c.id AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    GROUP BY c.id,c.nombre ORDER BY n DESC
");
$porCategoria->execute(['d'=>$desde,'h'=>$hasta,'t'=>$total_real]);
$porCategoria = $porCategoria->fetchAll();

$porPrioridad = $pdo->prepare("
    SELECT prioridad, COUNT(*) AS n,
           SUM(estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(*)*100/:t,1) AS pct,
           ROUND(SUM(estado IN ('resuelto','cerrado'))*100/NULLIF(COUNT(*),0),1) AS pct_res
    FROM tickets WHERE DATE(fecha_creacion) BETWEEN :d AND :h
    GROUP BY prioridad ORDER BY FIELD(prioridad,'urgente','alta','media','baja')
");
$porPrioridad->execute(['d'=>$desde,'h'=>$hasta,'t'=>$total_real]);
$porPrioridad = $porPrioridad->fetchAll();

$tecnicosData = $pdo->prepare("
    SELECT CONCAT(u.apellido,', ',u.nombre) AS tecnico, u.anio_curso,
           COUNT(t.id) AS asignados,
           SUM(t.estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(t.id)*100/NULLIF(:t,0),1) AS pct_carga,
           ROUND(SUM(t.estado IN ('resuelto','cerrado'))*100/NULLIF(COUNT(t.id),0),1) AS pct_res,
           SUM(t.veces_reabierto) AS reaperturas,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL
               THEN TIMESTAMPDIFF(HOUR,t.fecha_asignacion,t.fecha_resolucion) END),1) AS horas_prom,
           (SELECT ROUND(AVG(ev.puntaje),1) FROM evaluaciones ev
            JOIN tickets tv ON tv.id=ev.ticket_id WHERE tv.tecnico_id=u.id) AS satisf
    FROM usuarios u
    LEFT JOIN tickets t ON t.tecnico_id=u.id AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    WHERE u.rol='tecnico' AND u.activo=1
    GROUP BY u.id ORDER BY asignados DESC
");
$tecnicosData->execute(['d'=>$desde,'h'=>$hasta,'t'=>$total_real]);
$tecnicosData = $tecnicosData->fetchAll();

$escuelasData = $pdo->prepare("
    SELECT e.nombre, COALESCE(te.nombre,'Sin tipo') AS tipo,
           COUNT(t.id) AS n,
           SUM(t.estado IN ('resuelto','cerrado')) AS resueltos,
           ROUND(COUNT(t.id)*100/NULLIF(:t,0),1) AS pct,
           ROUND(SUM(t.estado IN ('resuelto','cerrado'))*100/NULLIF(COUNT(t.id),0),1) AS pct_res
    FROM escuelas e
    LEFT JOIN tickets t ON t.escuela_id=e.id AND DATE(t.fecha_creacion) BETWEEN :d AND :h
    LEFT JOIN tipos_escuela te ON te.id=e.tipo_id
    WHERE e.activa=1 GROUP BY e.id ORDER BY n DESC LIMIT 10
");
$escuelasData->execute(['d'=>$desde,'h'=>$hasta,'t'=>$total_real]);
$escuelasData = $escuelasData->fetchAll();

$porMes = $pdo->query("
    SELECT DATE_FORMAT(fecha_creacion,'%b %Y') AS mes,
           COUNT(*) AS n,
           SUM(estado IN ('resuelto','cerrado')) AS resueltos
    FROM tickets WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(fecha_creacion,'%Y-%m'), mes ORDER BY MIN(fecha_creacion)
")->fetchAll();

// ════════════════════════════════════════════════════════════
// CLASE PDF
// ════════════════════════════════════════════════════════════
class PDF extends FPDF
{
    // Colores de la paleta
    const AZUL_OSCURO = [15,  23,  42];
    const AZUL        = [37,  99,  235];
    const AZUL_CLARO  = [239, 246, 255];
    const VERDE       = [22,  163, 74];
    const VERDE_CLARO = [240, 253, 244];
    const ROJO        = [220, 38,  38];
    const ROJO_CLARO  = [254, 242, 242];
    const AMARILLO    = [217, 119, 6];
    const GRIS        = [71,  85,  105];
    const GRIS_CLARO  = [248, 250, 252];
    const BORDE       = [226, 232, 240];
    const BLANCO      = [255, 255, 255];

    public string $subtitulo = '';
    public string $periodo   = '';

    function Header()
    {
        // Banda superior oscura
        $this->SetFillColor(...self::AZUL_OSCURO);
        $this->Rect(0, 0, 210, 26, 'F');

        // Línea acento azul
        $this->SetFillColor(...self::AZUL);
        $this->Rect(0, 26, 210, 2, 'F');

        // Logo / institución
        $this->SetTextColor(...self::BLANCO);
        $this->SetFont('Helvetica','B', 14);
        $this->SetXY(10, 5);
        $this->Cell(130, 7, 'Soporte Tecnico Distrital', 0, 0, 'L');

        $this->SetFont('Helvetica','', 8);
        $this->SetXY(10, 13);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(130, 5, 'Escuela Tecnica de Monte Hermoso  |  '.$this->subtitulo, 0, 0, 'L');

        // Período (derecha)
        $this->SetFont('Helvetica','', 8);
        $this->SetTextColor(203, 213, 225);
        $this->SetXY(140, 8);
        $this->Cell(60, 5, $this->periodo, 0, 0, 'R');

        $this->SetTextColor(...self::AZUL_OSCURO);
        $this->SetY(32);
    }

    function Footer()
    {
        $this->SetY(-11);
        $this->SetFillColor(...self::GRIS_CLARO);
        $this->Rect(0, $this->GetY()-1, 210, 12, 'F');
        $this->SetFont('Helvetica','I', 7);
        $this->SetTextColor(...self::GRIS);
        $this->Cell(95, 5, 'Sistema de gestion de soporte tecnico distrital — ETMH', 0, 0, 'L');
        $this->Cell(95, 5, 'Pagina '.$this->PageNo().'  |  Generado el '.date('d/m/Y H:i'), 0, 0, 'R');
    }

    /** Título de sección con banda de color */
    function Seccion(string $titulo, array $color = null): void
    {
        $color = $color ?? self::AZUL;
        $this->Ln(3);
        $this->SetFillColor(...$color);
        $this->SetTextColor(...self::BLANCO);
        $this->SetFont('Helvetica','B', 9);
        $this->SetX(10);
        $this->Cell(190, 7, '  '.$titulo, 0, 1, 'L', true);
        $this->SetTextColor(...self::AZUL_OSCURO);
        $this->Ln(2);
    }

    /** Tarjeta KPI: número grande + etiqueta + sub */
    function KpiCard(float $x, float $y, float $w, float $h,
                     string $valor, string $label, string $sub = '',
                     array $colorValor = null, array $bgColor = null): void
    {
        $colorValor = $colorValor ?? self::AZUL;
        $bgColor    = $bgColor    ?? self::BLANCO;

        // Fondo
        $this->SetFillColor(...$bgColor);
        $this->SetDrawColor(...self::BORDE);
        $this->SetLineWidth(0.2);
        $this->RoundedRect($x, $y, $w, $h, 3, 'FD');

        // Línea de acento arriba
        $this->SetFillColor(...$colorValor);
        $this->Rect($x, $y, $w, 1.5, 'F');

        // Valor
        $this->SetFont('Helvetica','B', 18);
        $this->SetTextColor(...$colorValor);
        $this->SetXY($x, $y+4);
        $this->Cell($w, 10, $valor, 0, 1, 'C');

        // Label
        $this->SetFont('Helvetica','B', 7);
        $this->SetTextColor(...self::AZUL_OSCURO);
        $this->SetX($x);
        $this->Cell($w, 4, $label, 0, 1, 'C');

        // Sub
        if ($sub) {
            $this->SetFont('Helvetica','', 6.5);
            $this->SetTextColor(...self::GRIS);
            $this->SetX($x);
            $this->Cell($w, 4, $sub, 0, 1, 'C');
        }
    }

    /** Barra de porcentaje con color según valor */
    function BarraPct(float $x, float $y, float $w, float $pct,
                      bool $colorSemantico = true): void
    {
        $h = 3.5;
        $this->SetFillColor(...self::BORDE);
        $this->RoundedRect($x, $y, $w, $h, 1, 'F');

        if ($pct > 0) {
            if ($colorSemantico) {
                if ($pct >= 70)      $this->SetFillColor(...self::VERDE);
                elseif ($pct >= 40)  $this->SetFillColor(...self::AMARILLO);
                else                 $this->SetFillColor(...self::ROJO);
            } else {
                $this->SetFillColor(...self::AZUL);
            }
            $fill = min($pct / 100, 1) * $w;
            $this->RoundedRect($x, $y, $fill, $h, 1, 'F');
        }
    }

    /** Rectángulo con esquinas redondeadas (helper de FPDF) */
    function RoundedRect(float $x, float $y, float $w, float $h,
                         float $r, string $style = ''): void
    {
        $k   = $this->k;
        $hp  = $this->h;
        if ($style === 'F') $op = 'f';
        elseif ($style === 'FD' || $style === 'DF') $op = 'B';
        else $op = 'S';
        $MyArc = 4/3*(sqrt(2)-1);
        $this->_out(sprintf('%.2F %.2F m', ($x+$r)*$k, ($hp-$y)*$k));
        $xc = $x+$w-$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-$y)*$k));
        $this->_Arc($xc+$r*$MyArc,$yc-$r,$xc+$r,$yc-$r*$MyArc,$xc+$r,$yc);
        $xc = $x+$w-$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k, ($hp-$yc)*$k));
        $this->_Arc($xc+$r,$yc+$r*$MyArc,$xc+$r*$MyArc,$yc+$r,$xc,$yc+$r);
        $xc = $x+$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k, ($hp-($y+$h))*$k));
        $this->_Arc($xc-$r*$MyArc,$yc+$r,$xc-$r,$yc+$r*$MyArc,$xc-$r,$yc);
        $xc = $x+$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $x*$k, ($hp-$yc)*$k));
        $this->_Arc($xc-$r,$yc-$r*$MyArc,$xc-$r*$MyArc,$yc-$r,$xc,$yc-$r);
        $this->_out($op);
    }

    function _Arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k,
            $x3*$this->k, ($h-$y3)*$this->k));
    }

    /** Fila de tabla (alterna color) */
    function FilaTbl(array $vals, array $ws, bool $alt,
                     array $aligns = [], string $colorTexto = ''): void
    {
        if ($alt) $this->SetFillColor(...self::GRIS_CLARO);
        else      $this->SetFillColor(...self::BLANCO);
        $this->SetFont('Helvetica','', 8);
        $color = $colorTexto ? explode(',', $colorTexto) : self::AZUL_OSCURO;
        $this->SetTextColor(...array_map('intval', $color));
        $this->SetX(10);
        foreach ($vals as $i => $v) {
            $this->Cell($ws[$i], 5.5, (string)$v, 0, 0, $aligns[$i] ?? 'L', true);
        }
        $this->Ln();
        $this->SetTextColor(...self::AZUL_OSCURO);
    }

    /** Cabecera de tabla */
    function CabezalTbl(array $cols, array $ws): void
    {
        $this->SetFillColor(...self::AZUL_OSCURO);
        $this->SetTextColor(...self::BLANCO);
        $this->SetFont('Helvetica','B', 7.5);
        $this->SetX(10);
        foreach ($cols as $i => $c) {
            $this->Cell($ws[$i], 6, $c, 0, 0, 'L', true);
        }
        $this->Ln();
        $this->SetTextColor(...self::AZUL_OSCURO);
    }

    /** Línea separadora */
    function Separador(): void
    {
        $this->SetDrawColor(...self::BORDE);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY()+1, 200, $this->GetY()+1);
        $this->Ln(3);
    }
}

// ════════════════════════════════════════════════════════════
// CONSTRUIR EL PDF
// ════════════════════════════════════════════════════════════
$pdf = new PDF('P','mm','A4');
$pdf->subtitulo = 'Reporte estadistico de tickets';
$pdf->periodo   = date('d/m/Y', strtotime($desde)).' al '.date('d/m/Y', strtotime($hasta));
$pdf->SetMargins(10, 32, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── PORTADA / Resumen ejecutivo ──────────────────────────────
$pdf->SetFont('Helvetica','B', 13);
$pdf->SetTextColor(...PDF::AZUL_OSCURO);
$pdf->Cell(0, 8, 'Reporte Estadistico — Soporte Tecnico Distrital', 0, 1, 'C');
$pdf->SetFont('Helvetica','', 9);
$pdf->SetTextColor(...PDF::GRIS);
$pdf->Cell(0, 5, 'Periodo analizado: '.$pdf->periodo.'  |  Generado por: '.$user['nombre'].' '.$user['apellido'], 0, 1, 'C');
$pdf->Ln(4);

// ── TARJETAS KPI ─────────────────────────────────────────────
$cardW = 38; $cardH = 25; $gap = 2;
$startX = 10; $y0 = $pdf->GetY();

$cards = [
    [(string)$k['total'],     'Total de tickets',    '',                              PDF::AZUL,     PDF::AZUL_CLARO],
    [(string)$k['resueltos'], 'Resueltos / Cerrados', $pct($k['resueltos']).'% del total', PDF::VERDE,    PDF::VERDE_CLARO],
    [(string)$k['abiertos'],  'Abiertos',            $pct($k['abiertos']).'% del total',  [217,119,6],   [255,251,235]],
    [(string)$k['cancelados'],'Cancelados',          $pct($k['cancelados']).'% del total',PDF::GRIS,     PDF::GRIS_CLARO],
    [(string)$k['urgentes'],  'Urgentes',            $pct($k['urgentes']).'% del total',  PDF::ROJO,     PDF::ROJO_CLARO],
];
foreach ($cards as $i => [$val, $lab, $sub, $col, $bg]) {
    $pdf->KpiCard($startX + $i*($cardW+$gap), $y0, $cardW, $cardH, $val, $lab, $sub, $col, $bg);
}
$pdf->SetY($y0 + $cardH + 4);

// Segunda fila KPI: tiempos + satisfacción
$cards2 = [
    [$k['horas_prom'] ? $k['horas_prom'].'h' : '—', 'Hs prom. resolucion', '', PDF::AZUL, PDF::AZUL_CLARO],
    [$k['min_primera_resp'] ? (($k['min_primera_resp']<60) ? $k['min_primera_resp'].'min' : round($k['min_primera_resp']/60,1).'h') : '—', 'Prim. respuesta', '', [99,102,241], [238,242,255]],
    [$k['reabiertos'], 'Con reaperturas', $pct($k['reabiertos']).'% del total', [124,58,237],[245,243,255]],
    [$satisf['total']>0 ? $satisf['prom'].'/5' : '—', 'Satisfaccion prom.', $satisf['total'].' evaluaciones', PDF::VERDE, PDF::VERDE_CLARO],
    [(string)($k['urgentes']+$k['alta']), 'Alta/Urgente', $pct($k['urgentes']+$k['alta']).'% del total', PDF::ROJO, PDF::ROJO_CLARO],
];
$y0b = $pdf->GetY();
foreach ($cards2 as $i => [$val, $lab, $sub, $col, $bg]) {
    $pdf->KpiCard($startX + $i*($cardW+$gap), $y0b, $cardW, $cardH, $val, $lab, $sub, $col, $bg);
}
$pdf->SetY($y0b + $cardH + 6);

// ── DISTRIBUCIÓN POR ESTADO ──────────────────────────────────
$pdf->Seccion('Distribucion por estado');
$pdf->CabezalTbl(['Estado','Cantidad','% del total','Distribucion (% visual)','—'], [38,25,28,90,9]);
$colsEstado = [
    'nuevo'      => PDF::GRIS,
    'asignado'   => PDF::AZUL,
    'en_proceso' => [99,102,241],
    'resuelto'   => PDF::VERDE,
    'cerrado'    => PDF::GRIS,
    'cancelado'  => PDF::ROJO,
];
foreach ($porEstado as $i => $row) {
    if ($pdf->GetY() > 265) { $pdf->AddPage(); }
    $alt = $i % 2 === 1;
    if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
    $pdf->SetX(10);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->Cell(38, 7, '  '.ucfirst(str_replace('_',' ',$row['estado'])), 0, 0, 'L', true);
    $pdf->Cell(25, 7, (string)$row['n'], 0, 0, 'R', true);
    $pdf->Cell(28, 7, $row['pct'].'%', 0, 0, 'R', true);
    // Barra visual
    $xBarra = $pdf->GetX(); $yBarra = $pdf->GetY() + 1.8;
    $pdf->Cell(90, 7, '', 0, 0, 'L', true);
    $colBarra = $colsEstado[$row['estado']] ?? PDF::AZUL;
    $pdf->SetFillColor(...PDF::BORDE);
    $pdf->RoundedRect($xBarra+2, $yBarra, 80, 3.5, 1.5, 'F');
    $pdf->SetFillColor(...$colBarra);
    $fill = max(1, ($row['pct']/100)*80);
    $pdf->RoundedRect($xBarra+2, $yBarra, $fill, 3.5, 1.5, 'F');
    $pdf->SetFillColor($alt ? 248 : 255, $alt ? 250 : 255, $alt ? 252 : 255);
    $pdf->Cell(9, 7, '', 0, 1, 'L', true);
}
$pdf->Ln(3);

// ── POR CATEGORÍA ────────────────────────────────────────────
$pdf->Seccion('Analisis por categoria');
$ws = [52, 18, 22, 40, 18, 22, 18];
$pdf->CabezalTbl(['Categoria','Tickets','% Total','Distribucion','Resueltos','% Res.','Hs prom.'], $ws);
foreach ($porCategoria as $i => $cat) {
    if ($pdf->GetY() > 265) { $pdf->AddPage(); }
    $alt = $i % 2 === 1;
    if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
    $pdf->SetX(10);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->Cell($ws[0], 7, '  '.$cat['nombre'], 0, 0, 'L', true);
    $pdf->Cell($ws[1], 7, (string)(int)$cat['n'], 0, 0, 'R', true);
    $pdf->Cell($ws[2], 7, $cat['pct'].'%', 0, 0, 'R', true);
    $xB = $pdf->GetX(); $yB = $pdf->GetY()+1.8;
    $pdf->Cell($ws[3], 7, '', 0, 0, 'L', true);
    $pdf->SetFillColor(...PDF::BORDE);
    $pdf->RoundedRect($xB+1,$yB,35,3.5,1,'F');
    $fill2 = ($cat['pct']/100)*35;
    $pdf->SetFillColor(...PDF::AZUL);
    if ($fill2 > 0) $pdf->RoundedRect($xB+1,$yB,$fill2,3.5,1,'F');
    $pdf->SetFillColor($alt ? 248 : 255, $alt ? 250 : 255, $alt ? 252 : 255);
    $pdf->Cell($ws[4], 7, (string)(int)$cat['resueltos'], 0, 0, 'R', true);
    $pctR = (float)($cat['pct_res'] ?? 0);
    if ($pctR >= 70)     $pdf->SetTextColor(...PDF::VERDE);
    elseif ($pctR >= 40) $pdf->SetTextColor(...PDF::AMARILLO);
    else                 $pdf->SetTextColor(...PDF::ROJO);
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->Cell($ws[5], 7, $pctR.'%', 0, 0, 'R', true);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($ws[6], 7, $cat['horas_prom'] ? $cat['horas_prom'].'h' : '—', 0, 1, 'R', true);
}
$pdf->Ln(3);

// ── POR PRIORIDAD ────────────────────────────────────────────
$pdf->Seccion('Distribucion por prioridad');
$wsp = [40, 20, 22, 50, 22, 22, 14];
$pdf->CabezalTbl(['Prioridad','Tickets','% Total','Distribucion','Resueltos','% Res.',''], $wsp);
$colorPrio = ['urgente'=>PDF::ROJO,'alta'=>PDF::AMARILLO,'media'=>PDF::AZUL,'baja'=>PDF::GRIS];
foreach ($porPrioridad as $i => $row) {
    $alt = $i % 2 === 1;
    if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
    $col = $colorPrio[$row['prioridad']] ?? PDF::GRIS;
    $pdf->SetX(10);
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(...$col);
    $pdf->Cell($wsp[0], 7, '  '.ucfirst($row['prioridad']), 0, 0, 'L', true);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($wsp[1], 7, (string)(int)$row['n'], 0, 0, 'R', true);
    $pdf->Cell($wsp[2], 7, $row['pct'].'%', 0, 0, 'R', true);
    $xB = $pdf->GetX(); $yB = $pdf->GetY()+1.8;
    $pdf->Cell($wsp[3], 7, '', 0, 0, 'L', true);
    $pdf->SetFillColor(...PDF::BORDE);
    $pdf->RoundedRect($xB+1,$yB,44,3.5,1,'F');
    $fill3 = ($row['pct']/100)*44;
    $pdf->SetFillColor(...$col);
    if ($fill3 > 0) $pdf->RoundedRect($xB+1,$yB,$fill3,3.5,1,'F');
    $pdf->SetFillColor($alt ? 248 : 255, $alt ? 250 : 255, $alt ? 252 : 255);
    $pdf->Cell($wsp[4], 7, (string)(int)$row['resueltos'], 0, 0, 'R', true);
    $pctR2 = (float)($row['pct_res'] ?? 0);
    if ($pctR2 >= 70) $pdf->SetTextColor(...PDF::VERDE);
    elseif ($pctR2 >= 40) $pdf->SetTextColor(...PDF::AMARILLO);
    else $pdf->SetTextColor(...PDF::ROJO);
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->Cell($wsp[5], 7, $pctR2.'%', 0, 0, 'R', true);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->Cell($wsp[6], 7, '', 0, 1, 'L', true);
}
$pdf->Ln(3);

// ── DESEMPEÑO TÉCNICOS ────────────────────────────────────────
$pdf->AddPage();
$pdf->Seccion('Desempeno por tecnico');
$wst = [50, 15, 20, 22, 20, 25, 16, 12];
$pdf->CabezalTbl(['Tecnico','Anio','Asignados','% Carga','Resueltos','% Resolucion','Hs prom.','Satisf.'], $wst);
foreach ($tecnicosData as $i => $t) {
    if ($pdf->GetY() > 265) { $pdf->AddPage(); }
    $alt  = $i % 2 === 1;
    $pRes = (float)($t['pct_res'] ?? 0);
    if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
    $pdf->SetX(10);
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->Cell($wst[0], 10, '  '.$t['tecnico'], 0, 0, 'L', true);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($wst[1], 10, $t['anio_curso'] ?? '—', 0, 0, 'C', true);
    $pdf->Cell($wst[2], 10, (string)(int)$t['asignados'], 0, 0, 'R', true);
    // % Carga con barra
    $xB = $pdf->GetX(); $yB = $pdf->GetY()+3.5;
    $pdf->Cell($wst[3], 10, '', 0, 0, 'L', true);
    $pdf->BarraPct($xB+1, $yB, 18, (float)($t['pct_carga']??0), false);
    $pdf->SetFont('Helvetica','', 7);
    $pdf->SetXY($xB, $pdf->GetY());
    $pdf->Cell($wst[3], 10, ($t['pct_carga']??0).'%', 0, 0, 'C', true);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($wst[4], 10, (string)(int)$t['resueltos'], 0, 0, 'R', true);
    // % Resolución con barra
    $xB2 = $pdf->GetX(); $yB2 = $pdf->GetY()+3.5;
    $pdf->Cell($wst[5], 10, '', 0, 0, 'L', true);
    $pdf->BarraPct($xB2+1, $yB2, 20, $pRes, true);
    if ($pRes >= 70) $pdf->SetTextColor(...PDF::VERDE);
    elseif ($pRes >= 40) $pdf->SetTextColor(...PDF::AMARILLO);
    else $pdf->SetTextColor(...PDF::ROJO);
    $pdf->SetFont('Helvetica','B', 7.5);
    $pdf->SetXY($xB2, $pdf->GetY());
    $pdf->Cell($wst[5], 10, $pRes.'%', 0, 0, 'C', true);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($wst[6], 10, $t['horas_prom'] ? $t['horas_prom'].'h' : '—', 0, 0, 'R', true);
    // Satisfacción
    if ($t['satisf']) {
        if ($t['satisf'] >= 4) $pdf->SetTextColor(...PDF::VERDE);
        elseif ($t['satisf'] >= 3) $pdf->SetTextColor(...PDF::AMARILLO);
        else $pdf->SetTextColor(...PDF::ROJO);
        $pdf->SetFont('Helvetica','B', 8);
        $pdf->Cell($wst[7], 10, $t['satisf'].'/5', 0, 1, 'C', true);
    } else {
        $pdf->SetTextColor(...PDF::GRIS);
        $pdf->Cell($wst[7], 10, '—', 0, 1, 'C', true);
    }
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
}
$pdf->Ln(3);

// ── ESCUELAS MÁS ACTIVAS ──────────────────────────────────────
$pdf->Seccion('Actividad por escuela (top 10)');
$wse = [60, 28, 18, 22, 40, 22];
$pdf->CabezalTbl(['Escuela','Tipo','Tickets','% Total','Distribucion / % Resolucion','% Res.'], $wse);
foreach ($escuelasData as $i => $esc) {
    if ($pdf->GetY() > 265) { $pdf->AddPage(); }
    $alt  = $i % 2 === 1;
    $pRes = (float)($esc['pct_res'] ?? 0);
    if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
    $pdf->SetX(10);
    $nombre = mb_strlen($esc['nombre']) > 35 ? mb_substr($esc['nombre'],0,32).'...' : $esc['nombre'];
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $pdf->Cell($wse[0], 9, '  '.$nombre, 0, 0, 'L', true);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell($wse[1], 9, $esc['tipo'], 0, 0, 'L', true);
    $pdf->Cell($wse[2], 9, (string)(int)$esc['n'], 0, 0, 'R', true);
    $pdf->Cell($wse[3], 9, $esc['pct'].'%', 0, 0, 'R', true);
    $xB = $pdf->GetX(); $yB = $pdf->GetY()+2;
    $pdf->Cell($wse[4], 9, '', 0, 0, 'L', true);
    // Barra pct del total
    $pdf->SetFillColor(...PDF::BORDE);
    $pdf->RoundedRect($xB+1,$yB,18,2.8,1,'F');
    $pdf->SetFillColor(...PDF::AZUL);
    $f4 = ($esc['pct']/100)*18;
    if ($f4 > 0) $pdf->RoundedRect($xB+1,$yB,$f4,2.8,1,'F');
    // Barra pct resolución
    $yB2 = $yB+4;
    $pdf->BarraPct($xB+1,$yB2,18,$pRes,true);
    $pdf->SetFillColor($alt ? 248 : 255, $alt ? 250 : 255, $alt ? 252 : 255);
    if ($pRes >= 70) $pdf->SetTextColor(...PDF::VERDE);
    elseif ($pRes >= 40) $pdf->SetTextColor(...PDF::AMARILLO);
    else $pdf->SetTextColor(...PDF::ROJO);
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->Cell($wse[5], 9, $pRes.'%', 0, 1, 'R', true);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
}
$pdf->Ln(3);

// ── EVOLUCIÓN MENSUAL ─────────────────────────────────────────
if ($porMes) {
    $pdf->Seccion('Evolucion mensual (ultimos 6 meses)');
    $wm = [35, 22, 22, 22, 90];
    $pdf->CabezalTbl(['Mes','Tickets','Resueltos','% Res.','Tendencia visual'], $wm);
    $maxMes = max(array_column($porMes, 'n')) ?: 1;
    foreach ($porMes as $i => $mes) {
        $alt  = $i % 2 === 1;
        $pRes = $mes['n'] > 0 ? round($mes['resueltos']/$mes['n']*100,1) : 0;
        if ($alt) $pdf->SetFillColor(...PDF::GRIS_CLARO); else $pdf->SetFillColor(...PDF::BLANCO);
        $pdf->SetX(10);
        $pdf->SetFont('Helvetica','B', 8);
        $pdf->SetTextColor(...PDF::AZUL_OSCURO);
        $pdf->Cell($wm[0], 8, '  '.$mes['mes'], 0, 0, 'L', true);
        $pdf->SetFont('Helvetica','', 8);
        $pdf->Cell($wm[1], 8, (string)(int)$mes['n'], 0, 0, 'R', true);
        $pdf->Cell($wm[2], 8, (string)(int)$mes['resueltos'], 0, 0, 'R', true);
        if ($pRes >= 70) $pdf->SetTextColor(...PDF::VERDE);
        elseif ($pRes >= 40) $pdf->SetTextColor(...PDF::AMARILLO);
        else $pdf->SetTextColor(...PDF::ROJO);
        $pdf->SetFont('Helvetica','B', 8);
        $pdf->Cell($wm[3], 8, $pRes.'%', 0, 0, 'R', true);
        $pdf->SetTextColor(...PDF::AZUL_OSCURO);
        $xB = $pdf->GetX(); $yB = $pdf->GetY()+2;
        $pdf->Cell($wm[4], 8, '', 0, 1, 'L', true);
        // Barra total
        $anchoTotal = ($mes['n']/$maxMes)*82;
        $pdf->SetFillColor(...PDF::AZUL_CLARO);
        if ($anchoTotal > 0) $pdf->RoundedRect($xB+1,$yB,$anchoTotal,2,1,'F');
        // Barra resueltos
        $anchoRes = $mes['n'] > 0 ? ($mes['resueltos']/$mes['n'])*$anchoTotal : 0;
        $pdf->SetFillColor(...PDF::VERDE);
        if ($anchoRes > 0) $pdf->RoundedRect($xB+1,$yB,$anchoRes,2,1,'F');
    }
    // Leyenda
    $pdf->Ln(2);
    $yLey = $pdf->GetY();
    $pdf->SetFillColor(...PDF::AZUL_CLARO);
    $pdf->Rect(12, $yLey, 8, 3, 'F');
    $pdf->SetFont('Helvetica','', 7.5); $pdf->SetTextColor(...PDF::GRIS);
    $pdf->SetXY(22, $yLey-0.5); $pdf->Cell(20, 4, 'Total', 0);
    $pdf->SetFillColor(...PDF::VERDE);
    $pdf->Rect(48, $yLey, 8, 3, 'F');
    $pdf->SetXY(58, $yLey-0.5); $pdf->Cell(30, 4, 'Resueltos', 0);
    $pdf->Ln(5);
}

// ── SATISFACCIÓN ──────────────────────────────────────────────
if ($satisf['total'] > 0) {
    $pdf->Seccion('Indice de satisfaccion');
    $pdf->SetFont('Helvetica','', 9);
    $pdf->SetTextColor(...PDF::AZUL_OSCURO);
    $yS = $pdf->GetY();
    $pdf->Cell(95, 5, 'Promedio general: ', 0, 0, 'L');
    $pdf->SetFont('Helvetica','B', 12);
    if ($satisf['prom'] >= 4) $pdf->SetTextColor(...PDF::VERDE);
    elseif ($satisf['prom'] >= 3) $pdf->SetTextColor(...PDF::AMARILLO);
    else $pdf->SetTextColor(...PDF::ROJO);
    $pdf->Cell(20, 5, $satisf['prom'].'/5', 0, 0, 'L');
    $pdf->SetTextColor(...PDF::GRIS);
    $pdf->SetFont('Helvetica','', 8);
    $pdf->Cell(0, 5, '('.$satisf['total'].' evaluaciones recibidas)', 0, 1);
    $pdf->Ln(2);

    // Barras de estrellas
    $estrellas = [5=>'p5',4=>'p4',3=>'p3',2=>'p2',1=>'p1'];
    $maxPts = max(array_map(fn($k) => (int)$satisf[$k], $estrellas)) ?: 1;
    foreach ($estrellas as $pts => $key) {
        $n   = (int)$satisf[$key];
        $pct2 = $satisf['total'] > 0 ? round($n/$satisf['total']*100,1) : 0;
        $pdf->SetFont('Helvetica','B', 8);
        $pdf->SetTextColor(...PDF::AMARILLO);
        $pdf->SetX(12); $pdf->Cell(15, 5, $pts.' estrellas', 0, 0);
        $xB = $pdf->GetX(); $yB = $pdf->GetY()+1;
        $pdf->SetTextColor(...PDF::AZUL_OSCURO);
        $pdf->Cell(90, 5, '', 0, 0);
        $pdf->SetFillColor(...PDF::BORDE);
        $pdf->RoundedRect($xB,$yB,80,3,1.5,'F');
        $pdf->SetFillColor(...PDF::AMARILLO);
        $fill5 = ($maxPts > 0) ? ($n/$maxPts)*80 : 0;
        if ($fill5>0) $pdf->RoundedRect($xB,$yB,$fill5,3,1.5,'F');
        $pdf->SetFont('Helvetica','', 8);
        $pdf->SetTextColor(...PDF::GRIS);
        $pdf->Cell(20, 5, $n.' ('.$pct2.'%)', 0, 1, 'L');
    }
    $pdf->Ln(2);
}

// ── NOTA FINAL ────────────────────────────────────────────────
$pdf->Separador();
$pdf->SetFont('Helvetica','I', 7.5);
$pdf->SetTextColor(...PDF::GRIS);
$pdf->MultiCell(190, 4,
    'Este reporte fue generado automaticamente por el sistema de gestion de soporte tecnico distrital de la ETMH. '.
    'Generado por: '.$user['nombre'].' '.$user['apellido'].' ('.$user['rol'].').  '.
    'Fecha de emision: '.date('d/m/Y H:i').'.', 0, 'C');

$pdf->Output('D', 'reporte_soporte_'.date('Ymd_Hi').'.pdf');
