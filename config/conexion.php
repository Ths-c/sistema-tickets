<?php
// Polyfills PHP 7.4 (str_contains/str_starts_with/str_ends_with).
// En PHP 8+ no hacen nada: se usan las nativas.
require_once __DIR__ . '/../lib/compat74.php';

/**
 * Conexión a la base de datos.
 *
 * Prioridad de las credenciales (gana la primera que exista):
 *   1. Archivo config/conexion.local.php — RECOMENDADO en hosting compartido:
 *      lo creás una vez por FileZilla/panel con los datos del hosting y no
 *      tenés que tocar nunca este archivo (ver conexion.local.ejemplo.php).
 *      Ese archivo NO está en git (ver .gitignore).
 *   2. Variables de entorno DB_HOST, DB_NAME, DB_USER, DB_PASS
 *      (para VPS/Docker/hostings que las soportan).
 *   3. Valores de abajo (tu XAMPP local).
 */

// 1) Override local por archivo (ideal para FTP: sobrevive actualizaciones).
$__conexionLocal = __DIR__ . '/conexion.local.php';
if (is_file($__conexionLocal)) {
    require_once $__conexionLocal;
}

// 2-3) Entorno o defaults locales. El archivo local manda si ya definió.
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'tickets_distrital');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
unset($__conexionLocal);

function obtenerConexion(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepares reales (no emulados): los datos viajan separados del SQL
            // en el protocolo de MySQL, que es la protección más fuerte contra
            // inyección SQL que existe a nivel de driver.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        // Deshabilitar múltiples sentencias por consulta (defensa adicional:
        // ni con prepares emulados se podría "apilar" un segundo comando SQL).
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $opciones[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            // No mostramos el detalle del error en producción por seguridad
            error_log('Error de conexión a la base de datos: ' . $e->getMessage());
            die('No se pudo conectar a la base de datos. Avisá al administrador del sistema.');
        }
    }

    return $pdo;
}
