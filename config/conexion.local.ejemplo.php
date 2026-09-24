<?php
/**
 * Credenciales del HOSTING (archivo local, NO se versiona en git).
 *
 * Cómo usarlo:
 *   1. Copiá este archivo como "conexion.local.php" en la misma carpeta
 *      (config/conexion.local.php).
 *   2. Completá los 4 valores con los que te dio el hosting (cPanel →
 *      "Bases de datos MySQL").
 *   3. Subilo por FileZilla a config/. Listo: este archivo manda sobre
 *      las variables de entorno y sobre los defaults de conexion.php,
 *      así que no tenés que editar ningún otro archivo.
 *
 * En tu XAMPP local NO necesitás este archivo (se usan los defaults).
 */

define('DB_HOST', 'localhost');      // casi siempre 'localhost' en hosting compartido
define('DB_NAME', 'usuario_tickets'); // ej: michofer_tickets
define('DB_USER', 'usuario_tickets'); // ej: michofer_admin
define('DB_PASS', 'TU_CLAVE_AQUI');
