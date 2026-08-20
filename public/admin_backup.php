<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo = obtenerConexion();
$usuario = usuarioActual();
$tituloPagina = 'Backup de base de datos';

// Directorio de backups (fuera de public/, no accesible directamente)
$backupsDir = __DIR__ . '/../backups';
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}
$metaFile = $backupsDir . '/historial.json';

$ok    = null;
$error = null;

// ── Historial (metadatos: quién generó cada backup) ──────────
function leerHistorial(string $metaFile): array {
    if (!is_file($metaFile)) return [];
    $data = json_decode((string) file_get_contents($metaFile), true);
    return is_array($data) ? $data : [];
}

function guardarHistorial(string $metaFile, array $historial): void {
    file_put_contents($metaFile, json_encode($historial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function formatoTamano(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return $bytes . ' B';
}

// ── Acciones POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'generar') {
        $timestamp = date('Ymd_His');
        $filename  = "backup_{$timestamp}.sql";
        $filepath  = $backupsDir . '/' . $filename;

        // Localizar mysqldump (XAMPP por defecto)
        $mysqldump = 'mysqldump';
        foreach (['C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'] as $candidato) {
            if (is_file($candidato)) { $mysqldump = $candidato; break; }
        }

        $command = $mysqldump
            . ' --user='    . escapeshellarg(DB_USER)
            . ' --password=' . escapeshellarg(DB_PASS)
            . ' --host='    . escapeshellarg(DB_HOST)
            . ' --no-tablespaces --routines --triggers'
            . ' ' . escapeshellarg(DB_NAME)
            . ' > ' . escapeshellarg($filepath)
            . ' 2>&1';

        $output    = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar === 0 && is_file($filepath) && filesize($filepath) > 0) {
            $historial = leerHistorial($metaFile);
            array_unshift($historial, [
                'archivo' => $filename,
                'fecha'   => date('Y-m-d H:i:s'),
                'usuario' => $usuario['nombre'] . ' ' . $usuario['apellido'],
                'tamano'  => (int) filesize($filepath),
            ]);
            guardarHistorial($metaFile, $historial);
            $ok = 'Backup generado correctamente: <strong>' . e($filename) . '</strong>';
        } else {
            $error = 'No se pudo generar el backup de la base de datos.';
            if (!empty($output)) {
                $error .= '<br><small style="font-family:monospace;">' . e(implode('<br>', $output)) . '</small>';
            }
        }
    }

    if ($accion === 'eliminar') {
        $archivo = basename((string) ($_POST['archivo'] ?? ''));
        $path = $backupsDir . '/' . $archivo;
        if ($archivo !== '' && str_starts_with($archivo, 'backup_') && is_file($path)) {
            unlink($path);
            $historial = array_values(array_filter(leerHistorial($metaFile), fn($h) => ($h['archivo'] ?? '') !== $archivo));
            guardarHistorial($metaFile, $historial);
            $ok = 'Backup eliminado: <strong>' . e($archivo) . '</strong>';
        }
    }
}

// ── Listar backups ───────────────────────────────────────────
$historial = leerHistorial($metaFile);
$porArchivo = [];
foreach ($historial as $h) {
    $porArchivo[$h['archivo'] ?? ''] = $h;
}

$backups = [];
if (is_dir($backupsDir)) {
    foreach (scandir($backupsDir) as $file) {
        if ($file === '.' || $file === '..' || $file === 'historial.json') continue;
        if (!str_starts_with($file, 'backup_')) continue;
        $path = $backupsDir . '/' . $file;
        $meta = $porArchivo[$file] ?? [];
        $backups[] = [
            'nombre'  => $file,
            'tamano'  => (int) filesize($path),
            'fecha'   => $meta['fecha'] ?? date('Y-m-d H:i:s', filemtime($path)),
            'usuario' => $meta['usuario'] ?? '—',
            'descargar' => 'admin_backup_descargar.php?archivo=' . rawurlencode($file),
        ];
    }
}
usort($backups, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));

// ── Métricas de contexto ─────────────────────────────────────
$totalBackups  = count($backups);
$totalSize     = array_sum(array_column($backups, 'tamano'));
$ultimoBackup  = $backups[0] ?? null;

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Backup de base de datos</h1>
    <p>Generá copias de seguridad completas de la base de datos y administrá el historial de respaldos del sistema.</p>
</div>

<?php if ($ok):    ?><div class="alerta alerta-ok"><?= $ok ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= $error ?></div><?php endif; ?>

