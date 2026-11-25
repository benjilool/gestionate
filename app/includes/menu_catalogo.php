<?php
/** Pestañas del catálogo (platos, categorías, ingredientes). Variable: $seccionCatalogo. */
$pestanas = [
    'platos.php'       => 'Platos y recetas',
    'categorias.php'   => 'Categorías',
    'ingredientes.php' => 'Ingredientes',
];
?>
<nav class="pestanas">
    <?php foreach ($pestanas as $archivo => $texto): ?>
        <a href="<?= e($archivo) ?>" class="<?= $archivo === ($seccionCatalogo ?? '') ? 'activa' : '' ?>"><?= e($texto) ?></a>
    <?php endforeach; ?>
</nav>
