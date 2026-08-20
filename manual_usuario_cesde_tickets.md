# Manual de Usuario: Sistema de Tickets CESDE

**CESDE - Centro de Soporte Digital Educativo**

Este manual explica cómo usar el sistema de gestión de tickets: registro, seguimiento, resolución y cierre de solicitudes o incidentes, según el rol de cada usuario.

---

## 1. Introducción

El sistema permite registrar, seguir y resolver solicitudes o incidentes de manera organizada. Cada solicitud se llama **ticket** y recorre un ciclo de vida con estados controlados (nuevo → asignado → en proceso → resuelto → cerrado), con un historial completo de cada cambio y la posibilidad de adjuntar archivos y dejar comentarios.

### 1.1 Roles y sus permisos

| Rol | ¿Qué puede hacer? |
|---|---|
| **Solicitante** | Crear tickets, seguir sus tickets, dejar comentarios, adjuntar archivos, evaluar la atención recibida. |
| **Técnico** | Ver los tickets asignados, trabajar en ellos, marcarlos como resueltos, completar el acta de equipo. |
| **Coordinador** | Crear y gestionar tickets, asignar técnicos, ajustar prioridades, ver estadísticas y reportes, responder mensajes. |
| **Administrador** | Todo lo anterior, más: gestión de usuarios, escuelas, categorías, control de acceso (bloqueos), y **backup de la base de datos**. |

---

## 2. Acceso al sistema

1. Abrí tu navegador web.
2. Ingresá a la URL proporcionada por el área de TI.
3. Ingresá tu **DNI** (sin puntos, ej: `30111222`).
4. Ingresá tu **contraseña**.
5. Hacé clic en **Ingresar**.

> **Nota:** Si olvidaste la contraseña, contactate con el administrador del sistema para que te la restablezca.

Para salir, usá la opción **Cerrar sesión** que está al pie del menú lateral.

---

## 3. El panel principal (Inicio)

El **Dashboard** muestra un resumen del estado del sistema:

- **Métricas generales**: tickets abiertos, sin asignar, en proceso, resueltos.
- **Tickets recientes**: los últimos tickets cargados, con su estado y prioridad.
- **Banner del sistema**: si el sistema está bloqueado o habilitado, se muestra un aviso en todas las pantallas.

Desde el menú lateral podés navegar a las distintas secciones según tu rol.

---

## 4. Creación de un ticket

Para abrir un nuevo ticket:

1. Iniciá sesión en el sistema.
2. En el menú lateral, seleccioná **Nuevo ticket**.
3. Completá los campos:
   - **Título breve**: descripción corta del problema (ej: "No anda el proyector del aula 3").
   - **Categoría**: elegí la adecuada (Hardware, Software, Redes y conectividad, Cuentas y accesos, Otro).
   - **Descripción**: detallá qué pasó, desde cuándo, y en qué equipo o aula.
4. Hacé clic en **Crear ticket**.
5. El sistema asigna automáticamente un número de ticket y notifica al equipo de soporte.

> **Importante:**
> - La **prioridad** no la elegís vos: se crea en "media" y la define el coordinador o administrador al revisar el ticket.
> - Tu escuela tiene un **límite de tickets abiertos simultáneos** (por defecto 5). Si lo alcanzaste, esperá a que se cierren o cancelen tickets existentes antes de crear uno nuevo.
> - Si el sistema está bloqueado (global o para tu escuela), no se pueden crear tickets. El sistema te lo indica con una pantalla de aviso.

---

## 5. Seguimiento de tickets

### 5.1 Lista de tickets

Desde **Tickets** podés ver y buscar tus tickets. Podés filtrar por:

- **Estado** (nuevo, asignado, en proceso, resuelto, cerrado, cancelado).
- **Escuela** (solo administradores y coordinadores).
- **Fecha**.
- **Búsqueda por texto** (título, número de ticket, etc.).

### 5.2 Detalle del ticket

Al abrir un ticket vas a encontrar:

- **Datos del ticket**: número, título, categoría, prioridad, estado, escuela, solicitante, técnico asignado.
- **Historial de estados**: cada cambio queda registrado con fecha, responsable y comentario.
- **Comentarios**: podés agregar comentarios **públicos** (los ve el solicitante) o **internos** (solo el equipo de soporte).
- **Archivos adjuntos**: podés adjuntar imágenes, PDFs y otros documentos relacionados con el problema.

### 5.3 Estados del ticket

| Estado | Significado |
|---|---|
| **Nuevo** | El ticket fue creado y espera revisión. |
| **Asignado** | Un coordinador/administrador lo asignó a un técnico. |
| **En proceso** | El técnico está trabajando en la resolución. |
| **Resuelto** | El problema fue resuelto; espera confirmación de cierre. |
| **Cerrado** | El ticket terminó y se puede evaluar la atención. |
| **Cancelado** | El ticket se dio de baja (solo coordinador/administrador). |
| **Reabierto** | Un ticket resuelto o cerrado vuelve a abrirse porque el problema reapareció. |

### 5.4 Notificaciones

- **Campana (🔔)**: recibís una notificación cuando un ticket cambia de estado. Se actualiza automáticamente cada 30 segundos.
- **Mensajes (✉)**: los solicitantes y coordinadores tienen una bandeja de mensajes para los comentarios de los tickets. Cada comentario nuevo genera un mensaje no leído.

---

## 6. Constancia de entrega y recepción de equipo

Para los tickets de **equipamiento** (notebooks, tablets, etc.) existe una **constancia con 4 etapas obligatorias**. Cada etapa debe completarse para poder avanzar el ticket:

