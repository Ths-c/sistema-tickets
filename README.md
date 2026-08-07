#gabriel Sistema de tickets — Soporte técnico distrital (ETMH)

Sistema de gestión y trazabilidad de tickets para el proyecto de tecnología
educativa en el que alumnos de 4° a 7° año de la Escuela Técnica de Monte
Hermoso brindan soporte técnico al resto de las escuelas del distrito.

## Qué incluye

- **Ingreso por DNI**: cada usuario entra al sistema con su DNI (sin
  puntos ni espacios) y una contraseña, en vez de email. El sistema
  acepta que lo tipeen con puntos igual (los ignora automáticamente).
- **Roles**: administrador, coordinador del proyecto, técnico (alumno ETMH),
  solicitante (docente/directivo de cualquier escuela del distrito).
- **El administrador tiene acceso total**: puede asignar o reasignar técnico
  en cualquier momento, actuar como técnico (marcar en proceso / resuelto),
  cerrar, reabrir o cancelar un ticket sin las restricciones normales de
  orden del flujo, y además cuenta con un **panel para forzar el estado a
  cualquier valor** directamente. Todos estos cambios quedan igual
  registrados en el historial de trazabilidad, identificando que fueron
  hechos por el admin.
  **Única excepción que NO tiene ni siquiera el admin: las 4 actas
  obligatorias de entrega/recepción de equipo** (ver punto siguiente) — ni
  el flujo normal ni el panel de forzar estado permiten saltearlas.
- **Control de acceso a la creación de tickets** (pantalla `admin_bloqueo.php`
  y `admin_escuelas.php`), con dos niveles independientes:
  - **Global**: el admin puede bloquear la creación de tickets para todo el
    distrito con un clic, con mensaje personalizable para los usuarios.
  - **Por escuela**: el admin puede bloquear la creación de tickets para una
    institución puntual (por ejemplo, si ya tiene demasiada carga o mientras
    se resuelve algo administrativamente), sin afectar al resto de las
    escuelas. Queda registrado quién y cuándo la bloqueó.
  - En ambos casos, el admin y el coordinador pueden seguir creando tickets
    igual (ven un aviso), pero los solicitantes quedan bloqueados.
- **Límite de tickets abiertos por escuela**: cada escuela puede tener como
  máximo una cantidad configurable de tickets abiertos (no cerrados ni
  cancelados) al mismo tiempo — **5 por defecto**. Al llegar al límite, los
  solicitantes de esa escuela no pueden crear tickets nuevos hasta que se
  resuelva/cierre o cancele alguno de los existentes. El número es
  configurable desde `admin_bloqueo.php` (no hace falta tocar código).
- **Filtro avanzado y cancelación masiva (solo administrador)**: en
  `ticket_lista.php`, el admin puede filtrar por escuela, rango de fechas
  y estado, y cancelar de una sola vez todos los tickets que coincidan con
  el filtro aplicado (con confirmación previa mostrando la cantidad exacta).
  Cada cancelación queda registrada individualmente en el historial de cada
  ticket, igual que si se cancelara uno por uno.
- **Bandeja de mensajes** (`mensajes.php`), para solicitante y coordinador:
  los comentarios nuevos en los tickets aparecen en esta bandeja separada
  (con su propio contador en el menú lateral), mientras que la campana 🔔
  sigue mostrando únicamente los cambios de estado de los tickets. Para
  admin y técnico no cambia nada: siguen viendo todo junto en la campana.
- **Ciclo de vida del ticket**: nuevo → asignado → en proceso → resuelto →
  cerrado (con posibilidad de reabrir o cancelar).
- **Trazabilidad real**: cada cambio de estado queda registrado en
  `historial_estados` con fecha, usuario y motivo. Nada se sobrescribe.
- **Adjuntos**: cualquiera con acceso al ticket puede subir capturas de
  pantalla, fotos o PDFs (hasta 8 MB). Se sirven a través de un script que
  verifica permisos antes de mostrarlos, así nadie puede ver el adjunto de
  un ticket ajeno solo por tener el link.
- **Constancia de entrega y recepción de equipo, obligatoria en 4 etapas**
  (`constancia_equipo.php`), integrada al flujo del ticket: no se puede
  avanzar de un estado al siguiente sin completar el acta correspondiente.
  1. **Entrega** (Escuela → Proyecto) — obligatoria para poder **asignar un
     técnico**.
  2. **Asignación** (Proyecto → Técnico) — obligatoria para pasar a
     **"en proceso"**.
  3. **Resolución** (trabajo realizado) — obligatoria para marcar el ticket
     como **resuelto**.
  4. **Devolución** (Proyecto → Escuela originante) — obligatoria para
     **cerrar** el ticket.

  Cada etapa muestra su estado (✓ completa / pendiente) tanto en la
  constancia como en la pantalla del ticket, con un enlace directo a la
  sección que falta completar. **Es obligatoria para todos los roles, sin
  excepción — incluido el administrador**: ni el flujo normal ni el panel
  de "forzar estado" del admin permiten avanzar el ticket sin la acta
  correspondiente completa. Un botón genera además una vista de impresión
  lista para **"Guardar como PDF"** desde el navegador, y hay una versión
  con PDF nativo (`constancia_pdf.php`, vía FPDF) que no depende de eso.