<!-- Acción principal: generar backup -->
<div class="tarjeta" style="border-left:5px solid var(--acento); display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;">
    <div style="flex:1; min-width:240px;">
        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
            <span style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.35rem 0.9rem; border-radius:999px; font-size:0.85rem; font-weight:700; background:var(--acento-claro); color:var(--acento);">
                <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block;"></span>
                ÚLTIMO BACKUP
            </span>
            <?php if ($ultimoBackup): ?>
                <span class="texto-2">
                    <?= date('d/m/Y H:i', strtotime($ultimoBackup['fecha'])) ?>
                    · <?= e($ultimoBackup['usuario']) ?>
                </span>
            <?php else: ?>
                <span class="texto-2">Sin respaldos todavía</span>
            <?php endif; ?>
        </div>
        <p class="texto-2" style="margin:0;">
            El backup exporta todas las tablas, datos, procedimientos y triggers de la base
            <strong style="color:var(--texto);"><?= e(DB_NAME) ?></strong>
            a un archivo <code>.sql</code> con marca de fecha y hora.
        </p>
    </div>

    <form method="post" id="formGenerar" style="flex-shrink:0;"
          onsubmit="return confirm('¿Generar un nuevo backup de la base de datos? El proceso puede tardar unos segundos.')">
        <input type="hidden" name="accion" value="generar">
        <button type="submit" id="btnGenerar" class="boton" style="margin:0;">
            <span id="iconoBtn">💾</span> Generar backup
        </button>
    </form>
</div>

<!-- Métricas -->
<div class="metricas" style="margin-bottom:1.25rem;">
    <div class="metrica-card">
        <div style="font-size:1.8rem; font-weight:750; color:var(--acento); letter-spacing:-0.02em;"><?= $totalBackups ?></div>
        <div class="texto-3" style="margin-top:3px;">Backups totales</div>
    </div>
    <div class="metrica-card">
        <div style="font-size:1.8rem; font-weight:750; color:var(--verde); letter-spacing:-0.02em;"><?= formatoTamano($totalSize) ?></div>
        <div class="texto-3" style="margin-top:3px;">Espacio ocupado</div>
    </div>
    <div class="metrica-card">
        <div style="font-size:1.8rem; font-weight:750; color:var(--texto); letter-spacing:-0.02em;">
            <?= $ultimoBackup ? date('d/m', strtotime($ultimoBackup['fecha'])) : '—' ?>
        </div>
        <div class="texto-3" style="margin-top:3px;">Último respaldo</div>
    </div>
    <div class="metrica-card">
        <div style="font-size:1.8rem; font-weight:750; color:var(--amarillo); letter-spacing:-0.02em;">
            <?= $ultimoBackup ? formatoTamano($ultimoBackup['tamano']) : '—' ?>
        </div>
        <div class="texto-3" style="margin-top:3px;">Tamaño último</div>
    </div>
</div>

<!-- Historial -->
<div class="tarjeta">
    <div class="tarjeta-titulo">Historial de backups</div>

    <?php if ($backups): ?>
        <div class="tabla-wrap">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Fecha</th>
                    <th>Generado por</th>
                    <th>Tamaño</th>
                    <th style="text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $b): ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <span style="margin-right:0.4rem;">🗄️</span>
                        <code style="font-size:0.8rem;"><?= e($b['nombre']) ?></code>
                    </td>
                    <td class="texto-2" style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($b['fecha'])) ?></td>
                    <td class="texto-2"><?= e($b['usuario']) ?></td>
                    <td class="negrita" style="white-space:nowrap;"><?= formatoTamano($b['tamano']) ?></td>
                    <td>
                        <div class="acciones-tabla">
                            <a href="<?= e($b['descargar']) ?>" class="boton boton-secundario boton-sm">⬇ Descargar</a>
                            <form method="post" onsubmit="return confirm('¿Eliminar este backup? No se podrá recuperar.')">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="archivo" value="<?= e($b['nombre']) ?>">
                                <button type="submit" class="boton boton-peligro boton-sm">🗑 Eliminar</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <div style="text-align:center; padding:2.5rem 1rem;">
            <div style="font-size:2.5rem; margin-bottom:0.75rem;">🗄️</div>
            <p style="font-weight:650; margin-bottom:0.35rem;">Todavía no hay backups</p>
            <p class="texto-2" style="margin:0;">
                Presioná <strong>Generar backup</strong> para crear la primera copia de seguridad de la base de datos.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php if ($totalBackups >= 10): ?>
<div class="alerta alerta-error">
    <strong>Recomendación:</strong> tenés <?= $totalBackups ?> backups acumulados
    (<?= formatoTamano($totalSize) ?>). Eliminá los más antiguos para liberar espacio en el servidor.
</div>
<?php endif; ?>

<style>
/* Deshabilitar botón mientras se genera el backup */
.btn-generando {
    background: var(--texto-3) !important;
    cursor: wait !important;
    pointer-events: none;
}
@keyframes girar { to { transform: rotate(360deg); } }
.btn-generando .icono-generar { display:inline-block; animation: girar 0.9s linear infinite; }
</style>

<script>
document.getElementById('formGenerar')?.addEventListener('submit', function () {
    const btn = document.getElementById('btnGenerar');
    btn.classList.add('btn-generando');
    btn.innerHTML = '<span class="icono-generar">⏳</span> Generando backup…';
    btn.disabled = true;
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>