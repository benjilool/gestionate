<?php
/**
 * Tomar pedido (garzón y administrador)
 * - Muestra solo los platos disponibles, agrupados por categoría.
 * - Guarda el pedido en una transacción: INSERT pedido -> INSERT detalles
 *   -> CALL sp_descontar_stock_pedido -> commit. Si falta stock, el
 *   procedimiento lanza SIGNAL 45000, se hace rollBack y no queda nada guardado.
 * - La lógica de stock vive en la BD; aquí solo se validan los datos del formulario.
 */
require_once __DIR__ . '/includes/auth.php';
$usuario = requerirRol(['administrador', 'garzon']);
$db = obtenerConexion();

const MAX_CANTIDAD = 50;      // tope por línea, evita errores de tipeo (ej. 500 en vez de 5)
const MAX_OBSERVACION = 255;  // largo de detalle_pedidos.observaciones

// Platos que se pueden vender ahora (los agotados e inactivos no se muestran)
$platos = $db->query(
    "SELECT p.id_plato, p.nombre, p.precio, c.nombre_categoria
     FROM platos p
     JOIN categorias_platos c ON c.id_categoria = p.id_categoria
     WHERE p.estado = 'disponible'
     ORDER BY c.id_categoria, p.nombre"
)->fetchAll();

$platosPorId = array_column($platos, null, 'id_plato');

// Valores del formulario (se conservan si hay un error, para no volver a digitar todo)
$tipo          = 'local';
$mesa          = '';
$cantidades    = [];
$observaciones = [];
$error         = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = filter_input(INPUT_POST, 'tipo_pedido', FILTER_VALIDATE_REGEXP,
                         ['options' => ['regexp' => '/^(local|llevar)$/']]) ?: '';
    $mesa = trim((string) filter_input(INPUT_POST, 'numero_mesa'));

    // Cantidades: enteros entre 0 y MAX_CANTIDAD; lo inválido queda como false
    $cantidades = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT, [
        'flags'   => FILTER_REQUIRE_ARRAY,
        'options' => ['min_range' => 0, 'max_range' => MAX_CANTIDAD],
    ]) ?: [];
    $observaciones = filter_input(INPUT_POST, 'observacion', FILTER_DEFAULT,
                                  FILTER_REQUIRE_ARRAY) ?: [];
    // Si alguien manda arreglos anidados, se descartan (solo se aceptan textos)
    $observaciones = array_map(fn($o) => is_string($o) ? $o : '', $observaciones);

    // Arma las líneas del pedido solo con platos de la lista y cantidad > 0
    $lineas = [];
    $cantidadInvalida = false;
    $platoNoDisponible = false;
    foreach ($cantidades as $idPlato => $cantidad) {
        if (!is_int($cantidad)) {
            $cantidadInvalida = true;
            continue;
        }
        if ($cantidad === 0) {
            continue;
        }
        if (!isset($platosPorId[$idPlato])) {
            $platoNoDisponible = true;   // se agotó mientras se tomaba el pedido
            continue;
        }
        $obs = trim((string) ($observaciones[$idPlato] ?? ''));
        $lineas[] = [
            'id_plato'      => (int) $idPlato,
            'cantidad'      => $cantidad,
            'observaciones' => $obs === '' ? null : mb_substr($obs, 0, MAX_OBSERVACION),
        ];
    }

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif ($tipo === '') {
        $error = 'Elige si el pedido es para la mesa o para llevar.';
    } elseif ($tipo === 'local' && !preg_match('/^[0-9A-Za-z]{1,10}$/', $mesa)) {
        $error = 'Indica el número de mesa (solo letras y números, máximo 10).';
    } elseif ($cantidadInvalida) {
        $error = 'Las cantidades deben ser números enteros entre 0 y ' . MAX_CANTIDAD . '.';
    } elseif ($platoNoDisponible) {
        $error = 'Uno de los platos ya no está disponible. Revisa el menú y vuelve a enviar.';
    } elseif (!$lineas) {
        $error = 'Agrega al menos un plato.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('INSERT INTO pedidos (id_usuario, numero_mesa, tipo_pedido)
                                  VALUES (:usuario, :mesa, :tipo)');
            $stmt->execute([
                'usuario' => $usuario['id'],
                'mesa'    => $tipo === 'local' ? $mesa : null,   // para llevar no tiene mesa
                'tipo'    => $tipo,
            ]);
            $idPedido = (int) $db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO detalle_pedidos (id_pedido, id_plato, cantidad, observaciones)
                                  VALUES (:pedido, :plato, :cantidad, :obs)');
            foreach ($lineas as $l) {
                $stmt->execute([
                    'pedido'   => $idPedido,
                    'plato'    => $l['id_plato'],
                    'cantidad' => $l['cantidad'],
                    'obs'      => $l['observaciones'],
                ]);
            }

            // Valida y descuenta stock según la receta (no abre transacción propia)
            $stmt = $db->prepare('CALL sp_descontar_stock_pedido(:pedido, :usuario)');
            $stmt->execute(['pedido' => $idPedido, 'usuario' => $usuario['id']]);
            $stmt->closeCursor();

            $db->commit();

            $total = 0;
            foreach ($lineas as $l) {
                $total += $platosPorId[$l['id_plato']]['precio'] * $l['cantidad'];
            }
            $_SESSION['flash_pedido'] = 'Pedido #' . $idPedido . ' enviado a cocina. Total: $'
                                      . number_format($total, 0, ',', '.');
            header('Location: pedido_nuevo.php');   // patrón PRG: recargar no duplica el pedido
            exit;
        } catch (PDOException $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Errores de negocio (SIGNAL 45000, ej. "Stock insuficiente de: X") se muestran;
            // el resto se registra sin mostrar detalles
            $error = $ex->getCode() === '45000' ? ($ex->errorInfo[2] ?? 'No se pudo guardar el pedido.')
                                                : 'No se pudo guardar el pedido. Intenta de nuevo.';
            error_log('[GestiÓnate] ' . $ex->getMessage());
        }
    }
}

