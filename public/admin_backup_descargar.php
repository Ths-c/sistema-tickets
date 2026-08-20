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

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;