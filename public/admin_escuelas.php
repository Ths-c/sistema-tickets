<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo = obtenerConexion();
$tituloPagina = 'Escuelas';
$ok = null;
$error = null;
$editando = null;

$tipos = $pdo->query('SELECT id, nombre FROM tipos_escuela WHERE activo=1 ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion    = $_POST['accion'] ?? '';
    $id        = (int) ($_POST['id'] ?? 0);
    $nombre    = trim($_POST['nombre']    ?? '');
    $localidad = trim($_POST['localidad'] ?? '');
    $tipoId    = (int) ($_POST['tipo_id'] ?? 0) ?: null;
    $direccion = trim($_POST['direccion'] ?? '') ?: null;
    $telefono  = trim($_POST['telefono']  ?? '') ?: null;

    if ($accion === 'guardar') {
        if ($nombre === '' || $localidad === '') {
            $error = 'Nombre y localidad son obligatorios.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE escuelas SET nombre=:n, localidad=:l, tipo_id=:t, direccion=:d, telefono=:tel WHERE id=:id')
                        ->execute(['n'=>$nombre,'l'=>$localidad,'t'=>$tipoId,'d'=>$direccion,'tel'=>$telefono,'id'=>$id]);
                    $ok = 'Escuela actualizada.';
                } else {
                    $pdo->prepare('INSERT INTO escuelas (nombre, localidad, tipo_id, direccion, telefono) VALUES (:n,:l,:t,:d,:tel)')
                        ->execute(['n'=>$nombre,'l'=>$localidad,'t'=>$tipoId,'d'=>$direccion,'tel'=>$telefono]);
                    $ok = 'Escuela creada.';
                }
            } catch (PDOException $e) {
                $error = 'No se pudo guardar la escuela.';
            }
        }
    }

    if ($accion === 'toggle') {
        $row = $pdo->prepare('SELECT activa FROM escuelas WHERE id=:id');
        $row->execute(['id'=>$id]);
        $actual = (int) $row->fetchColumn();
        $pdo->prepare('UPDATE escuelas SET activa=:a WHERE id=:id')->execute(['a'=>$actual?0:1,'id'=>$id]);
        $ok = $actual ? 'Escuela desactivada.' : 'Escuela activada.';
    }

    if ($accion === 'toggle_bloqueo_tickets') {
        $usuarioAdmin = usuarioActual();
        $row = $pdo->prepare('SELECT nombre, tickets_bloqueados FROM escuelas WHERE id=:id');
        $row->execute(['id'=>$id]);
        $fila = $row->fetch();
        if ($fila) {
            $actual = (int) $fila['tickets_bloqueados'];
            if ($actual) {
                // Desbloquear
                $pdo->prepare(
                    'UPDATE escuelas SET tickets_bloqueados=0, bloqueo_fecha=NULL, bloqueo_responsable=NULL WHERE id=:id'
                )->execute(['id'=>$id]);
                $ok = 'Escuela "' . $fila['nombre'] . '" habilitada para crear tickets nuevamente.';
            } else {
                // Bloquear
                $pdo->prepare(
                    'UPDATE escuelas SET tickets_bloqueados=1, bloqueo_fecha=NOW(), bloqueo_responsable=:resp WHERE id=:id'
                )->execute([
                    'resp' => $usuarioAdmin['nombre'] . ' ' . $usuarioAdmin['apellido'],
                    'id'   => $id,
                ]);
                $ok = 'Escuela "' . $fila['nombre'] . '" bloqueada: ya no puede crear tickets nuevos.';
            }
        }
    }
}