| Etapa | Descripción |
|---|---|
| **1. Entrega** | La escuela entrega físicamente el equipo al proyecto. Se registra fecha, estado del equipo, quién entrega (escuela) y quién recibe (proyecto). |
| **2. Asignación** | Se asigna el técnico responsable, con fecha y observaciones. |
| **3. Resolución** | El técnico registra el trabajo realizado y el estado final del equipo. |
| **4. Devolución** | Se devuelve el equipo a la escuela, con estado, técnico y receptor. |

> **Importante:** sin completar la etapa de **entrega** no se puede asignar técnico; sin la **resolución** no se puede marcar el ticket como resuelto; y sin la **devolución** no se puede cerrar el ticket.

Al completar las etapas se puede generar la **constancia en PDF** (botón **Descargar constancia**), que sirve como documento oficial de todo el proceso.

---

## 7. Cierre de un ticket y evaluación

Un ticket se cierra cuando la solicitud fue satisfecha o el problema resuelto:

1. Abrí el ticket correspondiente.
2. Verificá que todas las acciones estén completas (incluidas las etapas del acta de equipo).
3. Seleccioná **Cerrar ticket**.
4. Agregá un comentario de cierre si es necesario.
5. Al cerrar, podés **evaluar la atención** con un puntaje de 1 a 5 y un comentario opcional.

---

## 8. Estadísticas y reportes (coordinador y administrador)

- **Estadísticas**: gráficos y KPIs de evolución de tickets por categoría, prioridad, escuela y técnico; tiempos de resolución y primera respuesta; porcentaje de resolución por escuela.
- **Reportes**: tablas resumen con los mismos indicadores y la posibilidad de exportarlos en PDF.
- **Satisfacción**: promedio de evaluaciones recibidas.

---

## 9. Panel de administración (solo administradores)

Desde la sección **Administración** del menú lateral:

| Sección | Función |
|---|---|
| **Usuarios** | Crear usuarios, asignarles rol y escuela, activarlos o desactivarlos. |
| **Escuelas** | Administrar las instituciones, activarlas/desactivarlas y **bloquear la creación de tickets por escuela** (con fecha y responsable). |
| **Tipos de escuela** | Administrar los tipos de institución (primaria, secundaria, técnica, especial, etc.). |
| **Categorías** | Administrar las categorías de tickets (activar/desactivar). |
| **Control de acceso** | Bloquear/habilitar el sistema completo, configurar los mensajes que ven todos los usuarios y el límite de tickets abiertos por escuela. |
| **Backup** | Generar y administrar copias de seguridad de la base de datos (ver sección 10). |

### 9.1 Control de acceso

- **Bloqueo global**: suspende la creación de tickets para todos los solicitantes. Se registra fecha y responsable.
- **Bloqueo por escuela**: suspende la creación de tickets solo para una institución puntual.
- **Límite de tickets abiertos por escuela**: máximo de tickets abiertos simultáneos (por defecto 5).
- **Mensajes personalizables**: textos que se muestran a todos los usuarios cuando el sistema está bloqueado o habilitado.

---

## 10. Backup de la base de datos (solo administradores)

La sección **Backup** permite proteger la información del sistema:

### 10.1 Generar un backup

1. Entrá a **Administración → Backup**.
2. Hacé clic en **Generar backup**.
3. El sistema exporta **toda la base de datos** (todas las tablas, registros, estructura, procedimientos y triggers) a un archivo `.sql` con fecha y hora.

> El archivo se guarda en el servidor y queda registrado en el historial con el nombre del administrador que lo generó.

### 10.2 Historial de backups

La sección muestra:

- **Métricas**: cantidad total de backups, espacio ocupado, fecha y tamaño del último respaldo.
- **Tabla de historial**: archivo, fecha de generación, administrador responsable y tamaño.
- **Descargar**: bajá el archivo `.sql` a tu computadora para guardarlo fuera del servidor.
- **Eliminar**: borrá backups antiguos para liberar espacio.

### 10.3 Recomendaciones

- Descargá periódicamente los backups a un medio externo (disco, nube).
- El backup de la base de datos **no incluye los archivos adjuntos** (subidos por los usuarios): para una protección completa, hacé también una copia de la carpeta de adjuntos del servidor.

---

## 11. Preguntas frecuentes

| Pregunta | Respuesta |
|---|---|
| ¿Cómo restablezco mi contraseña? | No hay auto-recuperación: contactá al administrador del sistema para que te la restablezca. |
| ¿Puedo adjuntar archivos al crear un ticket? | Sí, el sistema permite adjuntar documentos, imágenes y PDFs desde el detalle del ticket. |
| ¿Quién define la prioridad de mi ticket? | El coordinador o el administrador. Al crearlo, el ticket queda con prioridad media. |
| ¿Por qué no puedo crear un ticket nuevo? | Puede deberse a tres motivos: el sistema está bloqueado globalmente, tu escuela tiene bloqueada la creación de tickets, o alcanzaste el límite de tickets abiertos. |
| ¿Cuántos tickets puedo tener abiertos a la vez? | El límite lo configura el administrador (por defecto 5 por escuela). |
| ¿A quién debo dirigirme para urgencias? | Comunicate directamente con la coordinación del proyecto o con el administrador del sistema. |
| ¿Qué pasa si mi problema reaparece después del cierre? | El ticket se puede **reabrir** para continuar el seguimiento. |

---

## 12. Contacto y soporte

Para dudas adicionales sobre el sistema, contactate con el área de TI de CESDE o enviá un ticket de soporte técnico desde la plataforma.

---

*Última actualización: Agosto 2026*