<?php
/** Cabecera común. Variables opcionales: $tituloPagina, $claseBody, $refrescarCada (segundos). */
$usuario = usuarioActual();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($refrescarCada)): ?>
        <meta http-equiv="refresh" content="<?= (int) $refrescarCada ?>">
    <?php endif; ?>
    <title><?= e($tituloPagina ?? 'GestiÓnate') ?> · GestiÓnate</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="<?= e($claseBody ?? '') ?>">
<?php if ($usuario): ?>
    <header class="barra">
        <a class="marca marca--chica" href="index.php">Gesti<span>Ó</span>nate</a>
        <nav class="barra__nav">
            <?php if (in_array($usuario['rol'], ['administrador', 'garzon'], true)): ?>
                <a href="panel.php">Panel</a>
                <a href="pedido_nuevo.php">Tomar pedido</a>
            <?php endif; ?>
            <?php if ($usuario['rol'] === 'administrador'): ?>
                <a href="inventario.php">Inventario</a>
            <?php endif; ?>
            <?php if (in_array($usuario['rol'], ['administrador', 'cocina'], true)): ?>
                <a href="cocina.php">Cocina</a>
            <?php endif; ?>
        </nav>
        <div class="barra__usuario">
            <span><?= e($usuario['nombre']) ?> · <?= e($usuario['rol']) ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <button class="btn btn--texto" type="submit">Salir</button>
            </form>
        </div>
    </header>
<?php endif; ?>
