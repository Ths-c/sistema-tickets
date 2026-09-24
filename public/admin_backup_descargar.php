<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$backupsDir = __DIR__ . '/../backups';
$archivo = basename((string) ($_GET['archivo'] ?? ''));
$path = $backupsDir . '/' . $archivo;

if ($archivo === '' || !str_starts_with($archivo, 'backup_') || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

// ── Saneamiento: los backups viejos pueden traer al inicio el aviso
// "mysqldump: Deprecated program name…" (stderr redirigido al .sql).
// Se sirve el contenido limpio para que importe directo en phpMyAdmin.
// Mantenida en sync con admin_backup.php.
function sanearContenidoBackup(string $sql): string {
    $lineas  = preg_split("/\r\n|\n|\r/", $sql);
    $limpias = [];
    $empezo  = false;
    foreach ($lineas as $linea) {
        if (!$empezo) {
            $t = trim($linea);
            if ($t === '') continue;
            if (str_contains($linea, 'Deprecated program name')) {
                $pos = strpos($linea, '*/');
                if ($pos !== false) {
                    $resto = trim(substr($linea, $pos + 2));
                    if ($resto !== '') {
                        $limpias[] = $resto;
                        $empezo = true;
                    }
                }
                continue;
            }
            if (str_starts_with($t, 'mysqldump:') || str_starts_with($t, 'mariadb-dump:')) continue;
            if (preg_match('/^(Warning|ERROR \d+|Usage:)/i', $t)) continue;
            $empezo = true;
        }
        $limpias[] = $linea;
    }
    return implode("\n", $limpias);
}

$contenido = sanearContenidoBackup((string) file_get_contents($path));

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . (string) strlen($contenido));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $contenido;
exit;