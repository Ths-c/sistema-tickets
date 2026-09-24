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

// ── Saneamiento de dumps: quita del inicio las líneas de ruido que los
// binarios pueden emitir (ej. "mysqldump: Deprecated program name…") para
// que el .sql descargado importe directo en phpMyAdmin sin tocar código.
// Mantenida en sync con admin_backup_descargar.php.
function sanearContenidoBackup(string $sql): string {
    $lineas  = preg_split("/\r\n|\n|\r/", $sql);
    $limpias = [];
    $empezo  = false;
    foreach ($lineas as $linea) {
        if (!$empezo) {
            $t = trim($linea);
            if ($t === '') continue;
            // Aviso pegado al SQL en la misma línea: recortar el aviso y
            // conservar el resto (ej. "...instead /*M!999999 ... */ -- MariaDB dump ...").
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
            // Otras líneas de ruido de shell al inicio del archivo.
            if (str_starts_with($t, 'mysqldump:') || str_starts_with($t, 'mariadb-dump:')) continue;
            if (preg_match('/^(Warning|ERROR \d+|Usage:)/i', $t)) continue;
            $empezo = true;
        }
        $limpias[] = $linea;
    }
    return implode("\n", $limpias);
}

// El contenido es SQL importable: sin avisos y con primera línea útil válida.
function backupEsValido(string $sql): bool {
    if (str_contains($sql, 'Deprecated program name')) return false;
    foreach (preg_split("/\r\n|\n|\r/", $sql) as $linea) {
        $t = trim($linea);
        if ($t === '') continue;
        if (str_starts_with($t, '--') || str_starts_with($t, '/*') || str_starts_with($t, '!')) return true;
        return (bool) preg_match('/^(SET|CREATE|USE |LOCK|DROP|INSERT|ALTER|DELIMITER)/i', $t);
    }
    return false;
}

