<?php
/**
 * Categorías de platos (solo administrador): crear, renombrar y eliminar.
 * Una categoría con platos no se puede eliminar (la FK es RESTRICT): primero
 * hay que mover sus platos a otra categoría.
 */
require_once __DIR__ . '/includes/auth.php';
requerirRol(['administrador']);
$db = obtenerConexion();

const MAX_NOMBRE = 50;        // categorias_platos.nombre_categoria
const MAX_DESCRIPCION = 255;

$error  = '';
$editar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;
$form   = ['nombre' => '', 'descripcion' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) filter_input(INPUT_POST, 'accion');
    $id     = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT) ?: 0;
    $editar = $id;   // si hay error, el formulario sigue en modo edición

    if (!validarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif ($accion === 'eliminar') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM platos WHERE id_categoria = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $error = 'No se puede eliminar: la categoría tiene platos. Muévelos a otra categoría primero.';
        } else {
            try {
                $stmt = $db->prepare('DELETE FROM categorias_platos WHERE id_categoria = :id');
                $stmt->execute(['id' => $id]);
                $_SESSION['flash_catalogo'] = $stmt->rowCount() ? 'Categoría eliminada.' : 'La categoría ya no existe.';
                header('Location: categorias.php');
                exit;
            } catch (PDOException $ex) {
                // Si alguien le asignó un plato recién, la FK (RESTRICT) lo impide
                $error = 'No se pudo eliminar la categoría: tiene platos.';
                error_log('[GestiÓnate] ' . $ex->getMessage());
            }
        }
        $editar = 0;
    } elseif ($accion === 'guardar') {
        $form['nombre']      = trim((string) filter_input(INPUT_POST, 'nombre'));
        $form['descripcion'] = trim((string) filter_input(INPUT_POST, 'descripcion'));

        $existe = $db->prepare('SELECT COUNT(*) FROM categorias_platos WHERE id_categoria = :id');
        $existe->execute(['id' => $id]);

        if ($id && !$existe->fetchColumn()) {
            $error = 'La categoría ya no existe.';
            $editar = 0;
        } elseif ($form['nombre'] === '' || mb_strlen($form['nombre']) > MAX_NOMBRE) {
            $error = 'El nombre es obligatorio (máximo ' . MAX_NOMBRE . ' caracteres).';
        } elseif (mb_strlen($form['descripcion']) > MAX_DESCRIPCION) {
            $error = 'La descripción admite hasta ' . MAX_DESCRIPCION . ' caracteres.';
        } else {
            $datos = [
                'nombre'      => $form['nombre'],
                'descripcion' => $form['descripcion'] === '' ? null : $form['descripcion'],
            ];
            try {
                if ($id) {
                    $stmt = $db->prepare('UPDATE categorias_platos SET nombre_categoria = :nombre,
                                          descripcion = :descripcion WHERE id_categoria = :id');
                    $stmt->execute($datos + ['id' => $id]);
                } else {
                    $stmt = $db->prepare('INSERT INTO categorias_platos (nombre_categoria, descripcion)
                                          VALUES (:nombre, :descripcion)');
                    $stmt->execute($datos);
                }
                $_SESSION['flash_catalogo'] = 'Categoría "' . $form['nombre'] . '" guardada.';
                header('Location: categorias.php');   // patrón PRG
                exit;
            } catch (PDOException $ex) {
                // 1062 = nombre repetido (la columna es UNIQUE)
                $error = ($ex->errorInfo[1] ?? 0) === 1062 ? 'Ya existe una categoría con ese nombre.'
                                                           : 'No se pudo guardar la categoría.';
                error_log('[GestiÓnate] ' . $ex->getMessage());
            }
        }
    } else {
        $error = 'Acción no válida.';
    }
}

$categorias = $db->query(
    'SELECT c.id_categoria, c.nombre_categoria, c.descripcion, COUNT(p.id_plato) AS total_platos
     FROM categorias_platos c
     LEFT JOIN platos p ON p.id_categoria = c.id_categoria
     GROUP BY c.id_categoria, c.nombre_categoria, c.descripcion
     ORDER BY c.id_categoria'
)->fetchAll();
$categoriasPorId = array_column($categorias, null, 'id_categoria');

// Al entrar a editar (GET) se cargan los datos actuales en el formulario
if (!isset($categoriasPorId[$editar])) {
    $editar = 0;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $form = ['nombre'      => $categoriasPorId[$editar]['nombre_categoria'],
             'descripcion' => $categoriasPorId[$editar]['descripcion'] ?? ''];
}

$exito = $_SESSION['flash_catalogo'] ?? '';
unset($_SESSION['flash_catalogo']);

$tituloPagina    = 'Categorías';
$seccionCatalogo = 'categorias.php';
require __DIR__ . '/includes/header.php';
?>
<main class="contenedor">
    <h1>Categorías</h1>
    <?php require __DIR__ . '/includes/menu_catalogo.php'; ?>

    <?php if ($exito): ?><p class="aviso-ok"><?= e($exito) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alerta"><?= e($error) ?></p><?php endif; ?>

    <section class="tarjeta">
        <h2><?= $editar ? 'Editar categoría' : 'Nueva categoría' ?></h2>
        <form method="post" action="categorias.php" class="formulario">
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_categoria" value="<?= (int) $editar ?>">
            <label>
                Nombre
                <input type="text" name="nombre" value="<?= e($form['nombre']) ?>" maxlength="<?= MAX_NOMBRE ?>"
                       placeholder="Ej: Postres" required>
            </label>
            <label>
                Descripción (opcional)
                <input type="text" name="descripcion" value="<?= e($form['descripcion']) ?>"
                       maxlength="<?= MAX_DESCRIPCION ?>">
            </label>
            <button class="btn btn--grande btn--listo" type="submit">Guardar</button>
            <?php if ($editar): ?><a class="cancelar" href="categorias.php">Cancelar</a><?php endif; ?>
        </form>
    </section>

    <section class="tarjeta">
        <h2>Categorías actuales</h2>
        <div class="tabla-scroll">
            <table class="tabla">
                <thead><tr><th>Nombre</th><th>Descripción</th><th>Platos</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categorias as $c): $id = (int) $c['id_categoria']; ?>
                    <tr>
                        <td><strong><?= e($c['nombre_categoria']) ?></strong></td>
                        <td><?= e($c['descripcion'] ?? '') ?></td>
                        <td><?= (int) $c['total_platos'] ?></td>
                        <td class="acciones">
                            <a class="btn btn--chico" href="categorias.php?editar=<?= $id ?>">Editar</a>
                            <?php if ((int) $c['total_platos'] === 0): ?>
                                <form method="post" action="categorias.php"
                                      onsubmit="return confirm('¿Eliminar la categoría?');">
                                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id_categoria" value="<?= $id ?>">
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
