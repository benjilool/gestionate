<?php
/**
 * Conexión única a MySQL con PDO.
 * - Consultas preparadas reales (ATTR_EMULATE_PREPARES = false) contra inyección SQL.
 * - Errores como excepciones: nunca se muestran detalles de la BD al usuario.
 */

function obtenerConexion(): PDO
{
    static $pdo = null;               // se reutiliza la misma conexión en cada petición
    if ($pdo !== null) {
        return $pdo;
    }

    $rutaConfig = __DIR__ . '/../config.php';
    if (!file_exists($rutaConfig)) {
        http_response_code(500);
        exit('Falta config.php: copia config.example.php como config.php y completa tus datos.');
    }
    $cfg = require $rutaConfig;

    date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Santiago');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['db_host'], $cfg['db_port'], $cfg['db_nombre']
    );

    try {
        $pdo = new PDO($dsn, $cfg['db_usuario'], $cfg['db_clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Misma zona horaria en MySQL que en PHP (para NOW() y el semáforo)
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    } catch (PDOException $e) {
        error_log('[GestiÓnate] Error de conexión: ' . $e->getMessage());
        http_response_code(500);
        exit('No se pudo conectar a la base de datos. Revisa que MySQL esté encendido en XAMPP.');
    }

    return $pdo;
}