// ── Acciones POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'generar') {
        $timestamp = date('Ymd_His');
        $filename  = "backup_{$timestamp}.sql";
        $filepath  = $backupsDir . '/' . $filename;

        // Localizar el binario de volcado. Se prefiere el propio de XAMPP
        // (coincide con la versión del servidor y no sufre la contaminación
        // de LD_LIBRARY_PATH); si no está, el del sistema con entorno saneado.
        // Se evita el wrapper 'mysqldump' deprecado que ensucia el .sql.
        $mysqldump = '';
        foreach (['/opt/lampp/bin/mariadb-dump', '/opt/lampp/bin/mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe', '/usr/bin/mariadb-dump', '/usr/bin/mysqldump'] as $candidato) {
            if (is_file($candidato) && is_executable($candidato)) { $mysqldump = $candidato; break; }
        }
        if ($mysqldump === '') {
            // Respaldo: lo que haya en el PATH del servidor web.
            $cual = trim((string) shell_exec('command -v mariadb-dump 2>/dev/null || command -v mysqldump 2>/dev/null'));
            if ($cual !== '') $mysqldump = $cual;
        }
        // Los binarios del sistema se rompen si heredan el LD_LIBRARY_PATH de
        // XAMPP (/opt/lampp/lib sin GLIBCXX nuevo): se ejecuta con entorno
        // limpio. Los de /opt/lampp sí necesitan sus libs, se dejan como están.
        $envPrefix = ($mysqldump !== '' && !str_starts_with($mysqldump, '/opt/lampp')
            && !str_contains(strtolower($mysqldump), 'xampp'))
            ? 'env -u LD_LIBRARY_PATH ' : '';

        // ── Pre-chequeos: fallar con mensaje claro en vez del genérico ──
        $preError = null;
        if ($mysqldump === '') {
            $preError = 'No se encontró mariadb-dump/mysqldump en el servidor.';
        } elseif (!is_dir($backupsDir) || !is_writable($backupsDir)) {
            $preError = 'La carpeta backups/ no tiene permiso de escritura para el servidor web. '
                . 'En local (XAMPP Linux): sudo chown -R daemon ' . e($backupsDir) . '. '
                . 'En hosting: creá la carpeta backups/ desde el panel/FileZilla y dale permiso 755 (o 775). '
                . 'Si el hosting no permite exec() ni mysqldump, generá el backup desde phpMyAdmin → Exportar.';
        } elseif (!function_exists('exec')) {
            $preError = 'La función exec() está deshabilitada en este servidor.';
        }
        $stderrTmp = $preError === null ? tempnam(sys_get_temp_dir(), 'backup_err_') : false;
        if ($preError === null && $stderrTmp === false) {
            $preError = 'No se pudo crear un archivo temporal en ' . e(sys_get_temp_dir());
        }

        if ($preError !== null) {
            $error = 'No se pudo generar el backup de la base de datos.<br>' . $preError;
        } else {
        // stderr va a un temporal aparte: nunca dentro del .sql (eso era lo
        // que dejaba la línea "Deprecated program name" e impedía importar).
        // Si la contraseña está vacía se omite el flag (algunos wrappers lo
        // tratan distinto y pueden colgarse pidiéndola).
        // Con host local se fuerza TCP (127.0.0.1): el socket por defecto del
        // sistema (/var/lib/...) no coincide con el de XAMPP y el volcado
        // fallaría con "Can't connect through socket".
        $dumpHost = in_array(DB_HOST, ['localhost', '127.0.0.1'], true) ? '127.0.0.1' : DB_HOST;
        $passFlag = DB_PASS !== '' ? ' --password=' . escapeshellarg(DB_PASS) : '';
        $flagsBase = ' --user='    . escapeshellarg(DB_USER)
            . $passFlag
            . ' --host='    . escapeshellarg($dumpHost)
            . ' --protocol=TCP'
            . ' --no-tablespaces --routines --triggers'
            . ' ' . escapeshellarg(DB_NAME);
        // Si el servidor tiene mysql.proc desactualizado (o sin privilegios
        // para rutinas), el intento con --routines falla: se reintenta sin él.
        $intentosFlags = [$flagsBase, str_replace(' --routines', '', $flagsBase)];
        $output = [];
        $returnVar = 1;
        $stderr = '';
        $sinRutinas = false;
        foreach ($intentosFlags as $i => $flags) {
            $command = $envPrefix . $mysqldump . $flags
                . ' > ' . escapeshellarg($filepath)
                . ' 2> ' . escapeshellarg($stderrTmp);
            $output    = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            $stderr = is_file($stderrTmp) ? (string) file_get_contents($stderrTmp) : '';
            if ($returnVar === 0 && is_file($filepath) && filesize($filepath) > 0) break;
            $fallaRutinas = str_contains($stderr, 'mysql.proc')
                || str_contains($stderr, 'SHOW FUNCTION STATUS')
                || str_contains($stderr, 'SHOW PROCEDURE STATUS');
            if ($i === 0 && $fallaRutinas) {
                $sinRutinas = true;
                continue;
            }
            break;
        }
        if (is_file($stderrTmp)) @unlink($stderrTmp);

        if ($returnVar === 0 && is_file($filepath) && filesize($filepath) > 0) {
            $contenido = sanearContenidoBackup((string) file_get_contents($filepath));
            if (!backupEsValido($contenido)) {
                @unlink($filepath);
                $error = 'El backup generado no superó la validación de SQL importable y se descartó. Probá de nuevo o avisá al administrador.';
                if (trim($stderr) !== '') {
                    $error .= '<br><small style="font-family:monospace;">' . e($stderr) . '</small>';
                }
            } else {
                file_put_contents($filepath, $contenido);
                $historial = leerHistorial($metaFile);
                array_unshift($historial, [
                    'archivo' => $filename,
                    'fecha'   => date('Y-m-d H:i:s'),
                    'usuario' => $usuario['nombre'] . ' ' . $usuario['apellido'],
                    'tamano'  => (int) filesize($filepath),
                ]);
                guardarHistorial($metaFile, $historial);
                $ok = 'Backup generado correctamente: <strong>' . e($filename) . '</strong>';
                if ($sinRutinas) {
                    $ok .= '<br><small>Sin rutinas almacenadas (el servidor MySQL necesita mysql_upgrade; tablas y triggers incluidos).</small>';
                }
            }
        } else {
            if (is_file($filepath)) @unlink($filepath);
            $error = 'No se pudo generar el backup de la base de datos.'
                . '<br><small style="font-family:monospace;">Binario: ' . e($mysqldump)
                . ' · retorno: ' . (int) $returnVar . '</small>';
            $detalle = trim($stderr) !== '' ? $stderr : implode("\n", $output);
            if (trim($detalle) !== '') {
                $error .= '<br><small style="font-family:monospace;">' . e($detalle) . '</small>';
            } elseif ($returnVar !== 0) {
                $error .= '<br><small style="font-family:monospace;">Sin mensaje del sistema: suele ser permiso de escritura en backups/ o credenciales del volcado.</small>';
            }
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