- **Comentarios** públicos (los ve el solicitante) e internos (solo el
  equipo de soporte).
- **Evaluación de satisfacción** al cerrar el ticket.
- **Reportes**: tickets por escuela, desempeño por técnico (cantidad
  resuelta, reaperturas, tiempo promedio de resolución), tickets por
  categoría y satisfacción promedio.

## 1. Instalación en localhost (Windows/Mac con XAMPP)

1. Instalar [XAMPP](https://www.apachefriends.org/) (incluye Apache, PHP y MySQL).
2. Copiar toda la carpeta `sistema-tickets` dentro de `htdocs` (en XAMPP suele
   estar en `C:\xampp\htdocs\` o `/Applications/XAMPP/htdocs/`).
3. Abrir XAMPP Control Panel y arrancar **Apache** y **MySQL**.
4. Abrir **phpMyAdmin** (`http://localhost/phpmyadmin`), pestaña **SQL**,
   pegar el contenido de `sql/schema.sql` y ejecutar. Esto crea la base
   `tickets_distrital` con las tablas y 4 usuarios de prueba.
5. Ir a `http://localhost/sistema-tickets/public/login.php`

### Usuarios de prueba (contraseña para todos: `cambiar123`)

| Rol | DNI |
|---|---|
| Administrador | 30111222 |
| Coordinador | 28222333 |
| Técnico (alumno) | 45333444 |
| Solicitante (docente) | 32444555 |

**Importante**: cambiar estas contraseñas (o borrar estos usuarios y crear
los reales desde la pantalla de administración) antes de usar el sistema
con datos reales.

## 2. Configuración de la conexión a la base

Por defecto el sistema usa estos valores (en `config/conexion.php`):
host `localhost`, base `tickets_distrital`, usuario `root`, sin contraseña
— el default típico de XAMPP/MAMP.

Si tu instalación de MySQL tiene otro usuario/contraseña, podés:

- Editar directamente las constantes en `config/conexion.php`, o
- Definir variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
  (recomendado para cuando subas el sistema a internet, así no hay
  credenciales escritas en el código).

## 3. Subir el sistema a internet (cuando estén listos)

Dos caminos típicos, ambos compatibles con este código tal cual está:

**A. Hosting compartido (cPanel) — el más común y económico en Argentina**
1. Crear la base de datos MySQL desde cPanel y un usuario con permisos sobre ella.
2. Importar `sql/schema.sql` desde phpMyAdmin del hosting.
3. Subir el contenido de `public/`, `config/`, `includes/`, `css/` y `uploads/`
   por FTP, dentro de la carpeta pública del dominio (`public_html` o similar).
4. Editar `config/conexion.php` con los datos reales que te da el hosting.

**B. Plataformas con despliegue gratuito/económico (Render, Railway)**
Soportan PHP + MySQL gestionado. El proceso es similar: crear la base,
cargar `schema.sql`, conectar el repositorio o subir el código, y configurar
las variables de entorno `DB_HOST/DB_NAME/DB_USER/DB_PASS` desde el panel
del servicio (no hace falta tocar el código).

### Antes de salir a producción, recomendado:

- Servir todo bajo **HTTPS** (los hostings y Render/Railway lo dan gratis).
- Cambiar las contraseñas de los usuarios semilla o eliminarlos.
- Hacer un backup periódico de la base (`mysqldump`).
- Si vas a permitir adjuntar archivos (capturas de pantalla), limitar tipo
  y tamaño de archivo — la tabla `adjuntos` ya está preparada en el esquema,
  pero la pantalla para subir archivos no está implementada todavía; se
  puede agregar como siguiente paso.

## 4. Estructura del proyecto

```
sistema-tickets/
├── config/
│   ├── conexion.php      # conexión PDO a MySQL
│   └── sesion.php        # login, roles, helpers (historial, escape HTML)
├── includes/
│   ├── header.php        # navegación según rol
│   └── footer.php
├── public/                # punto de entrada del sitio (Document Root)
│   ├── login.php / logout.php
│   ├── dashboard.php
│   ├── ticket_nuevo.php   # alta de ticket (solicitante)
│   ├── ticket_lista.php   # listado con filtros
│   ├── ticket_detalle.php # detalle, cambios de estado, comentarios, historial, adjuntos
│   ├── adjunto_ver.php    # sirve los archivos adjuntos verificando permisos
│   ├── constancia_equipo.php # acta de entrega/recepción de equipo + vista de impresión (PDF)
│   ├── admin_usuarios.php
│   ├── admin_escuelas.php
│   └── reportes.php
├── css/estilo.css
├── sql/schema.sql         # esquema completo + datos semilla
└── uploads/adjuntos/      # carpeta para futuros archivos adjuntos
```

## 5. Próximos pasos posibles

- Notificaciones por email cuando cambia el estado de un ticket.
- Asignación automática por categoría/escuela en vez de manual.
- Exportar reportes a Excel/PDF.
- Firma digital simple (dibujada con el dedo/mouse) en la constancia de
  entrega y recepción, en vez de firma manuscrita sobre el papel impreso.
- Un listado histórico de todas las actas de equipo generadas, no solo
  acceder a ellas desde cada ticket individual.
