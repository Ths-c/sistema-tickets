<?php
require_once __DIR__ . '/../config/sesion.php';
header('Location: ' . (usuarioActual() ? 'dashboard.php' : 'login.php'));
exit;
