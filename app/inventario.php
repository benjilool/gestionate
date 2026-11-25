<?php
/**
 * Inventario (solo administrador)
 * - Stock actual de cada ingrediente, marcando los que están bajo el mínimo.
 * - Registrar entrada (preparación del día o compra) con sp_registrar_entrada,
 *   que suma el stock, guarda el movimiento y reactiva los platos agotados.
 * - Historial de movimientos_inventario (entradas, ventas, mermas y ajustes).
 */
require_once __DIR__ . '/includes/auth.php';
$usuario = requerirRol(['administrador']);
$db = obtenerConexion();

const MAX_ENTRADA = 9999;     // tope por entrada, evita errores de tipeo
const MAX_DETALLE = 200;      // motivo + detalle caben en movimientos_inventario.motivo (255)
const LIMITE_HISTORIAL = 50;
const MOTIVOS = ['Preparación del día', 'Compra'];

/** Muestra 3.00 como "3" y 2.50 como "2,5" (formato chileno, sin ceros de más). */
function formatoCantidad(string|float $numero): string
{
    return rtrim(rtrim(number_format((float) $numero, 2, ',', '.'), '0'), ',');
}

/** Abreviatura de la unidad para las tablas. */
function unidadCorta(string $unidad): string
{
    return match ($unidad) {
        'unidad' => 'u.',
        'litro'  => 'L',
        default  => $unidad,
    };
}

$ingredientes = $db->query(
    'SELECT id_ingrediente, nombre, unidad_medida, stock_actual, stock_minimo_alerta,
            stock_actual <= stock_minimo_alerta AS bajo_minimo
     FROM ingredientes
     ORDER BY nombre'
)->fetchAll();
$ingredientesPorId = array_column($ingredientes, null, 'id_ingrediente');

// Valores del formulario (se conservan si hay error)
$idIngrediente = 0;
$cantidadTexto = '';
$motivo        = MOTIVOS[0];
$detalle       = '';
$error         = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idIngrediente = filter_input(INPUT_POST, 'id_ingrediente', FILTER_VALIDATE_INT) ?: 0;
    $cantidadTexto = trim((string) filter_input(INPUT_POST, 'cantidad'));
    $motivo        = (string) filter_input(INPUT_POST, 'motivo');
    $detalle       = trim((string) filter_input(INPUT_POST, 'detalle'));

    // En Chile se escribe "2,5": se acepta la coma como separador decimal
    $cantidad = filter_var(str_replace(',', '.', $cantidadTexto), FILTER_VALIDATE_FLOAT);
    $cantidad = $cantidad === false ? 0 : round($cantidad, 2);   // la columna guarda 2 decimales

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif (!isset($ingredientesPorId[$idIngrediente])) {
        $error = 'Elige un ingrediente.';
    } elseif ($cantidad <= 0 || $cantidad > MAX_ENTRADA) {
        $error = 'La cantidad debe ser un número mayor que 0 y hasta ' . number_format(MAX_ENTRADA, 0, ',', '.')
               . ' (ej: 2,5).';
    } elseif (!in_array($motivo, MOTIVOS, true)) {
        $error = 'Elige el motivo de la entrada.';
    } else {
        // Motivo fijo + detalle opcional (ej. "Compra · Feria Las Compañías")
        $textoMotivo = $detalle === '' ? $motivo : $motivo . ' · ' . mb_substr($detalle, 0, MAX_DETALLE);
        try {
            // El procedimiento hace UPDATE + INSERT: la transacción asegura que pasen los dos o ninguno
            $db->beginTransaction();
            $stmt = $db->prepare('CALL sp_registrar_entrada(:ingrediente, :cantidad, :usuario, :motivo)');
            $stmt->execute([
                'ingrediente' => $idIngrediente,
                'cantidad'    => $cantidad,
                'usuario'     => $usuario['id'],
                'motivo'      => $textoMotivo,
            ]);
            $stmt->closeCursor();
            $db->commit();

            $ing = $ingredientesPorId[$idIngrediente];
            $_SESSION['flash_inventario'] = 'Entrada registrada: ' . formatoCantidad($cantidad) . ' '
                                          . unidadCorta($ing['unidad_medida']) . ' de ' . $ing['nombre'] . '.';
            header('Location: inventario.php');   // patrón PRG: recargar no repite la entrada
            exit;
        } catch (PDOException $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $ex->getCode() === '45000' ? ($ex->errorInfo[2] ?? 'No se pudo registrar la entrada.')
                                                : 'No se pudo registrar la entrada. Intenta de nuevo.';
            error_log('[GestiÓnate] ' . $ex->getMessage());
        }
    }
}

$exito = $_SESSION['flash_inventario'] ?? '';
unset($_SESSION['flash_inventario']);

