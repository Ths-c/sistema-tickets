<?php
/**
 * Conversión de texto UTF-8 → Latin1 (ISO-8859-1) para FPDF.
 *
 * Las fuentes core de FPDF (Helvetica, etc.) solo renderizan Latin1:
 * todo texto que sale de la BD o de literales viene en UTF-8 y debe
 * convertirse antes de pasarlo a Cell()/MultiCell()/Write()/Text().
 * Sin esto las tildes/ñ se ven como caracteres raros (ej. Ã©, â€”)
 * y el cálculo de anchos de FPDF se descuadra (cuenta bytes, no letras).
 */
function pdfTextoLatin1($v): string
{
    $s = (string) ($v ?? '');
    if ($s === '') return '';
    // Símbolos sin equivalente en Latin1 → ASCII seguro
    $s = str_replace(
        ['—', '–', '→', '•', '★', '✓', '○', '“', '”', '‘', '’', '…'],
        ['-', '-', '->', '-', '*', 'v', 'o', '"', '"', "'", "'", '...'],
        $s
    );
    if (function_exists('iconv')) {
        $c = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        if ($c !== false) return $c;
    }
    return @utf8_decode($s);
}
