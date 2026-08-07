<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo = obtenerConexion();
$tituloPagina = 'Categorías de tickets';
$ok = null;
$error = null;
$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion      = $_POST['accion'] ?? '';
    $id          = (int) ($_POST['id'] ?? 0);
    $nombre      = trim($_POST['nombre']      ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($accion === 'guardar') {
        if ($nombre === '') {
            $error = 'El nombre es obligatorio.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE categorias SET nombre=:n, descripcion=:d WHERE id=:id')
                        ->execute(['n'=>$nombre,'d'=>$descripcion?:null,'id'=>$id]);
                    $ok = 'Categoría actualizada.';
                } else {
                    $pdo->prepare('INSERT INTO categorias (nombre, descripcion) VALUES (:n, :d)')
                        ->execute(['n'=>$nombre,'d'=>$descripcion?:null]);
                    $ok = 'Categoría creada.';
                }
            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(), 'Duplicate') ? 'Ya existe una categoría con ese nombre.' : 'No se pudo guardar.';
            }
        }
    }

    if ($accion === 'toggle') {
        $row = $pdo->prepare('SELECT activa FROM categorias WHERE id=:id');
        $row->execute(['id'=>$id]);
        $actual = (int) $row->fetchColumn();
        $pdo->prepare('UPDATE categorias SET activa=:a WHERE id=:id')->execute(['a'=>$actual?0:1,'id'=>$id]);
        $ok = $actual ? 'Categoría desactivada.' : 'Categoría activada.';
    }
}

$editId = (int) ($_GET['editar'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM categorias WHERE id=:id');
    $stmt->execute(['id'=>$editId]);
    $editando = $stmt->fetch();
}

$categorias = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM tickets t WHERE t.categoria_id = c.id) AS total_tickets
     FROM categorias c ORDER BY c.activa DESC, c.nombre"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="pagina-header">
    <h1>Categorías de tickets</h1>
    <p>Definí los tipos de problema que los solicitantes pueden reportar.</p>
</div>

<?php if ($ok):    ?><div class="alerta alerta-ok"><?= e($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid-2" style="align-items:start;">

    <div class="tarjeta">
        <div class="tarjeta-titulo"><?= $editando ? 'Editar categoría' : 'Nueva categoría' ?></div>
        <form method="post">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= $editando ? (int)$editando['id'] : 0 ?>">

            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" required maxlength="80"
                   placeholder="Ej: Hardware, Software, Conectividad…"
                   value="<?= e($editando['nombre'] ?? '') ?>">

            <label for="descripcion">Descripción (opcional)</label>
            <textarea id="descripcion" name="descripcion" style="min-height:70px;"><?= e($editando['descripcion'] ?? '') ?></textarea>

            <div class="acciones-fila">
                <button type="submit"><?= $editando ? 'Guardar cambios' : 'Crear categoría' ?></button>
                <?php if ($editando): ?>
                    <a href="admin_categorias.php" class="boton boton-secundario">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="tarjeta">
        <div class="tarjeta-titulo">Listado (<?= count($categorias) ?>)</div>
        <div class="tabla-wrap">
        <table>
            <thead>
                <tr><th>Nombre</th><th>Descripción</th><th>Tickets</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($categorias as $cat): ?>
                <tr>
                    <td class="negrita"><?= e($cat['nombre']) ?></td>
                    <td class="texto-2"><?= e($cat['descripcion'] ?? '—') ?></td>
                    <td class="texto-2"><?= (int)$cat['total_tickets'] ?></td>
                    <td>
                        <span class="etiqueta <?= $cat['activa'] ? 'estado-resuelto' : 'estado-cancelado' ?>">
                            <?= $cat['activa'] ? 'Activa' : 'Inactiva' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex; gap:0.4rem;">
                            <a href="admin_categorias.php?editar=<?= (int)$cat['id'] ?>" class="boton boton-secundario boton-sm">Editar</a>
                            <form method="post" style="margin:0;" onsubmit="return confirm('¿Confirmar?')">
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                <button type="submit" class="boton-sm <?= $cat['activa'] ? 'boton-peligro' : 'boton boton-secundario' ?>" style="margin:0;">
                                    <?= $cat['activa'] ? 'Desactivar' : 'Activar' ?>
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
