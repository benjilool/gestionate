<?php
/**
 * Sesión, control de acceso por rol y protección CSRF.
 * Se incluye al inicio de cada página.
 */

require_once __DIR__ . '/conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,      // JavaScript no puede leer la cookie de sesión
        'samesite' => 'Strict',  // la cookie no viaja en peticiones de otros sitios
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Escapa texto antes de mostrarlo en HTML (protección XSS). */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

function usuarioActual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** Página de inicio de cada rol después del login. */
function paginaInicioPorRol(string $rol): string
{
    return match ($rol) {
        'cocina'        => 'cocina.php',
        'garzon'        => 'panel.php',
        'administrador' => 'panel.php',
        default         => 'login.php',
    };
}

/**
 * Exige sesión iniciada y, si se indican, uno de los roles permitidos.
 * Ejemplo: requerirRol(['administrador', 'cocina']);
 */
function requerirRol(array $rolesPermitidos = []): array
{
    $usuario = usuarioActual();
    if ($usuario === null) {
        header('Location: login.php');
        exit;
    }
    if ($rolesPermitidos && !in_array($usuario['rol'], $rolesPermitidos, true)) {
        http_response_code(403);
        require __DIR__ . '/sin_permiso.php';
        exit;
    }
    return $usuario;
}

/** Token CSRF: cada formulario POST lo envía y el servidor lo compara. */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function validarCsrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
