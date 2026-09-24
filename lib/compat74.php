<?php
/**
 * Compatibilidad con PHP 7.4.
 *
 * Los helpers str_contains() / str_starts_with() / str_ends_with() existen
 * desde PHP 8.0. En 7.4 se definen acá con la misma semántica (incluido el
 * caso de aguja vacía, que en PHP 8 devuelve true). En PHP 8+ este archivo
 * no hace nada: se usan las funciones nativas.
 *
 * Se carga desde config/conexion.php, que a su vez carga en todas las
 * páginas vía config/sesion.php, así que no hay que incluirlo a mano.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $largo = strlen($needle);
        if ($largo > strlen($haystack)) {
            return false;
        }
        return substr($haystack, -$largo) === $needle;
    }
}
