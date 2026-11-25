<?php
/**
 * Platos y recetas (solo administrador)
 * - Crear y editar un plato junto con su receta (cuánto de cada ingrediente usa
 *   una porción). Se guarda en una transacción y después se llama a
 *   sp_actualizar_disponibilidad() para que el estado refleje la receta nueva.
 * - "Eliminar": si el plato tiene ventas queda 'inactivo' (no se pierde el
 *   historial); si no tiene ventas se borra de verdad (su receta se borra en cascada).
 * - Un plato inactivo se puede reactivar.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formato.php';
requerirRol(['administrador']);
$db = obtenerConexion();

const MAX_NOMBRE = 100;           // platos.nombre
const MAX_DESCRIPCION = 500;
const MAX_PRECIO = 1000000;
const MAX_MINUTOS = 180;
const MAX_CANTIDAD_RECETA = 999;  // por porción; evita errores de tipeo

$categorias = $db->query('SELECT id_categoria, nombre_categoria FROM categorias_platos ORDER BY id_categoria')
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
$ingredientes = $db->query('SELECT id_ingrediente, nombre, unidad_medida FROM ingredientes ORDER BY nombre')
                   ->fetchAll();
$ingredientesPorId = array_column($ingredientes, null, 'id_ingrediente');

/** Datos de un plato y su receta, o null si no existe. */
function buscarPlato(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT p.*, (SELECT COUNT(*) FROM detalle_pedidos d WHERE d.id_plato = p.id_plato) AS ventas
                          FROM platos p WHERE p.id_plato = :id');
    $stmt->execute(['id' => $id]);
    $plato = $stmt->fetch();
    if (!$plato) {
        return null;
    }
    $stmt = $db->prepare('SELECT id_ingrediente, cantidad_requerida FROM plato_ingredientes WHERE id_plato = :id');
    $stmt->execute(['id' => $id]);
    $plato['receta'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return $plato;
}

$formVacio = ['nombre' => '', 'id_categoria' => 0, 'precio' => '', 'minutos' => '', 'descripcion' => '', 'receta' => []];
$form   = $formVacio;
$error  = '';
$editar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) filter_input(INPUT_POST, 'accion');
    $id     = filter_input(INPUT_POST, 'id_plato', FILTER_VALIDATE_INT) ?: 0;
    $actual = $id ? buscarPlato($db, $id) : null;
    $editar = $actual ? $id : 0;

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif ($id && !$actual) {
        $error = 'El plato ya no existe.';
    } elseif ($accion === 'eliminar' && $actual) {
        try {
            if ($actual['ventas'] > 0) {
                // Con ventas: se desactiva para no perder el historial de pedidos
                $db->prepare("UPDATE platos SET estado = 'inactivo' WHERE id_plato = :id")->execute(['id' => $id]);
                $_SESSION['flash_catalogo'] = '"' . $actual['nombre'] . '" tiene ventas: quedó inactivo y ya no aparece al tomar pedidos.';
            } else {
                $db->prepare('DELETE FROM platos WHERE id_plato = :id')->execute(['id' => $id]);
                $_SESSION['flash_catalogo'] = '"' . $actual['nombre'] . '" eliminado.';
            }
            header('Location: platos.php');
            exit;
        } catch (PDOException $ex) {
            // Si se vendió justo ahora, la FK de detalle_pedidos impide borrarlo
            $error = 'No se pudo eliminar el plato. Intenta de nuevo.';
            error_log('[GestiÓnate] ' . $ex->getMessage());
        }
        $editar = 0;
    } elseif ($accion === 'reactivar' && $actual) {
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE platos SET estado = 'disponible' WHERE id_plato = :id AND estado = 'inactivo'")
               ->execute(['id' => $id]);
            // El procedimiento lo deja en 'agotado' si no alcanza el stock para su receta
            $db->query('CALL sp_actualizar_disponibilidad()')->closeCursor();
            $db->commit();
            $_SESSION['flash_catalogo'] = '"' . $actual['nombre'] . '" reactivado.';
            header('Location: platos.php');
            exit;
        } catch (PDOException $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'No se pudo reactivar el plato.';
            error_log('[GestiÓnate] ' . $ex->getMessage());
        }
        $editar = 0;
    } elseif ($accion === 'guardar') {
        $form['nombre']       = trim((string) filter_input(INPUT_POST, 'nombre'));
        $form['id_categoria'] = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT) ?: 0;
        $form['precio']       = trim((string) filter_input(INPUT_POST, 'precio'));
        $form['minutos']      = trim((string) filter_input(INPUT_POST, 'minutos'));
        $form['descripcion']  = trim((string) filter_input(INPUT_POST, 'descripcion'));
        $recetaTexto          = filter_input(INPUT_POST, 'receta', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY) ?: [];

        // Precio: se acepta "4.700" o "$4.700" (formato chileno); se guardan solo los dígitos
        $precio  = filter_var(str_replace(['.', '$', ' '], '', $form['precio']), FILTER_VALIDATE_INT,
                              ['options' => ['min_range' => 0, 'max_range' => MAX_PRECIO]]);
        $minutos = filter_var($form['minutos'], FILTER_VALIDATE_INT,
                              ['options' => ['min_range' => 1, 'max_range' => MAX_MINUTOS]]);

        // Receta: solo ingredientes que existen; un campo vacío significa "no lo usa"
        $receta = [];
        $errorReceta = '';
        foreach ($ingredientesPorId as $idIng => $ing) {
            $texto = $recetaTexto[$idIng] ?? '';
            $texto = is_string($texto) ? trim($texto) : '';
            $form['receta'][$idIng] = $texto;
            if ($texto === '') {
                continue;
            }
            $cantidad = leerDecimal($texto);
            if ($cantidad === null || $cantidad <= 0 || $cantidad > MAX_CANTIDAD_RECETA) {
                $errorReceta = 'La cantidad de "' . $ing['nombre'] . '" debe ser un número mayor que 0 (ej: 0,15).';
                break;
            }
            $receta[$idIng] = $cantidad;
        }

        $repetido = $db->prepare('SELECT COUNT(*) FROM platos WHERE nombre = :nombre AND id_plato <> :id');
        $repetido->execute(['nombre' => $form['nombre'], 'id' => $id]);

        if ($form['nombre'] === '' || mb_strlen($form['nombre']) > MAX_NOMBRE) {
            $error = 'El nombre es obligatorio (máximo ' . MAX_NOMBRE . ' caracteres).';
        } elseif ($repetido->fetchColumn() > 0) {
            $error = 'Ya existe un plato con ese nombre. Si está inactivo, reactívalo en la lista.';
        } elseif (!isset($categorias[$form['id_categoria']])) {
            $error = 'Elige una categoría.';
        } elseif ($precio === false) {
            $error = 'El precio debe ser un número entero entre 0 y ' . formatoPesos(MAX_PRECIO) . ' (0 = incluido en el menú).';
        } elseif ($minutos === false) {
            $error = 'El tiempo de preparación debe ser entre 1 y ' . MAX_MINUTOS . ' minutos.';
        } elseif (mb_strlen($form['descripcion']) > MAX_DESCRIPCION) {
            $error = 'La descripción admite hasta ' . MAX_DESCRIPCION . ' caracteres.';
        } elseif ($errorReceta) {
            $error = $errorReceta;
        } elseif (!$receta) {
            // Sin receta el plato nunca descontaría stock ni se marcaría agotado
            $error = 'La receta debe tener al menos un ingrediente.';
        } else {
            $datos = [
                'categoria'   => $form['id_categoria'],
                'nombre'      => $form['nombre'],
                'descripcion' => $form['descripcion'] === '' ? null : $form['descripcion'],
                'precio'      => $precio,
                'minutos'     => $minutos,
            ];
            try {
                $db->beginTransaction();
                if ($id) {
                    $db->prepare('UPDATE platos SET id_categoria = :categoria, nombre = :nombre, descripcion = :descripcion,
                                  precio = :precio, tiempo_preparacion_minutos = :minutos WHERE id_plato = :id')
                       ->execute($datos + ['id' => $id]);
                    // La receta se reemplaza completa: es más simple que comparar línea por línea
                    $db->prepare('DELETE FROM plato_ingredientes WHERE id_plato = :id')->execute(['id' => $id]);
                } else {
                    $db->prepare('INSERT INTO platos (id_categoria, nombre, descripcion, precio, tiempo_preparacion_minutos)
                                  VALUES (:categoria, :nombre, :descripcion, :precio, :minutos)')
                       ->execute($datos);
                    $id = (int) $db->lastInsertId();
                }

                $stmt = $db->prepare('INSERT INTO plato_ingredientes (id_plato, id_ingrediente, cantidad_requerida)
                                      VALUES (:plato, :ingrediente, :cantidad)');
                foreach ($receta as $idIng => $cantidad) {
                    $stmt->execute(['plato' => $id, 'ingrediente' => $idIng, 'cantidad' => $cantidad]);
                }

                // Con la receta nueva puede pasar de agotado a disponible o al revés
                $db->query('CALL sp_actualizar_disponibilidad()')->closeCursor();
                $db->commit();

                $_SESSION['flash_catalogo'] = 'Plato "' . $form['nombre'] . '" guardado.';
                header('Location: platos.php');   // patrón PRG
                exit;
            } catch (PDOException $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'No se pudo guardar el plato. Intenta de nuevo.';
                error_log('[GestiÓnate] ' . $ex->getMessage());
            }
        }
    } else {
        $error = 'Acción no válida.';
    }
}

// Al entrar a editar (GET) se cargan los datos actuales en el formulario
if ($editar && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $p = buscarPlato($db, $editar);
    if ($p) {
        $form = [
            'nombre'       => $p['nombre'],
            'id_categoria' => (int) $p['id_categoria'],
            'precio'       => (string) $p['precio'],
            'minutos'      => (string) $p['tiempo_preparacion_minutos'],
            'descripcion'  => $p['descripcion'] ?? '',
            'receta'       => array_map('formatoCantidad', $p['receta']),
        ];
    } else {
        $editar = 0;
    }
}

// Lista de platos con su receta y cuántas veces se han vendido
$platos = $db->query(
    "SELECT p.id_plato, p.nombre, p.precio, p.tiempo_preparacion_minutos, p.estado, c.nombre_categoria,
            (SELECT COUNT(*) FROM detalle_pedidos d WHERE d.id_plato = p.id_plato) AS ventas
     FROM platos p
     JOIN categorias_platos c ON c.id_categoria = p.id_categoria
     ORDER BY p.estado = 'inactivo', c.id_categoria, p.nombre"
)->fetchAll();

$recetas = [];
$filas = $db->query('SELECT pi.id_plato, pi.cantidad_requerida, i.nombre, i.unidad_medida
                     FROM plato_ingredientes pi
                     JOIN ingredientes i ON i.id_ingrediente = pi.id_ingrediente
                     ORDER BY i.nombre');
foreach ($filas as $f) {
    $recetas[$f['id_plato']][] = formatoCantidad($f['cantidad_requerida']) . ' ' . unidadCorta($f['unidad_medida'])
                               . ' ' . $f['nombre'];
}

$exito = $_SESSION['flash_catalogo'] ?? '';
unset($_SESSION['flash_catalogo']);

$nombresEstado = ['disponible' => 'Disponible', 'agotado' => 'Agotado', 'inactivo' => 'Inactivo'];

$tituloPagina    = 'Platos y recetas';
$seccionCatalogo = 'platos.php';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Platos y recetas</h1>
    <?php require __DIR__ . '/includes/menu_catalogo.php'; ?>

    <?php if ($exito): ?><p class="aviso-ok"><?= e($exito) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alerta"><?= e($error) ?></p><?php endif; ?>

    <section class="tarjeta">
        <h2><?= $editar ? 'Editar plato' : 'Nuevo plato' ?></h2>
        <?php if (!$categorias || !$ingredientes): ?>
            <p>Primero crea al menos una <a href="categorias.php">categoría</a> y un <a href="ingredientes.php">ingrediente</a>.</p>
        <?php else: ?>
        <form method="post" action="platos.php" class="formulario">
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_plato" value="<?= (int) $editar ?>">
            <label>
                Nombre
                <input type="text" name="nombre" value="<?= e($form['nombre']) ?>" maxlength="<?= MAX_NOMBRE ?>"
                       placeholder="Ej: Porotos con riendas" required>
            </label>
            <label>
                Categoría
                <select name="id_categoria" required>
                    <option value="">Elegir…</option>
                    <?php foreach ($categorias as $idCat => $nombreCat): ?>
                        <option value="<?= (int) $idCat ?>" <?= (int) $idCat === $form['id_categoria'] ? 'selected' : '' ?>>
                            <?= e($nombreCat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Precio ($)
                <input type="text" name="precio" value="<?= e($form['precio']) ?>" inputmode="numeric"
                       autocomplete="off" placeholder="Ej: 4700 (0 = incluido)" required>
            </label>
            <label>
                Tiempo de preparación (min)
                <input type="number" name="minutos" value="<?= e($form['minutos']) ?>" min="1" max="<?= MAX_MINUTOS ?>"
                       placeholder="Ej: 5" required>
            </label>
            <label class="formulario__ancho">
                Descripción (opcional)
                <input type="text" name="descripcion" value="<?= e($form['descripcion']) ?>" maxlength="<?= MAX_DESCRIPCION ?>">
            </label>

            <fieldset class="formulario__ancho receta">
                <legend>Receta: cantidad por porción</legend>
                <p class="nota">Deja vacío lo que el plato no usa. Se acepta coma decimal (ej: 0,15 kg).</p>
                <?php foreach ($ingredientes as $ing): $idIng = (int) $ing['id_ingrediente']; ?>
                    <label class="receta__linea">
                        <span><?= e($ing['nombre']) ?></span>
                        <span class="receta__campo">
                            <input type="text" name="receta[<?= $idIng ?>]" value="<?= e($form['receta'][$idIng] ?? '') ?>"
                                   inputmode="decimal" autocomplete="off">
                            <?= e(unidadCorta($ing['unidad_medida'])) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <button class="btn btn--grande btn--listo" type="submit">Guardar plato</button>
            <?php if ($editar): ?><a class="cancelar" href="platos.php">Cancelar</a><?php endif; ?>
        </form>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <h2>Platos</h2>
        <div class="tabla-scroll">
            <table class="tabla">
                <thead><tr><th>Plato</th><th>Categoría</th><th>Precio</th><th>Min.</th><th>Receta</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($platos as $p): $id = (int) $p['id_plato']; ?>
                    <tr class="<?= $p['estado'] === 'inactivo' ? 'fila--inactiva' : '' ?>">
                        <td><strong><?= e($p['nombre']) ?></strong></td>
                        <td><?= e($p['nombre_categoria']) ?></td>
                        <td><?= $p['precio'] > 0 ? e(formatoPesos($p['precio'])) : 'Incluido' ?></td>
                        <td><?= (int) $p['tiempo_preparacion_minutos'] ?></td>
                        <td class="receta__resumen"><?= e(implode(' + ', $recetas[$id] ?? ['(sin receta)'])) ?></td>
                        <td><span class="etiqueta etiqueta--<?= e($p['estado']) ?>"><?= e($nombresEstado[$p['estado']] ?? $p['estado']) ?></span></td>
                        <td class="acciones">
                            <a class="btn btn--chico" href="platos.php?editar=<?= $id ?>">Editar</a>
                            <form method="post" action="platos.php"
                                  <?php if ($p['estado'] !== 'inactivo'): ?>onsubmit="return confirm('<?= $p['ventas'] > 0
                                      ? '¿Desactivar el plato? Tiene ventas, así que quedará inactivo.'
                                      : '¿Eliminar el plato y su receta?' ?>');"<?php endif; ?>>
                                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                                <input type="hidden" name="id_plato" value="<?= $id ?>">
                                <?php if ($p['estado'] === 'inactivo'): ?>
                                    <input type="hidden" name="accion" value="reactivar">
                                    <button class="btn btn--chico" type="submit">Reactivar</button>
                                <?php else: ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <button class="btn btn--chico btn--peligro" type="submit">
                                        <?= $p['ventas'] > 0 ? 'Desactivar' : 'Eliminar' ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
