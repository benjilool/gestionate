<?php
/** Punto de entrada: envía al login o a la página del rol. */
require_once __DIR__ . '/includes/auth.php';

$usuario = usuarioActual();
header('Location: ' . ($usuario ? paginaInicioPorRol($usuario['rol']) : 'login.php'));
exit;