// Mensaje de éxito del pedido anterior (se muestra una sola vez)
$exito = $_SESSION['flash_pedido'] ?? '';
unset($_SESSION['flash_pedido']);

// Agrupa por categoría para mostrar Fondos / Agregados / Bebestibles
$categorias = [];
foreach ($platos as $p) {
    $categorias[$p['nombre_categoria']][] = $p;
}

$tituloPagina = 'Tomar pedido';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Tomar pedido</h1>

    <?php if ($exito): ?><p class="aviso-ok"><?= e($exito) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alerta"><?= e($error) ?></p><?php endif; ?>

    <?php if (!$platos): ?>
        <p class="alerta">No hay platos disponibles. Hay que reponer stock antes de tomar pedidos.</p>
    <?php else: ?>
    <form method="post" action="pedido_nuevo.php" class="pedido">
        <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">

        <fieldset class="tarjeta pedido__tipo">
            <legend>¿Dónde se sirve?</legend>
            <label class="opcion">
                <input type="radio" name="tipo_pedido" value="local" <?= $tipo !== 'llevar' ? 'checked' : '' ?>>
                Mesa
            </label>
            <label class="opcion">
                <input type="radio" name="tipo_pedido" value="llevar" <?= $tipo === 'llevar' ? 'checked' : '' ?>>
                Para llevar
            </label>
            <label class="pedido__mesa">
                N° de mesa
                <input type="text" name="numero_mesa" value="<?= e($mesa) ?>" maxlength="10"
                       inputmode="numeric" autocomplete="off" placeholder="Ej: 4">
            </label>
        </fieldset>

        <?php foreach ($categorias as $nombreCategoria => $lista): ?>
            <section class="tarjeta">
                <h2><?= e($nombreCategoria) ?></h2>
                <?php foreach ($lista as $p): $id = (int) $p['id_plato']; ?>
                    <div class="linea">
                        <label class="linea__nombre" for="cant-<?= $id ?>">
                            <strong><?= e($p['nombre']) ?></strong>
                            <span><?= $p['precio'] > 0 ? '$' . e(number_format($p['precio'], 0, ',', '.')) : 'Incluido' ?></span>
                        </label>
                        <input class="linea__cant" type="number" id="cant-<?= $id ?>"
                               name="cantidad[<?= $id ?>]" min="0" max="<?= MAX_CANTIDAD ?>" inputmode="numeric"
                               value="<?= is_int($cantidades[$id] ?? null) ? $cantidades[$id] : 0 ?>">
                        <input class="linea__obs" type="text" name="observacion[<?= $id ?>]"
                               maxlength="<?= MAX_OBSERVACION ?>" placeholder="Observación (opcional)"
                               value="<?= e($observaciones[$id] ?? '') ?>">
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <button class="btn btn--grande btn--listo" type="submit">Enviar a cocina</button>
    </form>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
