<?php
/**
 * Genera el PDF de la constancia de entrega/recepción de equipo
 * directamente con FPDF — sin depender del "imprimir" del navegador.
 * Se llama desde constancia_equipo.php con ?pdf=1
 */
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();
require_once __DIR__ . '/../lib/fpdf.php';
require_once __DIR__ . '/../lib/pdf_texto.php';

// Conversión automática UTF-8 → Latin1 en toda salida de texto
// (las fuentes core de FPDF solo renderizan Latin1).
class ConstanciaPDF extends FPDF
{
    /**
     * Cuando MultiCell()/Write() ya convirtieron el texto, las llamadas
     * internas que FPDF hace a $this->Cell() no deben reconvertirlo
     * (la doble conversión UTF-8→Latin1 rompe los acentos y muestra '?').
     */
    protected bool $textoYaConvertido = false;

    function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $t = $this->textoYaConvertido ? (string) $txt : pdfTextoLatin1($txt);
        parent::Cell($w, $h, $t, $border, $ln, $align, $fill, $link);
    }

    function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        $this->textoYaConvertido = true;
        try {
            parent::MultiCell($w, $h, pdfTextoLatin1($txt), $border, $align, $fill);
        } finally {
            $this->textoYaConvertido = false;
        }
    }

    function Write($h, $txt, $link = '')
    {
        $this->textoYaConvertido = true;
        try {
            parent::Write($h, pdfTextoLatin1($txt), $link);
        } finally {
            $this->textoYaConvertido = false;
        }
    }

    function Text($x, $y, $txt)
    {
        parent::Text($x, $y, pdfTextoLatin1($txt));
    }

    /** True si un bloque de $altura mm ya no entra en la página actual. */
    function bloqueNoEntra(float $altura): bool
    {
        return $this->GetY() + $altura > $this->PageBreakTrigger;
    }

    /** Altura estimada que ocupará un MultiCell($w, $h, $txt): sirve para evitar cortes. */
    function alturaMultiCell(float $w, float $h, string $txt): float
    {
        $txt = pdfTextoLatin1($txt);
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $usable = max(1, $w - 2 * $this->cMargin);
        $lineas = 0;
        foreach (explode("\n", $txt) as $parrafo) {
            $lineas += max(1, (int) ceil($this->GetStringWidth($parrafo) / $usable));
        }
        return $lineas * $h;
    }
}

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

// Dispositivos vinculados (máximo 2)
$dispositivosPdf = [];
$limiteDispPdf = 2;
try {
    $limiteDispPdf = limiteDispositivosPorTicket($pdo);
    $dispositivosPdf = obtenerDispositivosTicket($pdo, $ticketId);
} catch (Throwable $e) {
    $dispositivosPdf = [];
}

// ── PDF ─────────────────────────────────────────────────────
$pdf = new ConstanciaPDF('P','mm','A4');
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
$pdf->Cell(0, 5, 'CESDE - Centro de Soporte Digital Educativo', 0, 1, 'C');
$pdf->SetTextColor(15,23,42);
$pdf->SetY(30);

// Nro de ticket y datos básicos (MultiCell: títulos largos no se cortan)
$pdf->SetFont('Helvetica','B', 10);
$pdf->MultiCell(0, 7, 'Ticket N° '.$ticket['id'].'  —  '.$ticket['titulo'], 0, 'C');
$pdf->SetFont('Helvetica','', 9);
$pdf->SetTextColor(71,85,105);
$pdf->MultiCell(0, 5, 'Escuela: '.$ticket['escuela_nombre'].' ('.$ticket['escuela_localidad'].')  |  Categoria: '.$ticket['categoria_nombre'], 0, 'C');
$pdf->SetTextColor(15,23,42);
$pdf->Ln(3);

// Helper: campo con línea (el valor usa MultiCell: textos largos hacen wrap, no se cortan)
$campoLinea = function(string $label, string $valor) use ($pdf): void {
    $pdf->SetFont('Helvetica','', 10);
    $hValor = $pdf->alturaMultiCell(0, 7, '  '.($valor !== '' ? $valor : ' '));
    if ($pdf->bloqueNoEntra(4 + $hValor)) $pdf->AddPage();
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(0, 4, strtoupper($label), 0, 1);
    $pdf->SetFont('Helvetica','', 10);
    $pdf->SetTextColor(15,23,42);
    $valorMostrar = $valor !== '' ? $valor : ' ';
    $pdf->SetFillColor(248,250,252);
    $pdf->MultiCell(0, 7, '  '.$valorMostrar, 'B', 'L', true);
    $pdf->Ln(2);
};