// Historial: filtro opcional por ingrediente (?ver=ID)
$filtro = filter_input(INPUT_GET, 'ver', FILTER_VALIDATE_INT) ?: 0;
if (!isset($ingredientesPorId[$filtro])) {
    $filtro = 0;
}
$sql = 'SELECT m.fecha, m.tipo, m.cantidad, m.stock_resultante, m.motivo, m.id_pedido,
               i.nombre AS ingrediente, i.unidad_medida, u.nombre AS usuario
        FROM movimientos_inventario m
        JOIN ingredientes i ON i.id_ingrediente = m.id_ingrediente
        JOIN usuarios u ON u.id_usuario = m.id_usuario
        WHERE (:filtro = 0 OR m.id_ingrediente = :filtro2)
        ORDER BY m.fecha DESC, m.id_movimiento DESC
        LIMIT ' . LIMITE_HISTORIAL;   // constante propia, no viene del usuario
$stmt = $db->prepare($sql);
$stmt->execute(['filtro' => $filtro, 'filtro2' => $filtro]);
$movimientos = $stmt->fetchAll();

$nombresTipo = [
    'entrada'      => 'Entrada',
    'salida_venta' => 'Venta',
    'merma'        => 'Merma',
    'ajuste'       => 'Ajuste',
];

$tituloPagina = 'Inventario';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Inventario</h1>

    <?php if ($exito): ?><p class="aviso-ok"><?= e($exito) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alerta"><?= e($error) ?></p><?php endif; ?>

    <section class="tarjeta" id="entrada">
        <h2>Registrar entrada</h2>
        <form method="post" action="inventario.php" class="formulario">
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <label>
                Ingrediente
                <select name="id_ingrediente" required>
                    <option value="">Elegir…</option>
                    <?php foreach ($ingredientes as $i): $id = (int) $i['id_ingrediente']; ?>
                        <option value="<?= $id ?>" <?= $id === $idIngrediente ? 'selected' : '' ?>>
                            <?= e($i['nombre']) ?> (<?= e(unidadCorta($i['unidad_medida'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Cantidad
                <input type="text" name="cantidad" value="<?= e($cantidadTexto) ?>" inputmode="decimal"
                       autocomplete="off" placeholder="Ej: 15 o 2,5" required>
            </label>
            <label>
                Motivo
                <select name="motivo">
                    <?php foreach (MOTIVOS as $m): ?>
                        <option <?= $m === $motivo ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Detalle (opcional)
                <input type="text" name="detalle" value="<?= e($detalle) ?>" maxlength="<?= MAX_DETALLE ?>"
                       placeholder="Ej: proveedor o factura">
            </label>
            <button class="btn btn--grande btn--listo" type="submit">Registrar entrada</button>
        </form>
    </section>

    <section class="tarjeta">
        <h2>Stock actual</h2>
        <div class="tabla-scroll">
            <table class="tabla">
                <thead><tr><th>Ingrediente</th><th>Stock</th><th>Mínimo</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($ingredientes as $i): $u = unidadCorta($i['unidad_medida']); ?>
                    <tr class="<?= $i['bajo_minimo'] ? 'fila--alerta' : '' ?>">
                        <td><?= e($i['nombre']) ?></td>
                        <td><strong><?= e(formatoCantidad($i['stock_actual']) . ' ' . $u) ?></strong></td>
                        <td><?= e(formatoCantidad($i['stock_minimo_alerta']) . ' ' . $u) ?></td>
                        <td><?= $i['bajo_minimo'] ? '<span class="etiqueta etiqueta--alerta">Reponer</span>'
                                                  : '<span class="etiqueta">OK</span>' ?></td>
                        <td><a href="inventario.php?ver=<?= (int) $i['id_ingrediente'] ?>#historial">Historial</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="tarjeta" id="historial">
        <h2>
            Movimientos
            <?= $filtro ? 'de ' . e($ingredientesPorId[$filtro]['nombre']) : 'recientes' ?>
        </h2>
        <?php if ($filtro): ?>
            <p><a href="inventario.php#historial">Ver todos los ingredientes</a></p>
        <?php endif; ?>
        <?php if (!$movimientos): ?>
            <p>Todavía no hay movimientos.</p>
        <?php else: ?>
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Ingrediente</th><th>Tipo</th><th>Cantidad</th>
                               <th>Queda</th><th>Motivo</th><th>Usuario</th></tr></thead>
                    <tbody>
                    <?php foreach ($movimientos as $m):
                        $u = unidadCorta($m['unidad_medida']);
                        // Entrada suma y venta/merma restan; el ajuste puede ir en ambos sentidos
                        $signo = match ($m['tipo']) { 'entrada' => '+', 'ajuste' => '', default => '−' }; ?>
                        <tr>
                            <td><?= e(date('d/m H:i', strtotime($m['fecha']))) ?></td>
                            <td><?= e($m['ingrediente']) ?></td>
                            <td><?= e($nombresTipo[$m['tipo']] ?? $m['tipo']) ?></td>
                            <td class="<?= $m['tipo'] === 'entrada' ? 'num--mas' : '' ?>">
                                <?= e($signo . formatoCantidad($m['cantidad']) . ' ' . $u) ?>
                            </td>
                            <td><?= e(formatoCantidad($m['stock_resultante']) . ' ' . $u) ?></td>
                            <td><?= e($m['motivo'] ?? '') ?></td>
                            <td><?= e($m['usuario']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">Se muestran los últimos <?= LIMITE_HISTORIAL ?> movimientos.</p>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
