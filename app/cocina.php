<?php
/**
 * Pantalla de cocina (KDS)
 * - Lee vista_cola_cocina en orden FIFO (fecha_hora_ingreso ascendente).
 * - Semáforo calculado en la BD según tiempo_preparacion_minutos.
 * - Botones para avanzar el estado con sp_cambiar_estado_pedido (guarda los tiempos reales).
 * - Se recarga sola cada 20 segundos.
 */
require_once __DIR__ . '/includes/auth.php';
requerirRol(['administrador', 'cocina']);
$db = obtenerConexion();

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPedido = filter_input(INPUT_POST, 'id_pedido', FILTER_VALIDATE_INT);
    $nuevo    = $_POST['nuevo_estado'] ?? '';
    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $mensaje = 'La sesión expiró. Intenta de nuevo.';
    } elseif (!$idPedido || !in_array($nuevo, ['en_preparacion', 'listo'], true)) {
        $mensaje = 'Acción no válida.';
    } else {
        try {
            $stmt = $db->prepare('CALL sp_cambiar_estado_pedido(:id, :estado)');
            $stmt->execute(['id' => $idPedido, 'estado' => $nuevo]);
            $stmt->closeCursor();
            header('Location: cocina.php');   // patrón PRG: evita reenviar el formulario al recargar
            exit;
        } catch (PDOException $e) {
            // Errores de negocio (SIGNAL 45000) se muestran; el resto se registra
            $mensaje = $e->getCode() === '45000' ? ($e->errorInfo[2] ?? 'No se pudo cambiar el estado.')
                                                 : 'No se pudo cambiar el estado.';
            error_log('[GestiÓnate] ' . $e->getMessage());
        }
    }
}

$pedidos = $db->query('SELECT * FROM vista_cola_cocina ORDER BY fecha_hora_ingreso ASC')->fetchAll();

$tituloPagina  = 'Cocina';
$claseBody     = 'modo-cocina';
$refrescarCada = 20;
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <div class="kds__cabecera">
        <h1>Cola de pedidos</h1>
        <span class="kds__hora"><?= date('H:i') ?></span>
    </div>

    <?php if ($mensaje): ?><p class="alerta"><?= e($mensaje) ?></p><?php endif; ?>

    <?php if (!$pedidos): ?>
        <p class="kds__vacio">Sin pedidos pendientes.</p>
    <?php endif; ?>

    <section class="kds">
        <?php foreach ($pedidos as $i => $p): ?>
            <article class="comanda comanda--<?= e($p['semaforo']) ?>">
                <header class="comanda__cab">
                    <span class="comanda__orden">#<?= $i + 1 ?></span>
                    <strong><?= $p['tipo_pedido'] === 'llevar' ? 'Para llevar' : 'Mesa ' . e($p['numero_mesa']) ?></strong>
                    <span class="comanda__tiempo"><?= (int) $p['minutos_transcurridos'] ?> / <?= (int) $p['minutos_estimados'] ?> min</span>
                </header>
                <ul class="comanda__platos">
                    <?php foreach (explode(' | ', $p['platos']) as $plato): ?>
                        <li><?= e($plato) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="comanda__meta">Pedido <?= (int) $p['id_pedido'] ?> · <?= e($p['garzon']) ?> · <?= e(date('H:i', strtotime($p['fecha_hora_ingreso']))) ?></p>
                <form method="post" action="cocina.php">
                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                    <input type="hidden" name="id_pedido" value="<?= (int) $p['id_pedido'] ?>">
                    <?php if ($p['estado'] === 'pendiente'): ?>
                        <input type="hidden" name="nuevo_estado" value="en_preparacion">
                        <button class="btn btn--grande">Empezar</button>
                    <?php else: ?>
                        <input type="hidden" name="nuevo_estado" value="listo">
                        <button class="btn btn--grande btn--listo">Listo al pase</button>
                    <?php endif; ?>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
