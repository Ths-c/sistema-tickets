<?php
/**
 * Genera el PDF de la constancia de entrega/recepción de equipo
 * directamente con FPDF — sin depender del "imprimir" del navegador.
 * Se llama desde constancia_equipo.php con ?pdf=1
 */
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();
require_once __DIR__ . '/../lib/fpdf.php';

$usuario  = usuarioActual();
$pdo      = obtenerConexion();
$ticketId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT t.*, e.nombre AS escuela_nombre, e.localidad AS escuela_localidad,
            c.nombre AS categoria_nombre,
            CONCAT(sol.nombre,' ',sol.apellido) AS solicitante_nombre,
            CONCAT(tec.nombre,' ',tec.apellido) AS tecnico_nombre
     FROM tickets t
     JOIN escuelas e ON e.id=t.escuela_id
     JOIN categorias c ON c.id=t.categoria_id
     JOIN usuarios sol ON sol.id=t.solicitante_id
     LEFT JOIN usuarios tec ON tec.id=t.tecnico_id
     WHERE t.id=:id"
);
$stmt->execute(['id'=>$ticketId]);
$ticket = $stmt->fetch();
if (!$ticket) { http_response_code(404); die('Ticket no encontrado.'); }

$puedeVer = match($usuario['rol']) {
    'admin','coordinador' => true,
    'solicitante'         => $ticket['solicitante_id'] === $usuario['id'],
    'tecnico'             => $ticket['tecnico_id']     === $usuario['id'],
    default               => false,
};
if (!$puedeVer) { http_response_code(403); die('Sin permiso.'); }

$acta = $pdo->prepare('SELECT * FROM actas_equipo WHERE ticket_id=:id');
$acta->execute(['id'=>$ticketId]);
$acta = $acta->fetch() ?: [];

$v = fn(string $k, string $def='') => $acta[$k] ?? $def;
$fmtFecha = fn(?string $f) => $f ? date('d/m/Y H:i', strtotime($f)) : '___/___/____  __:__';

// ── PDF ─────────────────────────────────────────────────────
$pdf = new FPDF('P','mm','A4');
$pdf->SetMargins(18,18,18);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// Encabezado
$pdf->SetFillColor(15, 23, 42);
$pdf->Rect(0, 0, 210, 24, 'F');
$pdf->SetFont('Helvetica','B', 13);
$pdf->SetTextColor(255,255,255);
$pdf->SetXY(18, 6);
$pdf->Cell(0, 7, 'CONSTANCIA DE ENTREGA Y RECEPCION DE EQUIPO', 0, 1, 'C');
$pdf->SetFont('Helvetica','', 9);
$pdf->SetXY(18, 14);
$pdf->Cell(0, 5, 'Proyecto de soporte tecnico distrital  |  Escuela Tecnica de Monte Hermoso', 0, 1, 'C');
$pdf->SetTextColor(15,23,42);
$pdf->SetY(30);

// Nro de ticket y datos básicos
$pdf->SetFont('Helvetica','B', 10);
$pdf->Cell(0, 7, 'Ticket N° '.$ticket['id'].'  —  '.$ticket['titulo'], 0, 1, 'C');
$pdf->SetFont('Helvetica','', 9);
$pdf->SetTextColor(71,85,105);
$pdf->Cell(0, 5, 'Escuela: '.$ticket['escuela_nombre'].' ('.$ticket['escuela_localidad'].')  |  Categoria: '.$ticket['categoria_nombre'], 0, 1, 'C');
$pdf->SetTextColor(15,23,42);
$pdf->Ln(3);

// Helper: campo con línea
$campoLinea = function(string $label, string $valor) use ($pdf): void {
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(0, 4, strtoupper($label), 0, 1);
    $pdf->SetFont('Helvetica','', 10);
    $pdf->SetTextColor(15,23,42);
    $valorMostrar = $valor !== '' ? $valor : ' ';
    $pdf->SetFillColor(248,250,252);
    $pdf->Cell(0, 7, '  '.$valorMostrar, 'B', 1, 'L', true);
    $pdf->Ln(2);
};

$campoDoble = function(string $l1, string $v1, string $l2, string $v2) use ($pdf): void {
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(84, 4, strtoupper($l1), 0, 0);
    $pdf->Cell(4, 4, '', 0, 0);
    $pdf->Cell(84, 4, strtoupper($l2), 0, 1);
    $pdf->SetFont('Helvetica','', 10);
    $pdf->SetTextColor(15,23,42);
    $pdf->SetFillColor(248,250,252);
    $pdf->Cell(84, 7, '  '.($v1?:' '), 'B', 0, 'L', true);
    $pdf->Cell(4, 7, '', 0, 0);
    $pdf->Cell(84, 7, '  '.($v2?:' '), 'B', 1, 'L', true);
    $pdf->Ln(2);
};

// Sección separadora
$seccion = function(string $titulo, int $num) use ($pdf): void {
    $pdf->Ln(2);
    $pdf->SetFillColor(37,99,235);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('Helvetica','B', 10);
    $pdf->Cell(0, 7, "  {$num}. {$titulo}", 0, 1, 'L', true);
    $pdf->SetTextColor(15,23,42);
    $pdf->Ln(3);
};

