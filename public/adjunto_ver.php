<?php
require_once __DIR__ . '/../config/sesion.php';
requerirLogin();

$usuario = usuarioActual();
$pdo = obtenerConexion();
$adjuntoId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.*, t.solicitante_id, t.tecnico_id
     FROM adjuntos a JOIN tickets t ON t.id = a.ticket_id
     WHERE a.id = :id"
);
$stmt->execute(['id' => $adjuntoId]);
$adjunto = $stmt->fetch();

if (!$adjunto) {
    http_response_code(404);
    die('Adjunto no encontrado.');
}

// Mismo criterio de acceso que en ticket_detalle.php
$puedeVer = match ($usuario['rol']) {
    'admin', 'coordinador' => true,
    'solicitante' => $adjunto['solicitante_id'] === $usuario['id'],
    'tecnico'     => $adjunto['tecnico_id'] === $usuario['id'],
    default       => false,
};
if (!$puedeVer) {
    http_response_code(403);
    die('No tenés permiso para ver este archivo.');
}

$rutaCompleta = __DIR__ . '/../uploads/adjuntos/' . basename($adjunto['ruta_archivo']);
if (!is_file($rutaCompleta)) {
    http_response_code(404);
    die('El archivo ya no está disponible.');
}

$extension = strtolower(pathinfo($rutaCompleta, PATHINFO_EXTENSION));
$tiposMime = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
];
$mime = $tiposMime[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($rutaCompleta));
header('Content-Disposition: inline; filename="' . basename($adjunto['nombre_archivo']) . '"');
readfile($rutaCompleta);
exit;