$editId = (int) ($_GET['editar'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM escuelas WHERE id=:id');
    $stmt->execute(['id'=>$editId]);
    $editando = $stmt->fetch();
}

$escuelas = $pdo->query(
    "SELECT e.*, te.nombre AS tipo_nombre,
            (SELECT COUNT(*) FROM tickets t WHERE t.escuela_id = e.id) AS total_tickets,
            (SELECT COUNT(*) FROM usuarios u WHERE u.escuela_id = e.id AND u.activo = 1) AS total_usuarios
     FROM escuelas e
     LEFT JOIN tipos_escuela te ON te.id = e.tipo_id
     ORDER BY e.activa DESC, e.nombre"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Escuelas del distrito</h1>
    <p>Administrá las instituciones que participan del proyecto de soporte técnico.</p>
</div>

<?php if ($ok):    ?><div class="alerta alerta-ok"><?= e($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="tarjeta">
    <div class="tarjeta-titulo"><?= $editando ? 'Editar escuela' : 'Nueva escuela' ?></div>
    <form method="post">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= $editando ? (int)$editando['id'] : 0 ?>">

        <div class="grid-3">
            <div>
                <label for="nombre">Nombre de la institución</label>
                <input type="text" id="nombre" name="nombre" required maxlength="150"
                       placeholder="Ej: Escuela Primaria N°2"
                       value="<?= e($editando['nombre'] ?? '') ?>">
            </div>
            <div>
                <label for="localidad">Localidad</label>
                <input type="text" id="localidad" name="localidad" required maxlength="100"
                       value="<?= e($editando['localidad'] ?? '') ?>">
            </div>
            <div>
                <label for="tipo_id">Tipo de escuela</label>
                <select id="tipo_id" name="tipo_id">
                    <option value="">Sin especificar</option>
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['id'] ?>"
                            <?= ($editando['tipo_id'] ?? null) == $tipo['id'] ? 'selected' : '' ?>>
                            <?= e($tipo['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div>
                <label for="direccion">Dirección (opcional)</label>
                <input type="text" id="direccion" name="direccion" maxlength="200"
                       value="<?= e($editando['direccion'] ?? '') ?>">
            </div>
            <div>
                <label for="telefono">Teléfono (opcional)</label>
                <input type="text" id="telefono" name="telefono" maxlength="50"
                       value="<?= e($editando['telefono'] ?? '') ?>">
            </div>
        </div>

        <div class="acciones-fila">
            <button type="submit"><?= $editando ? 'Guardar cambios' : 'Crear escuela' ?></button>
            <?php if ($editando): ?>
                <a href="admin_escuelas.php" class="boton boton-secundario">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="tarjeta">
    <div class="tarjeta-titulo">Listado (<?= count($escuelas) ?>)</div>
    <div class="tabla-wrap">
    <table class="tabla-escuelas">
        <thead>
            <tr><th>Institución</th><th>Actividad</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($escuelas as $esc): ?>
            <tr>
                <td>
                    <div class="negrita"><?= e($esc['nombre']) ?></div>
                    <div class="texto-3 texto-sm">
                        <?= e($esc['tipo_nombre'] ?? 'Sin tipo') ?> · <?= e($esc['localidad']) ?>
                    </div>
                    <?php if ($esc['direccion']): ?><div class="texto-3 texto-sm"><?= e($esc['direccion']) ?></div><?php endif; ?>
                </td>
                <td class="texto-2 texto-sm">
                    <?= (int)$esc['total_tickets'] ?> ticket<?= (int)$esc['total_tickets'] === 1 ? '' : 's' ?><br>
                    <?= (int)$esc['total_usuarios'] ?> usuario<?= (int)$esc['total_usuarios'] === 1 ? '' : 's' ?>
                </td>
                <td>
                    <span class="etiqueta <?= $esc['activa'] ? 'estado-resuelto' : 'estado-cancelado' ?>"><?= $esc['activa'] ? 'Activa' : 'Inactiva' ?></span>
                    <br>
                    <span class="etiqueta <?= $esc['tickets_bloqueados'] ? 'estado-cancelado' : 'estado-resuelto' ?>" style="margin-top:0.3rem;">
                        <?= $esc['tickets_bloqueados'] ? '🔒 Bloqueada' : '🟢 Tickets ok' ?>
                    </span>
                    <?php if ($esc['tickets_bloqueados'] && $esc['bloqueo_fecha']): ?>
                        <div class="texto-3 texto-sm" style="margin-top:2px;">
                            desde <?= date('d/m/Y', strtotime($esc['bloqueo_fecha'])) ?>
                            <?php if ($esc['bloqueo_responsable']): ?>· <?= e($esc['bloqueo_responsable']) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="acciones-tabla">
                        <a href="admin_escuelas.php?editar=<?= (int)$esc['id'] ?>" class="boton boton-secundario boton-sm">Editar</a>
                        <form method="post" onsubmit="return confirm('¿Confirmar?')">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$esc['id'] ?>">
                            <button type="submit" class="boton-sm <?= $esc['activa'] ? 'boton-peligro' : 'boton boton-secundario' ?>">
                                <?= $esc['activa'] ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </form>
                        <form method="post" onsubmit="return confirm('<?= $esc['tickets_bloqueados'] ? '¿Habilitar la creación de tickets para esta escuela?' : '¿Bloquear la creación de tickets para esta escuela? Los solicitantes de esta institución no podrán crear tickets nuevos hasta que la habilités de nuevo.' ?>')">
                            <input type="hidden" name="accion" value="toggle_bloqueo_tickets">
                            <input type="hidden" name="id" value="<?= (int)$esc['id'] ?>">
                            <button type="submit" class="boton-sm <?= $esc['tickets_bloqueados'] ? 'boton boton-secundario' : 'boton-peligro' ?>">
                                <?= $esc['tickets_bloqueados'] ? 'Habilitar tickets' : 'Bloquear tickets' ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
