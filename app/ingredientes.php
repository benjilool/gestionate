<?php
/**
 * Ingredientes (solo administrador): crear, editar y eliminar.
 * - El stock NO se edita aquí: solo cambia con los procedimientos (entradas y
 *   ventas), para que todo quede en movimientos_inventario.
 * - La unidad se bloquea si el ingrediente ya está en recetas o tiene historial:
 *   cambiarla haría que "0,15" pase de kg a gr y las cantidades dejen de calzar.
 * - Solo se elimina si no está en ninguna receta ni tiene movimientos.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formato.php';
requerirRol(['administrador']);
$db = obtenerConexion();

const MAX_NOMBRE = 100;       // ingredientes.nombre
const MAX_MINIMO = 9999;
const UNIDADES = ['kg', 'gr', 'litro', 'ml', 'unidad'];   // mismo ENUM de la tabla

/** Lista de ingredientes con cuántas recetas y movimientos tiene cada uno. */
function cargarIngredientes(PDO $db): array
{
    $filas = $db->query(
        'SELECT i.id_ingrediente, i.nombre, i.unidad_medida, i.stock_actual, i.stock_minimo_alerta,
                (SELECT COUNT(*) FROM plato_ingredientes pi WHERE pi.id_ingrediente = i.id_ingrediente) AS en_recetas,
                (SELECT COUNT(*) FROM movimientos_inventario m WHERE m.id_ingrediente = i.id_ingrediente) AS movimientos
         FROM ingredientes i
         ORDER BY i.nombre'
    )->fetchAll();
    return array_column($filas, null, 'id_ingrediente');
}

$ingredientes = cargarIngredientes($db);

