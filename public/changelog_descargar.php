<?php
/**
 * Descarga el PDF de changelog más reciente.
 * El programador deja los PDF en /changelog en cada actualización;
 * aquí se sirve siempre el último por fecha de modificación.
 * Requiere login (todos los roles).
 */
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$changelogDir = __DIR__ . '/../changelog';

$ultimo = null;
if (is_dir($changelogDir)) {
    foreach (scandir($changelogDir) as $file) {
        if (!str_ends_with(strtolower($file), '.pdf')) continue;
        $path = $changelogDir . '/' . $file;
        if (!is_file($path)) continue;
        // basename() implícito: $file viene de scandir, sin separadores.
        if (str_contains($file, '/') || str_contains($file, '\\')) continue;
        if ($ultimo === null || filemtime($path) > filemtime($changelogDir . '/' . $ultimo)) {
            $ultimo = $file;
        }
    }
}

if ($ultimo === null) {
    http_response_code(404);
    exit('Todavía no hay un changelog publicado. Volvé a intentarlo luego de la próxima actualización.');
}

$path = $changelogDir . '/' . $ultimo;

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $ultimo . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
