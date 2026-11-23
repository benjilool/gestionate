<?php
/**
 * Inicio de sesión
 * - Valida correo y contraseña con password_verify() (hash bcrypt de la BD).
 * - Mensaje de error genérico: no revela si el correo existe.
 * - Bloqueo temporal tras 5 intentos fallidos (60 segundos).
 * - Regenera el id de sesión al entrar (evita fijación de sesión).
 */
require_once __DIR__ . '/includes/auth.php';

if (usuarioActual()) {
    header('Location: ' . paginaInicioPorRol(usuarioActual()['rol']));
    exit;
}

const MAX_INTENTOS = 5;
const SEGUNDOS_BLOQUEO = 60;

$error = '';
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $clave  = $_POST['clave'] ?? '';
    $bloqueadoHasta = $_SESSION['bloqueo_login'] ?? 0;

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Recarga la página e intenta de nuevo.';
    } elseif ($bloqueadoHasta > time()) {
        $error = 'Demasiados intentos. Espera ' . ($bloqueadoHasta - time()) . ' segundos.';
    } elseif ($correo === '' || $clave === '') {
        $error = 'Ingresa tu correo y tu contraseña.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no tiene un formato válido.';
    } else {
        $stmt = obtenerConexion()->prepare(
            'SELECT id_usuario, nombre, correo, password_hash, rol
             FROM usuarios
             WHERE correo = :correo AND activo = TRUE
             LIMIT 1'
        );
        $stmt->execute(['correo' => $correo]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($clave, $usuario['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['intentos_login'], $_SESSION['bloqueo_login']);
            $_SESSION['usuario'] = [
                'id'     => (int) $usuario['id_usuario'],
                'nombre' => $usuario['nombre'],
                'rol'    => $usuario['rol'],
            ];
            header('Location: ' . paginaInicioPorRol($usuario['rol']));
            exit;
        }

        $_SESSION['intentos_login'] = ($_SESSION['intentos_login'] ?? 0) + 1;
        if ($_SESSION['intentos_login'] >= MAX_INTENTOS) {
            $_SESSION['bloqueo_login'] = time() + SEGUNDOS_BLOQUEO;
            $_SESSION['intentos_login'] = 0;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}

$tituloPagina = 'Iniciar sesión';
require __DIR__ . '/includes/header.php';
?>
<main class="login">
    <form class="tarjeta login__form" method="post" action="login.php" novalidate>
        <h1 class="marca">Gesti<span>Ó</span>nate</h1>
        <p class="sub">Pedidos y stock de tu cocina, en orden.</p>

        <?php if ($error): ?>
            <p class="alerta" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <label for="correo">Correo</label>
        <input id="correo" name="correo" type="email" autocomplete="username"
               value="<?= e($correo) ?>" required autofocus>

        <label for="clave">Contraseña</label>
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>

        <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
        <button type="submit" class="btn btn--primario">Entrar</button>
    </form>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
