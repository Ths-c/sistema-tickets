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

    $rolesValidos = ['admin', 'coordinador', 'tecnico', 'solicitante', 'lector'];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reset_password') {
    $uid = (int) ($_POST['usuario_id'] ?? 0);
    $nueva = $_POST['nueva_password'] ?? '';
    $confirmar = $_POST['confirmar_password'] ?? '';

    if ($uid <= 0) {
        $error = 'Usuario no válido.';
    } elseif (strlen($nueva) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirmar) {
        $error = 'Las contraseñas no coinciden. Volvé a escribirlas.';
    } else {
        $stmtDest = $pdo->prepare('SELECT id, nombre, apellido FROM usuarios WHERE id = :id');
        $stmtDest->execute(['id' => $uid]);
        $dest = $stmtDest->fetch();
        if (!$dest) {
            $error = 'Usuario no encontrado.';
        } else {
            try {
                $pdo->prepare('UPDATE usuarios SET password_hash = :h WHERE id = :id')
                    ->execute(['h' => password_hash($nueva, PASSWORD_BCRYPT), 'id' => $uid]);
                $mensajeOk = 'Contraseña actualizada para ' . $dest['nombre'] . ' ' . $dest['apellido']
                    . '. Pasale la nueva contraseña por un canal seguro.';
            } catch (PDOException $ex) {
                $error = 'No se pudo actualizar la contraseña.';
            }
        }
    }
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
                    <option value="">Seleccioná un rol</option>

                    <?php foreach ($rolesValidos as $rol): ?>
                        <option value="<?= e($rol) ?>">
                            <?= e(ucfirst($rol)) ?>
                        </option>
                    <?php endforeach; ?>
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
                    <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                        <button type="button"
                            class="btn-reset-password"
                            style="margin:0; padding:0.3rem 0.7rem; font-size:0.82rem; white-space:nowrap;"
                            data-id="<?= (int) $u['id'] ?>"
                            data-nombre="<?= e($u['nombre'] . ' ' . $u['apellido']) ?>">
                            Restablecer contraseña
                        </button>
                        <?php if ($u['activo']): ?>
                        <form method="post" onsubmit="return confirm('¿Desactivar este usuario?')" style="margin:0;">
                            <input type="hidden" name="accion" value="desactivar">
                            <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" style="margin:0; padding:0.3rem 0.7rem; font-size:0.82rem; background:var(--rojo);">Desactivar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="resetOverlay" class="reset-overlay" hidden>
    <div class="reset-modal" role="dialog" aria-modal="true" aria-labelledby="resetTitulo">
        <form method="post" id="formReset">
            <input type="hidden" name="accion" value="reset_password">
            <input type="hidden" name="usuario_id" id="resetUsuarioId" value="">
            <div class="reset-modal-header">
                <div class="reset-modal-icono">🔑</div>
                <div class="reset-modal-titulos">
                    <h2 id="resetTitulo">Restablecer contraseña</h2>
                    <p>Se reemplazará la clave actual del usuario.</p>
                </div>
                <button type="button" id="btnCerrarReset" class="reset-modal-x" aria-label="Cerrar">✕</button>
            </div>
            <div class="reset-modal-usuario">
                <span class="reset-modal-avatar" id="resetAvatar">—</span>
                <div>
                    <div class="reset-modal-nombre" id="resetNombre">—</div>
                    <div class="reset-modal-ayuda">Pasale la nueva clave por un canal seguro.</div>
                </div>
            </div>
            <label for="nueva_password">Nueva contraseña</label>
            <input type="password" id="nueva_password" name="nueva_password" required minlength="8"
                   placeholder="Mínimo 8 caracteres" autocomplete="new-password">
            <label for="confirmar_password">Repetir nueva contraseña</label>
            <input type="password" id="confirmar_password" name="confirmar_password" required minlength="8"
                   placeholder="Repetila para confirmar" autocomplete="new-password">
            <p id="resetCoincide" class="reset-modal-error">
                ⚠ Las contraseñas no coinciden.
            </p>
            <div class="reset-modal-acciones">
                <button type="button" id="btnCancelarReset" class="boton-secundario">Cancelar</button>
                <button type="submit">Guardar contraseña</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var overlay = document.getElementById('resetOverlay');
    var form = document.getElementById('formReset');
    var inputId = document.getElementById('resetUsuarioId');
    var spanNombre = document.getElementById('resetNombre');
    var spanAvatar = document.getElementById('resetAvatar');
    var nueva = document.getElementById('nueva_password');
    var confirmar = document.getElementById('confirmar_password');
    var aviso = document.getElementById('resetCoincide');
    var btnCancelar = document.getElementById('btnCancelarReset');
    var btnCerrar = document.getElementById('btnCerrarReset');

    function iniciales(nombre) {
        return nombre.trim().split(/\s+/).slice(0, 2).map(function(p) {
            return p.charAt(0).toUpperCase();
        }).join('') || '—';
    }

    function abrir(nombre, id) {
        inputId.value = id;
        spanNombre.textContent = nombre;
        spanAvatar.textContent = iniciales(nombre);
        form.reset();
        aviso.classList.remove('visible');
        nueva.classList.remove('input-error');
        confirmar.classList.remove('input-error');
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        nueva.focus();
    }

    function cerrar() {
        overlay.hidden = true;
        document.body.style.overflow = '';
        form.reset();
        aviso.classList.remove('visible');
        nueva.classList.remove('input-error');
        confirmar.classList.remove('input-error');
    }

    document.querySelectorAll('.btn-reset-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            abrir(btn.dataset.nombre || '—', btn.dataset.id);
        });
    });

    btnCancelar.addEventListener('click', cerrar);
    btnCerrar.addEventListener('click', cerrar);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) cerrar();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !overlay.hidden) cerrar();
    });

    [nueva, confirmar].forEach(function(el) {
        el.addEventListener('input', function() {
            var distinto = nueva.value && confirmar.value && nueva.value !== confirmar.value;
            aviso.classList.toggle('visible', !!distinto);
            nueva.classList.toggle('input-error', !!distinto);
            confirmar.classList.toggle('input-error', !!distinto);
        });
    });

    form.addEventListener('submit', function(e) {
        if (nueva.value.length < 8) {
            e.preventDefault();
            alert('La nueva contraseña debe tener al menos 8 caracteres.');
            return;
        }
        if (nueva.value !== confirmar.value) {
            e.preventDefault();
            aviso.classList.add('visible');
            nueva.classList.add('input-error');
            confirmar.classList.add('input-error');
            alert('Las contraseñas no coinciden.');
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
