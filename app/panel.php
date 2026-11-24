<?php
/** Panel de administrador y garzón: resumen del turno y accesos a los módulos. */
require_once __DIR__ . '/includes/auth.php';
$usuario = requerirRol(['administrador', 'garzon']);
$db = obtenerConexion();

$enCola   = (int) $db->query("SELECT COUNT(*) FROM pedidos WHERE estado IN ('pendiente','en_preparacion')")->fetchColumn();
$listos   = (int) $db->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'listo'")->fetchColumn();
$agotados = $db->query("SELECT nombre FROM platos WHERE estado = 'agotado' ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
$alertas  = $db->query('SELECT nombre, unidad_medida, stock_actual, stock_minimo_alerta
                        FROM vista_alertas_stock ORDER BY nombre')->fetchAll();

$tituloPagina = 'Panel';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Hola, <?= e($usuario['nombre']) ?></h1>

    <section class="resumen">
        <div class="tarjeta dato">
            <span class="dato__num"><?= $enCola ?></span>
            <span class="dato__txt">pedidos en cocina</span>
        </div>
        <div class="tarjeta dato">
            <span class="dato__num"><?= $listos ?></span>
            <span class="dato__txt">listos para el pase</span>
        </div>
        <div class="tarjeta dato <?= $agotados ? 'dato--alerta' : '' ?>">
            <span class="dato__num"><?= count($agotados) ?></span>
            <span class="dato__txt">platos agotados</span>
        </div>
    </section>

    <?php if ($agotados): ?>
        <p class="alerta">Agotados: <?= e(implode(', ', $agotados)) ?>. No se pueden vender hasta reponer stock.</p>
    <?php endif; ?>

    <section class="tarjeta">
        <h2>Ingredientes bajo el mínimo</h2>
        <?php if (!$alertas): ?>
            <p>Todo el stock está sobre el mínimo.</p>
        <?php else: ?>
            <table class="tabla">
                <thead><tr><th>Ingrediente</th><th>Stock actual</th><th>Mínimo</th></tr></thead>
                <tbody>
                <?php foreach ($alertas as $a): ?>
                    <tr>
                        <td><?= e($a['nombre']) ?></td>
                        <td><?= e($a['stock_actual'] . ' ' . $a['unidad_medida']) ?></td>
                        <td><?= e($a['stock_minimo_alerta'] . ' ' . $a['unidad_medida']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="modulos">
        <a class="tarjeta modulo" href="cocina.php"><strong>Pantalla de cocina</strong><span>Cola de pedidos en orden de llegada</span></a>
        <a class="tarjeta modulo" href="pedido_nuevo.php"><strong>Tomar pedido</strong><span>Mesa o para llevar, con descuento de stock</span></a>
        <?php if ($usuario['rol'] === 'administrador'): ?>
            <div class="tarjeta modulo modulo--pronto"><strong>Platos y recetas</strong><span>Próxima versión</span></div>
            <div class="tarjeta modulo modulo--pronto"><strong>Inventario</strong><span>Próxima versión</span></div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