// ── Datos del equipo ─────────────────────────────────────────
$seccion('DATOS DEL EQUIPO', 1);
$campoDoble('Tipo de equipo',     $v('equipo_tipo'),          'Marca / Modelo',            $v('equipo_marca_modelo'));
$campoDoble('N° de serie / Inventario', $v('equipo_numero_serie'), 'Accesorios entregados', $v('accesorios'));

// ── Etapa 1: Entrega ─────────────────────────────────────────
$seccion('ENTREGA DEL EQUIPO — Escuela al proyecto', 2);
$campoLinea('Fecha y hora de entrega',          $fmtFecha($v('entrega_fecha') ?: null));
$campoLinea('Estado del equipo al momento de la entrega', $v('entrega_estado_equipo') ?: '');
$campoDoble('Nombre y apellido (quien entrega, escuela)', $v('entrega_nombre_escuela'),
            'Cargo', $v('entrega_cargo_escuela'));
$campoLinea('Quien recibe (por el proyecto)', $v('entrega_nombre_receptor'));

$pdf->Ln(4);
$y = $pdf->GetY();
$pdf->SetDrawColor(15,23,42);
$pdf->Line(18, $y+18, 90, $y+18);
$pdf->Line(116, $y+18, 190, $y+18);
$pdf->SetFont('Helvetica','', 8); $pdf->SetTextColor(71,85,105);
$pdf->SetXY(18, $y+19); $pdf->Cell(72, 4, 'Firma quien entrega (escuela)', 0, 0, 'C');
$pdf->SetXY(116, $y+19); $pdf->Cell(74, 4, 'Firma quien recibe (proyecto)', 0, 1, 'C');
$pdf->Ln(10);

// ── Etapa 2: Asignación ────────────────────────────────────────
$seccion('ASIGNACION A TECNICO — Proyecto al tecnico', 3);
$campoDoble('Fecha y hora de asignacion', $fmtFecha($v('asignacion_fecha') ?: null),
            'Tecnico asignado', $v('asignacion_nombre_tecnico', $ticket['tecnico_nombre'] ?? ''));
$campoLinea('Observaciones', $v('asignacion_observaciones') ?: '');

$pdf->Ln(4);
$y = $pdf->GetY();
$pdf->SetDrawColor(15,23,42);
$pdf->Line(53, $y+18, 155, $y+18);
$pdf->SetFont('Helvetica','', 8); $pdf->SetTextColor(71,85,105);
$pdf->SetXY(18, $y+19); $pdf->Cell(174, 4, 'Firma del tecnico que recibe el equipo', 0, 1, 'C');
$pdf->Ln(10);

// ── Etapa 3: Resolución ─────────────────────────────────────────
$seccion('RESOLUCION — Trabajo realizado', 4);
$campoLinea('Fecha y hora',            $fmtFecha($v('resolucion_fecha') ?: null));
$campoLinea('Trabajo realizado',       $v('resolucion_trabajo_realizado') ?: '');
$campoLinea('Estado del equipo al finalizar', $v('resolucion_estado_equipo') ?: '');

// ── Etapa 4: Devolución ─────────────────────────────────────────
$seccion('DEVOLUCION DEL EQUIPO — Proyecto a la escuela originante', 5);
$campoLinea('Fecha y hora de devolucion',        $fmtFecha($v('devolucion_fecha') ?: null));
$campoLinea('Estado del equipo al momento de la devolucion', $v('devolucion_estado_equipo') ?: '');
$campoLinea('Nombre del tecnico que devuelve',   $v('devolucion_nombre_tecnico', $ticket['tecnico_nombre'] ?? ''));
$campoDoble('Nombre y apellido (quien recibe, escuela)', $v('devolucion_nombre_escuela'),
            'Cargo', $v('devolucion_cargo_escuela'));

// Espacio de firmas devolución
$pdf->Ln(4);
$y = $pdf->GetY();
$pdf->SetDrawColor(15,23,42);
$pdf->Line(18, $y+18, 90, $y+18);
$pdf->Line(116, $y+18, 190, $y+18);
$pdf->SetFont('Helvetica','', 8); $pdf->SetTextColor(71,85,105);
$pdf->SetXY(18, $y+19); $pdf->Cell(72, 4, 'Firma del tecnico que devuelve', 0, 0, 'C');
$pdf->SetXY(116, $y+19); $pdf->Cell(74, 4, 'Firma quien recibe (escuela)', 0, 1, 'C');
$pdf->Ln(10);

// Nota legal
$pdf->SetFont('Helvetica','I', 8);
$pdf->SetTextColor(148,163,184);
$pdf->MultiCell(0, 4,
    'Ambas partes declaran conformidad con lo consignado en este documento, en el marco del proyecto distrital '.
    'de soporte tecnico educativo de la Escuela Tecnica de Monte Hermoso. '.
    'Documento generado el '.date('d/m/Y H:i').'.',
    0, 'C');

$pdf->Output('D', 'constancia_ticket'.$ticketId.'_'.date('Ymd').'.pdf');
