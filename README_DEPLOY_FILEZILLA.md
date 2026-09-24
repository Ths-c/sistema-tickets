# Despliegue en hosting compartido con FileZilla

Guía para subir `sistema-tickets/` a un hosting Apache + PHP + MySQL
(cPanel típico). Tiempo estimado: 20–30 min.

## 0. Requisitos del hosting

- PHP **8.0 o superior** (el código usa `match`, `str_contains`,
  `str_starts_with`). Revisalo en cPanel → "Versión PHP".
- Extensiones: `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `session`
  (casi todos los hostings las traen activas).
- 1 base MySQL libre + acceso a phpMyAdmin.

## 1. Configurar FileZilla (importante)

1. **Ver archivos ocultos:** en FileZilla → Servidor → *Forzar mostrar
   archivos ocultos*. Sin esto **no se suben los `.htaccess`** y las
   carpetas `config/`, `backups/`, `uploads/` quedan expuestas.
2. **Modo de transferencia:** Edición → Ajustes → Transferencias →
   Tipos de archivo → *Automático* (o *Binario*). El modo ASCII
   **corrompe** imágenes y PDF de `uploads/`.
3. Subí siempre manteniendo minúsculas y nombres tal cual: el servidor
   Linux distingue `Admin` de `admin`.

## 2. Qué subir y qué NO subir

Destino: todo el contenido de `sistema-tickets/` dentro de
`public_html/` (o `public_html/sistema-tickets/` si preferís
subcarpeta; en ese caso la URL será
`tudominio.com/sistema-tickets/public/`).

| Subir | NO subir |
|---|---|
| `.htaccess` (raíz, `public/`, `config/`, `includes/`, `lib/`, `sql/`, `uploads/`, `changelog/`, `css/`) | `backups/backup_*.sql` (datos locales) |
| `public/`, `config/conexion.php`, `includes/`, `lib/`, `css/`, `sql/` | `backups/historial.json` (local) |
| `changelog/*.pdf` (el último se ofrece en Menú) | `uploads/adjuntos/ticket*.*` (fotos locales) |
| Carpetas `backups/` y `uploads/adjuntos/` **vacías** | `sql/LISTADO USUARIOS*.csv` (datos personales) |
| `config/conexion.local.php` (**lo creás vos**, ver paso 4) | `.git/`, `.writetest_*`, `Thumbs.db`, `.DS_Store` |

> Nota: en tu PC la carpeta local `backups/` pertenece al usuario del
> servidor (daemon) y puede que no veas ahí su `.htaccess`/`index.html`.
> No importa: el `.htaccess` de la **raíz** ya bloquea `backups/` por
> web. Igual, como refuerzo, creá en el servidor (paso 5) el archivo
> `backups/.htaccess` con el contenido de abajo.

## 3. Base de datos

1. cPanel → "Bases de datos MySQL" → creá base + usuario + clave, y
   vinculá el usuario a la base (todos los privilegios).
2. phpMyAdmin → clic en **tu base vacía** → "Importar":
   - `sql/schema.sql` (**antes** comentá/borrá sus líneas
     `CREATE DATABASE` y `USE`, que en hosting dan error de permiso).
   - Después, en orden alfabético, cada `sql/migracion_*.sql`.
3. Verificá que existan las tablas `usuarios`, `tickets`, `escuelas`, etc.

## 4. Credenciales (sin editar código)

1. Copiá `config/conexion.local.ejemplo.php` → `config/conexion.local.php`.
2. Completá `DB_HOST` (casi siempre `localhost`), `DB_NAME`, `DB_USER`,
   `DB_PASS` con los datos del paso 3.
3. Subí ese archivo a `config/`. Manda sobre todo lo demás y **no está
   en git**, así que futuras actualizaciones no lo pisan.

## 5. Permisos y protección final (en el servidor)

1. Carpetas: `755` (`backups/`, `uploads/`, `uploads/adjuntos/`:
   si falla la subida, probá `775`). Archivos: `644`.
   En FileZilla: clic derecho → "Permisos de archivo".
2. Verificá que existan (si falta alguno, crealo desde el panel):
   - `backups/.htaccess` y `uploads/adjuntos/.htaccess`
3. Contenido de `backups/.htaccess` (por si hay que crearlo a mano):
   ```apache
   Options -Indexes
   ServerSignature Off
   <IfModule mod_authz_core.c>
       Require all denied
   </IfModule>
   <IfModule !mod_authz_core.c>
       Order deny,allow
       Deny from all
   </IfModule>
   ```

## 6. Probar

- [ ] `tudominio.com/public/` redirige al login (o al dashboard).
- [ ] Login con un usuario real (los de tu PC no existen en el hosting:
      crealos desde `admin_usuarios.php` o re-importalos).
- [ ] Crear un ticket con 1 dispositivo funciona.
- [ ] Subir una foto a un ticket funciona (si falla: permisos paso 5).
- [ ] `tudominio.com/config/conexion.php` da **403/404** (no debe verse).
- [ ] `tudominio.com/backups/` da **403/404** (no debe listarse).
- [ ] `tudominio.com/uploads/adjuntos/` da **403/404**; los adjuntos
      solo abren vía `adjunto_ver.php` con sesión iniciada.

## 7. Limitaciones conocidas en hosting compartido

- **Botón "Generar backup"**: necesita `exec()` + `mysqldump`, que la
  mayoría de los compartidos bloquea. Si falla, el sistema lo avisa y
  todo lo demás sigue funcionando; generá el respaldo desde
  phpMyAdmin → Exportar.
- **Límite de subida**: si el hosting tiene `upload_max_filesize` menor
  a 8 MB, archivos grandes serán rechazados (límite de la app:
  `ticket_detalle.php`).

## 8. Actualizar después (sin romper nada)

Subí todo de nuevo **excepto**: `config/conexion.local.php`,
`backups/` y `uploads/adjuntos/` del servidor (ahí están los datos
reales). Tus credenciales y archivos quedan intactos.