$campoDoble = function(string $l1, string $v1, string $l2, string $v2) use ($pdf): void {
    $pdf->SetFont('Helvetica','', 10);
    $t1 = '  '.($v1 !== '' ? $v1 : ' ');
    $t2 = '  '.($v2 !== '' ? $v2 : ' ');
    $hMax = max($pdf->alturaMultiCell(84, 7, $t1), $pdf->alturaMultiCell(84, 7, $t2));
    if ($pdf->bloqueNoEntra(4 + $hMax)) $pdf->AddPage();
    $pdf->SetFont('Helvetica','B', 8);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(84, 4, strtoupper($l1), 0, 0);
    $pdf->Cell(4, 4, '', 0, 0);
    $pdf->Cell(84, 4, strtoupper($l2), 0, 1);
    $pdf->SetFont('Helvetica','', 10);
    $pdf->SetTextColor(15,23,42);
    $pdf->SetFillColor(248,250,252);
    $x0 = $pdf->GetX();
    $y0 = $pdf->GetY();
    $pdf->SetXY($x0, $y0);
    $pdf->MultiCell(84, 7, $t1, 'B', 'L', true);
    $y1 = $pdf->GetY();
    $pdf->SetXY($x0 + 88, $y0);
    $pdf->MultiCell(84, 7, $t2, 'B', 'L', true);
    $pdf->SetY(max($y1, $pdf->GetY()));
    $pdf->Ln(2);
};

// Sección separadora (no queda huérfana al pie de página)
$seccion = function(string $titulo, int $num) use ($pdf): void {
    $pdf->Ln(2);
    if ($pdf->bloqueNoEntra(12)) $pdf->AddPage();
    $pdf->SetFillColor(37,99,235);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('Helvetica','B', 10);
    $pdf->Cell(0, 7, "  {$num}. {$titulo}", 0, 1, 'L', true);
    $pdf->SetTextColor(15,23,42);
    $pdf->Ln(3);
};

// ── Datos del equipo / Dispositivos ───────────────────────────
$seccion('DATOS DEL EQUIPO'.(!empty($dispositivosPdf) ? ' — Dispositivos del ticket ('.count($dispositivosPdf).'/'.$limiteDispPdf.')' : ''), 1);
if (!empty($dispositivosPdf)) {
    foreach ($dispositivosPdf as $idx => $d) {
        $n = $idx + 1;
        $pdf->SetFont('Helvetica','B', 9);
        $pdf->SetTextColor(37,99,235);
        $pdf->MultiCell(0, 6, '  Dispositivo '.$n.': '.($d['tipo'] ?? ''), 0, 'L');
        $pdf->SetTextColor(15,23,42);
        $campoDoble('Marca / Modelo (Disp. '.$n.')', $d['marca_modelo'] ?? '', 'N° serie / Inventario (Disp. '.$n.')', $d['numero_serie'] ?? '');
        if (!empty($d['descripcion'])) {
            $campoLinea('Falla reportada (Disp. '.$n.')', $d['descripcion']);
        }
    }
    // Accesorios siguen siendo del acta general
    $campoLinea('Accesorios entregados (acta)', $v('accesorios') ?: '');
} else {
    $campoDoble('Tipo de equipo',     $v('equipo_tipo'),          'Marca / Modelo',            $v('equipo_marca_modelo'));
    $campoDoble('N° de serie / Inventario', $v('equipo_numero_serie'), 'Accesorios entregados', $v('accesorios'));
}

// ── Etapa 1: Entrega ─────────────────────────────────────────
$seccion('ENTREGA DEL EQUIPO — Escuela al proyecto', 2);
$campoLinea('Fecha y hora de entrega',          $fmtFecha($v('entrega_fecha') ?: null));
$campoLinea('Estado del equipo al momento de la entrega', $v('entrega_estado_equipo') ?: '');
$campoDoble('Nombre y apellido (quien entrega, escuela)', $v('entrega_nombre_escuela'),
            'Cargo', $v('entrega_cargo_escuela'));
$campoLinea('Quien recibe (por el proyecto)', $v('entrega_nombre_receptor'));

$pdf->Ln(4);
// El bloque de firmas necesita ~37mm: si no entra, pasa entero a la página siguiente.
if ($pdf->bloqueNoEntra(37)) $pdf->AddPage();
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
// El bloque de firmas necesita ~37mm: si no entra, pasa entero a la página siguiente.
if ($pdf->bloqueNoEntra(37)) $pdf->AddPage();
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
// El bloque de firmas necesita ~37mm: si no entra, pasa entero a la página siguiente.
if ($pdf->bloqueNoEntra(37)) $pdf->AddPage();
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
    'de CESDE - Centro de Soporte Digital Educativo. '.
    'Documento generado el '.date('d/m/Y H:i').'.',
    0, 'C');

$pdf->Output('D', 'constancia_ticket'.$ticketId.'_'.date('Ymd').'.pdf');
