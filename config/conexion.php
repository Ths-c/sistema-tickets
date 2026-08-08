<?php
/**
 * Conexión a la base de datos.
 * En localhost (XAMPP) los valores por defecto ya funcionan.
 * Al subir a un hosting, sobrescribir con variables de entorno o
 * editar directamente estas constantes.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'tickets_distrital');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

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