$error  = '';
$editar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;
$form   = ['nombre' => '', 'unidad' => 'kg', 'minimo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) filter_input(INPUT_POST, 'accion');
    $id     = filter_input(INPUT_POST, 'id_ingrediente', FILTER_VALIDATE_INT) ?: 0;
    $actual = $ingredientes[$id] ?? null;
    $enUso  = $actual && ($actual['en_recetas'] > 0 || $actual['movimientos'] > 0);
    $editar = $actual ? $id : 0;

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif ($id && !$actual) {
        $error = 'El ingrediente ya no existe.';
    } elseif ($accion === 'eliminar') {
        $editar = 0;
        if (!$actual) {
            $error = 'Acción no válida.';
        } elseif ($enUso) {
            $error = 'No se puede eliminar "' . $actual['nombre'] . '": está en recetas o tiene historial de movimientos.';
        } else {
            try {
                $stmt = $db->prepare('DELETE FROM ingredientes WHERE id_ingrediente = :id');
                $stmt->execute(['id' => $id]);
                $_SESSION['flash_catalogo'] = 'Ingrediente "' . $actual['nombre'] . '" eliminado.';
                header('Location: ingredientes.php');
                exit;
            } catch (PDOException $ex) {
                // Si alguien lo agregó a una receta recién, la FK (RESTRICT) lo impide
                $error = 'No se pudo eliminar el ingrediente: está en uso.';
                error_log('[GestiÓnate] ' . $ex->getMessage());
            }
        }
    } elseif ($accion === 'guardar') {
        $form['nombre'] = trim((string) filter_input(INPUT_POST, 'nombre'));
        $form['unidad'] = (string) filter_input(INPUT_POST, 'unidad_medida');
        $form['minimo'] = trim((string) filter_input(INPUT_POST, 'stock_minimo'));
        $minimo = leerDecimal($form['minimo']);

        // Si la unidad está bloqueada, se ignora lo que venga en el formulario
        if ($enUso) {
            $form['unidad'] = $actual['unidad_medida'];
        }

        if ($form['nombre'] === '' || mb_strlen($form['nombre']) > MAX_NOMBRE) {
            $error = 'El nombre es obligatorio (máximo ' . MAX_NOMBRE . ' caracteres).';
        } elseif (!in_array($form['unidad'], UNIDADES, true)) {
            $error = 'Elige una unidad de medida.';
        } elseif ($minimo === null || $minimo < 0 || $minimo > MAX_MINIMO) {
            $error = 'El stock mínimo debe ser un número entre 0 y ' . MAX_MINIMO . ' (ej: 0,5).';
        } else {
            $datos = ['nombre' => $form['nombre'], 'unidad' => $form['unidad'], 'minimo' => $minimo];
            try {
                if ($id) {
                    $stmt = $db->prepare('UPDATE ingredientes SET nombre = :nombre, unidad_medida = :unidad,
                                          stock_minimo_alerta = :minimo WHERE id_ingrediente = :id');
                    $stmt->execute($datos + ['id' => $id]);
                    $_SESSION['flash_catalogo'] = 'Ingrediente "' . $form['nombre'] . '" actualizado.';
                } else {
                    // Parte con stock 0: el stock inicial se carga como entrada en Inventario
                    $stmt = $db->prepare('INSERT INTO ingredientes (nombre, unidad_medida, stock_minimo_alerta)
                                          VALUES (:nombre, :unidad, :minimo)');
                    $stmt->execute($datos);
                    $_SESSION['flash_catalogo'] = 'Ingrediente "' . $form['nombre']
                        . '" creado con stock 0. Carga el stock inicial en Inventario.';
                }
                header('Location: ingredientes.php');   // patrón PRG
                exit;
            } catch (PDOException $ex) {
                $error = ($ex->errorInfo[1] ?? 0) === 1062 ? 'Ya existe un ingrediente con ese nombre.'
                                                           : 'No se pudo guardar el ingrediente.';
                error_log('[GestiÓnate] ' . $ex->getMessage());
            }
        }
    } else {
        $error = 'Acción no válida.';
    }
}

// Al entrar a editar (GET) se cargan los datos actuales en el formulario
if (!isset($ingredientes[$editar])) {
    $editar = 0;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $i = $ingredientes[$editar];
    $form = ['nombre' => $i['nombre'], 'unidad' => $i['unidad_medida'],
             'minimo' => formatoCantidad($i['stock_minimo_alerta'])];
}
$unidadBloqueada = $editar && ($ingredientes[$editar]['en_recetas'] > 0 || $ingredientes[$editar]['movimientos'] > 0);

$exito = $_SESSION['flash_catalogo'] ?? '';
unset($_SESSION['flash_catalogo']);

$tituloPagina    = 'Ingredientes';
$seccionCatalogo = 'ingredientes.php';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Ingredientes</h1>
    <?php require __DIR__ . '/includes/menu_catalogo.php'; ?>

    <?php if ($exito): ?><p class="aviso-ok"><?= e($exito) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alerta"><?= e($error) ?></p><?php endif; ?>

    <section class="tarjeta">
        <h2><?= $editar ? 'Editar ingrediente' : 'Nuevo ingrediente' ?></h2>
        <form method="post" action="ingredientes.php" class="formulario">
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_ingrediente" value="<?= (int) $editar ?>">
            <label>
                Nombre
                <input type="text" name="nombre" value="<?= e($form['nombre']) ?>" maxlength="<?= MAX_NOMBRE ?>"
                       placeholder="Ej: Zapallo" required>
            </label>
            <label>
                Unidad de medida
                <select name="unidad_medida" <?= $unidadBloqueada ? 'disabled' : '' ?>>
                    <?php foreach (UNIDADES as $u): ?>
                        <option value="<?= e($u) ?>" <?= $u === $form['unidad'] ? 'selected' : '' ?>><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($unidadBloqueada): ?>
                    <small class="ayuda">No se puede cambiar: ya está en recetas o tiene movimientos.</small>
                <?php endif; ?>
            </label>
            <label>
                Stock mínimo (alerta)
                <input type="text" name="stock_minimo" value="<?= e($form['minimo']) ?>" inputmode="decimal"
                       autocomplete="off" placeholder="Ej: 0,5" required>
            </label>
            <button class="btn btn--grande btn--listo" type="submit">Guardar</button>
            <?php if ($editar): ?><a class="cancelar" href="ingredientes.php">Cancelar</a><?php endif; ?>
        </form>
    </section>

    <section class="tarjeta">
        <h2>Ingredientes actuales</h2>
        <p class="nota">El stock se modifica en <a href="inventario.php">Inventario</a> (entradas) y con cada venta.</p>
        <div class="tabla-scroll">
            <table class="tabla">
                <thead><tr><th>Nombre</th><th>Stock</th><th>Mínimo</th><th>En recetas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($ingredientes as $i):
                    $id = (int) $i['id_ingrediente'];
                    $u  = unidadCorta($i['unidad_medida']);
                    $puedeEliminar = $i['en_recetas'] == 0 && $i['movimientos'] == 0; ?>
                    <tr>
                        <td><strong><?= e($i['nombre']) ?></strong></td>
                        <td><?= e(formatoCantidad($i['stock_actual']) . ' ' . $u) ?></td>
                        <td><?= e(formatoCantidad($i['stock_minimo_alerta']) . ' ' . $u) ?></td>
                        <td><?= (int) $i['en_recetas'] ?></td>
                        <td class="acciones">
                            <a class="btn btn--chico" href="ingredientes.php?editar=<?= $id ?>">Editar</a>
                            <?php if ($puedeEliminar): ?>
                                <form method="post" action="ingredientes.php"
                                      onsubmit="return confirm('¿Eliminar el ingrediente?');">
                                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id_ingrediente" value="<?= $id ?>">
                                    <button class="btn btn--chico btn--peligro" type="submit">Eliminar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
