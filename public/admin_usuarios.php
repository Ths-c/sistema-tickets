<?php
require_once __DIR__ . '/../config/sesion.php';
requerirRol(['admin']);

$pdo = obtenerConexion();
$tituloPagina = 'Usuarios';
$error = null;
$mensajeOk = null;

$escuelas = $pdo->query('SELECT id, nombre FROM escuelas ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni = normalizarDni($_POST['dni'] ?? '');
    $email = trim($_POST['email'] ?? '') ?: null;
    $rol = $_POST['rol'] ?? '';
    $escuelaId = (int) ($_POST['escuela_id'] ?? 0) ?: null;
    // admin y coordinador no pertenecen a una escuela puntual: se ignora
    // cualquier valor que llegue en el campo, aunque lo manipulen a mano.
    if (in_array($rol, ['admin', 'coordinador'], true)) {
        $escuelaId = null;
    }
    $anioCurso = trim($_POST['anio_curso'] ?? '') ?: null;
    $passwordInicial = $_POST['password'] ?? '';

    $rolesValidos = ['admin', 'coordinador', 'tecnico', 'solicitante'];

    if ($nombre === '' || $apellido === '' || !in_array($rol, $rolesValidos, true) || strlen($passwordInicial) < 8) {
        $error = 'Completá nombre, apellido y rol. La contraseña debe tener al menos 8 caracteres.';
    } elseif (strlen($dni) < 7 || strlen($dni) > 10) {
        $error = 'El DNI no parece válido. Ingresalo sin puntos ni espacios (solo números).';
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol, escuela_id, anio_curso)
                 VALUES (:n, :a, :dni, :e, :p, :r, :esc, :anio)"
            )->execute([
                'n' => $nombre, 'a' => $apellido, 'dni' => $dni, 'e' => $email,
                'p' => password_hash($passwordInicial, PASSWORD_BCRYPT),
                'r' => $rol, 'esc' => $escuelaId, 'anio' => $anioCurso,
            ]);
            $mensajeOk = 'Usuario creado. Pasale el DNI y la contraseña inicial por un canal seguro.';
        } catch (PDOException $ex) {
            $error = str_contains($ex->getMessage(), 'Duplicate') ? 'Ese DNI ya está registrado.' : 'No se pudo crear el usuario.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'desactivar') {
    $uid = (int) ($_POST['usuario_id'] ?? 0);
    $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id')->execute(['id' => $uid]);
    $mensajeOk = 'Usuario desactivado.';
}

$usuarios = $pdo->query(
    "SELECT u.*, e.nombre AS escuela_nombre FROM usuarios u
     LEFT JOIN escuelas e ON e.id = u.escuela_id
     ORDER BY u.activo DESC, u.rol, u.apellido"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Usuarios</h1>
<?php if ($mensajeOk): ?><div class="alerta alerta-ok"><?= e($mensajeOk) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>

<div class="tarjeta">
    <h2>Crear usuario</h2>
    <form method="post">
        <input type="hidden" name="accion" value="crear">
        <div class="grid-2">
            <div>
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" required>
            </div>
            <div>
                <label for="apellido">Apellido</label>
                <input type="text" id="apellido" name="apellido" required>
            </div>
        </div>
        <label for="dni">DNI</label>
        <input type="text" id="dni" name="dni" required inputmode="numeric" placeholder="Sin puntos, ej: 30111222">

        <label for="email">Email (opcional)</label>
        <input type="email" id="email" name="email">

        <div class="grid-2">
            <div>
                <label for="rol">Rol</label>
                <select id="rol" name="rol" required onchange="
                    document.getElementById('campo_anio').style.display = this.value === 'tecnico' ? 'block' : 'none';
                    document.getElementById('campo_escuela').style.display = (this.value === 'admin' || this.value === 'coordinador') ? 'none' : 'block';
                ">
                    <option value="solicitante">Solicitante (docente/directivo)</option>
                    <option value="tecnico">Técnico (alumno ETMH)</option>
                    <option value="coordinador">Coordinador</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
            <div id="campo_escuela">
                <label for="escuela_id">Escuela</label>
                <select id="escuela_id" name="escuela_id">
                    <option value="">—</option>
                    <?php foreach ($escuelas as $esc): ?>
                        <option value="<?= (int) $esc['id'] ?>"><?= e($esc['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="campo_anio" style="display:none;">
            <label for="anio_curso">Año que cursa (solo técnicos)</label>
            <select id="anio_curso" name="anio_curso">
                <option value="4to">4to año</option>
                <option value="5to">5to año</option>
                <option value="6to">6to año</option>
                <option value="7mo">7mo año</option>
            </select>
        </div>

        <label for="password">Contraseña inicial</label>
        <input type="password" id="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres">

        <button type="submit">Crear usuario</button>
    </form>
</div>

<div class="tarjeta">
    <h2>Listado</h2>
    <table>
        <thead><tr><th>Nombre</th><th>DNI</th><th>Email</th><th>Rol</th><th>Escuela</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= e($u['nombre'] . ' ' . $u['apellido']) ?><?= $u['anio_curso'] ? ' (' . e($u['anio_curso']) . ')' : '' ?></td>
                <td><?= e($u['dni']) ?></td>
                <td><?= e($u['email'] ?? '—') ?></td>
                <td><?= e($u['rol']) ?></td>
                <td><?= e($u['escuela_nombre'] ?? '—') ?></td>
                <td><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></td>
                <td>
                    <?php if ($u['activo']): ?>
                    <form method="post" onsubmit="return confirm('¿Desactivar este usuario?')">
                        <input type="hidden" name="accion" value="desactivar">
                        <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" style="margin:0; padding:0.3rem 0.7rem; font-size:0.82rem; background:var(--rojo);">Desactivar</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
