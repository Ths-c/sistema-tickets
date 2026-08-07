<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo = obtenerConexion();
$tituloPagina = 'Tipos de escuela';
$ok = null;
$error = null;
$editando = null;

// ── Acción: guardar (crear o editar) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion      = $_POST['accion'] ?? '';
    $nombre      = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $id          = (int) ($_POST['id'] ?? 0);

    if ($accion === 'guardar') {
        if ($nombre === '') {
            $error = 'El nombre es obligatorio.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE tipos_escuela SET nombre=:n, descripcion=:d WHERE id=:id')
                        ->execute(['n' => $nombre, 'd' => $descripcion ?: null, 'id' => $id]);
                    $ok = 'Tipo actualizado.';
                } else {
                    $pdo->prepare('INSERT INTO tipos_escuela (nombre, descripcion) VALUES (:n, :d)')
                        ->execute(['n' => $nombre, 'd' => $descripcion ?: null]);
                    $ok = 'Tipo creado.';
                }
            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(), 'Duplicate') ? 'Ya existe un tipo con ese nombre.' : 'No se pudo guardar.';
            }
        }
    }

    if ($accion === 'toggle') {
        $tipo = $pdo->prepare('SELECT activo FROM tipos_escuela WHERE id=:id');
        $tipo->execute(['id' => $id]);
        $actual = (int) $tipo->fetchColumn();
        $pdo->prepare('UPDATE tipos_escuela SET activo=:a WHERE id=:id')
            ->execute(['a' => $actual ? 0 : 1, 'id' => $id]);
        $ok = $actual ? 'Tipo desactivado.' : 'Tipo activado.';
    }
}

// ── Cargar para editar ───────────────────────────────────────
$editId = (int) ($_GET['editar'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tipos_escuela WHERE id=:id');
    $stmt->execute(['id' => $editId]);
    $editando = $stmt->fetch();
}

$tipos = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM escuelas e WHERE e.tipo_id = t.id) AS total_escuelas FROM tipos_escuela t ORDER BY t.nombre')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Tipos de escuela</h1>
    <p>Administrá los tipos que se pueden asignar a cada institución del distrito.</p>
</div>

<?php if ($ok):    ?><div class="alerta alerta-ok"><?= e($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid-2" style="align-items:start;">

    <!-- Formulario crear / editar -->
    <div class="tarjeta">
        <div class="tarjeta-titulo"><?= $editando ? 'Editar tipo' : 'Nuevo tipo' ?></div>
        <form method="post">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= $editando ? (int)$editando['id'] : 0 ?>">

            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" required maxlength="80"
                   value="<?= e($editando['nombre'] ?? $_POST['nombre'] ?? '') ?>">

            <label for="descripcion">Descripción (opcional)</label>
            <textarea id="descripcion" name="descripcion" style="min-height:70px;"><?= e($editando['descripcion'] ?? $_POST['descripcion'] ?? '') ?></textarea>

            <div class="acciones-fila">
                <button type="submit"><?= $editando ? 'Guardar cambios' : 'Crear tipo' ?></button>
                <?php if ($editando): ?>
                    <a href="admin_tipos_escuela.php" class="boton boton-secundario">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Listado -->
    <div class="tarjeta">
        <div class="tarjeta-titulo">Listado</div>
        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr><th>Nombre</th><th>Descripción</th><th>Escuelas</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tipos as $t): ?>
                    <tr>
                        <td class="negrita"><?= e($t['nombre']) ?></td>
                        <td class="texto-2"><?= e($t['descripcion'] ?? '—') ?></td>
                        <td class="texto-2"><?= (int)$t['total_escuelas'] ?></td>
                        <td>
                            <?php if ($t['activo']): ?>
                                <span class="etiqueta estado-resuelto">Activo</span>
                            <?php else: ?>
                                <span class="etiqueta estado-cancelado">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:0.4rem;">
                                <a href="admin_tipos_escuela.php?editar=<?= (int)$t['id'] ?>" class="boton boton-secundario boton-sm">Editar</a>
                                <form method="post" style="margin:0;" onsubmit="return confirm('¿Confirmar?')">
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id"     value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="boton-sm <?= $t['activo'] ? 'boton-peligro' : 'boton boton-secundario' ?>" style="margin:0;">
                                        <?= $t['activo'] ? 'Desactivar' : 'Activar' ?>
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

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
