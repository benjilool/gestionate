<?php
/**
 * Página 403: el usuario inició sesión pero su rol no puede ver esta página.
 * Se muestra con el diseño de la app y un botón para volver a su inicio.
 * La incluye requerirRol(); usa $usuario de esa función.
 */
$tituloPagina = 'Sin permiso';
$claseBody    = $usuario['rol'] === 'cocina' ? 'modo-cocina' : '';   // la cocina sigue viendo su tema oscuro
require __DIR__ . '/header.php';
?>
<main class="contenedor sin-permiso">
    <div class="tarjeta sin-permiso__caja">
        <img src="img/icono.png" alt="" width="72" height="72">
        <h1>No tienes permiso para ver esta página</h1>
        <p>Tu usuario (<?= e($usuario['rol']) ?>) no tiene acceso a esta sección. Si crees que es un error, avisa al administrador.</p>
        <a class="btn btn--grande btn--listo" href="<?= e(paginaInicioPorRol($usuario['rol'])) ?>">Volver a mi inicio</a>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
