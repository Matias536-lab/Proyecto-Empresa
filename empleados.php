<?php
/* Listado de personal con buscador y filtros, más baja y reactivación.
   El alta y la edición están en empleado-form.php, es el mismo form.
   La baja nunca borra: pone activo = 0, si no se van con el empleado
   todas sus fichadas y licencias y los reportes viejos quedan mal. */

require_once 'includes/proteger.php';
exigir_rol('rrhh');
require_once 'config/conexion.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion      = $_POST['accion'] ?? '';
    $id_empleado = (int) ($_POST['id_empleado'] ?? 0);

    if ($id_empleado > 0 && in_array($accion, ['baja', 'reactivar'], true)) {

        $nuevo_estado = ($accion === 'baja') ? 0 : 1;

        /* El propio id lo sacamos de la sesión, no del POST. Y nadie se
           da de baja a sí mismo: si el único de RRHH se desactiva, el
           sistema queda sin administrador y nadie puede entrar a arreglarlo. */
        if ($accion === 'baja' && $id_empleado === (int) $_SESSION['id_empleado']) {
            header('Location: empleados.php?msg=uno_mismo');
            exit;
        }

        $conexion->prepare(
            "UPDATE empleados SET activo = :estado WHERE id_empleado = :id"
        )->execute([':estado' => $nuevo_estado, ':id' => $id_empleado]);

        /* El usuario tiene que seguir al empleado. Si lo dábamos de baja y
           le dejábamos el usuario activo, seguía entrando lo más bien. */
        $conexion->prepare(
            "UPDATE usuarios SET activo = :estado WHERE id_empleado = :id"
        )->execute([':estado' => $nuevo_estado, ':id' => $id_empleado]);

        header('Location: empleados.php?msg=' . ($accion === 'baja' ? 'baja_ok' : 'alta_ok'));
        exit;
    }
}


$avisos = [
    'creado'    => ['ok',    'El empleado se dio de alta correctamente.'],
    'editado'   => ['ok',    'Los datos se guardaron correctamente.'],
    'baja_ok'   => ['ok',    'El empleado quedó dado de baja y ya no puede ingresar al sistema.'],
    'alta_ok'   => ['ok',    'El empleado fue reactivado.'],
    'uno_mismo' => ['error', 'No podés darte de baja a vos mismo.'],
];

$aviso = $avisos[$_GET['msg'] ?? ''] ?? null;


/* Los filtros van por GET para que la búsqueda quede en la dirección y se
   pueda compartir o guardar el link. */

$buscar  = trim($_GET['buscar'] ?? '');
$f_area  = (int) ($_GET['area'] ?? 0);
$f_estado = $_GET['estado'] ?? 'activos';   /* activos | inactivos | todos */

$condiciones = [];
$parametros  = [];

if ($f_estado === 'activos')   { $condiciones[] = "e.activo = 1"; }
if ($f_estado === 'inactivos') { $condiciones[] = "e.activo = 0"; }

if ($f_area > 0) {
    $condiciones[] = "e.id_area = :area";
    $parametros[':area'] = $f_area;
}

if ($buscar !== '') {

    /* Sin los paréntesis, el OR se mezcla con las otras condiciones y el
       filtro de estado deja de aplicarse.

       Los seis marcadores van con nombres distintos porque con :b repetido
       la búsqueda explota con "Invalid parameter number": por el
       EMULATE_PREPARES => false de config/conexion.php prepara MySQL y no
       acepta el mismo marcador dos veces. Igual que en reportes.php.

       :b5 y :b6 son para el nombre completo, si no "Paz Javier" no cae en
       ninguna columna. Van los dos órdenes porque cada uno lo escribe al
       revés del otro. */

    $condiciones[] = "(e.nombre     LIKE :b1
                       OR e.apellido LIKE :b2
                       OR e.legajo   LIKE :b3
                       OR e.dni      LIKE :b4
                       OR CONCAT(e.apellido, ' ', e.nombre) LIKE :b5
                       OR CONCAT(e.nombre, ' ', e.apellido) LIKE :b6)";

    $texto = '%' . $buscar . '%';

    $parametros[':b1'] = $texto;
    $parametros[':b2'] = $texto;
    $parametros[':b3'] = $texto;
    $parametros[':b4'] = $texto;
    $parametros[':b5'] = $texto;
    $parametros[':b6'] = $texto;
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "
    SELECT e.id_empleado, e.legajo, e.nombre, e.apellido, e.dni,
           e.email, e.cargo, e.fecha_ingreso, e.activo,
           a.nombre AS area,
           u.usuario, u.rol, u.activo AS usuario_activo
      FROM empleados e
      LEFT JOIN areas    a ON a.id_area     = e.id_area
      LEFT JOIN usuarios u ON u.id_empleado = e.id_empleado
      $where
     ORDER BY e.apellido, e.nombre";

$consulta = $conexion->prepare($sql);
$consulta->execute($parametros);
$empleados = $consulta->fetchAll();

$total_activos = 0;
foreach ($empleados as $e) {
    if ($e['activo']) { $total_activos++; }
}

$areas = $conexion->query(
    "SELECT id_area, nombre FROM areas ORDER BY nombre"
)->fetchAll();


/* Esto no filtra nada, solo junta los filtros puestos para poder decirle en
   pantalla con qué estuvo buscando. Los tres se combinan con Y, así que
   "Giménez" + área Ventas no devuelve nada aunque Giménez exista, y sin el
   cartel la persona no sabe si se equivocó ella o si el sistema falló. */

$filtros_puestos = [];

if ($buscar !== '') {
    $filtros_puestos[] = 'el texto «' . $buscar . '»';
}

if ($f_area > 0) {
    foreach ($areas as $a) {
        if ((int) $a['id_area'] === $f_area) {
            $filtros_puestos[] = 'el área ' . $a['nombre'];
        }
    }
}

/* "Todos" no se nombra, ése no filtra nada y quedaba raro leerlo. */
if ($f_estado === 'activos')   { $filtros_puestos[] = 'el estado Activos'; }
if ($f_estado === 'inactivos') { $filtros_puestos[] = 'el estado Dados de baja'; }

$pagina_activa = 'empleados';
$titulo        = 'Empleados';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado">
        <div>
            <h2>Empleados</h2>
            <p>Legajos del personal de la empresa.</p>
        </div>
        <a href="empleado-form.php" class="boton boton--primario">Nuevo empleado</a>
    </div>

    <?php if ($aviso): ?>
    <div class="alerta alerta--<?= $aviso[0] === 'ok' ? 'ok' : 'error' ?>">
        <span><?= limpio($aviso[1]) ?></span>
    </div>
    <?php endif; ?>

    <div class="caja">
        <div class="caja__titulo">
            <h3>Listado</h3>
            <span class="texto-suave"><?= count($empleados) ?> resultados</span>
        </div>

        <form method="GET" action="empleados.php" class="filtros">

            <div class="filtros__buscador">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" name="buscar" value="<?= limpio($buscar) ?>"
                       placeholder="Buscar por nombre, apellido, legajo o DNI...">
            </div>

            <div class="filtros__grupo">
                <label for="area">Área</label>
                <select name="area" id="area">
                    <option value="0">Todas</option>
                    <?php foreach ($areas as $a): ?>
                    <option value="<?= (int) $a['id_area'] ?>"
                        <?= $f_area === (int) $a['id_area'] ? 'selected' : '' ?>>
                        <?= limpio($a['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filtros__grupo">
                <label for="estado">Estado</label>
                <select name="estado" id="estado">
                    <option value="activos"   <?= $f_estado === 'activos'   ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $f_estado === 'inactivos' ? 'selected' : '' ?>>Dados de baja</option>
                    <option value="todos"     <?= $f_estado === 'todos'     ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>

            <button type="submit" class="boton boton--primario">Buscar</button>
            <a href="empleados.php" class="boton boton--secundario">Limpiar</a>
        </form>

        <!-- El DNI y la fecha de ingreso no están en la tabla, se ven en la
             ficha. El buscador igual sigue buscando por DNI. -->
        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <th>Legajo</th>
                        <th>Empleado</th>
                        <th>Acceso al sistema</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($empleados) === 0): ?>
                    <tr>
                        <td colspan="5" class="vacio">

                            <p class="vacio__titulo">No se encontró ningún empleado.</p>

                            <?php if (count($filtros_puestos) > 0): ?>
                            <p class="vacio__detalle">
                                Se buscó con
                                <?php
                                /* Para que quede "a, b y c" y no "a, b, c". */
                                $ultimo = array_pop($filtros_puestos);
                                if (count($filtros_puestos) > 0) {
                                    echo limpio(implode(', ', $filtros_puestos)) . ' y ';
                                }
                                echo limpio($ultimo);
                                ?>.
                                <br>
                                Las condiciones se suman: tienen que cumplirse
                                <strong>todas a la vez</strong>.
                            </p>

                            <p class="vacio__detalle">
                                <a href="empleados.php">Quitar los filtros y ver todo el personal</a>
                            </p>
                            <?php endif; ?>

                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($empleados as $e): ?>

                    <!-- El dado de baja va en gris, igual que la caja apagada
                         del panel: se ve, pero no compite con los activos. -->
                    <tr class="<?= $e['activo'] ? '' : 'fila-baja' ?>">

                        <td class="num texto-suave nowrap"><?= limpio($e['legajo']) ?></td>

                        <td>
                            <strong><?= limpio($e['apellido'] . ', ' . $e['nombre']) ?></strong>
                            <span class="dato-sub">
                                <?= limpio($e['cargo'] ?? 'Sin cargo') ?>
                                &middot;
                                <?= limpio($e['area'] ?? 'Sin área') ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($e['usuario'] === null): ?>
                                <!-- No todos tienen cuenta, el que ficha en el
                                     reloj de planta no entra nunca al sistema. -->
                                <span class="dato-vacio">Sin usuario</span>
                            <?php else: ?>
                                <code><?= limpio($e['usuario']) ?></code>
                                <span class="dato-sub">
                                    <?= $e['rol'] === 'rrhh' ? 'Recursos Humanos' : 'Empleado' ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="nowrap">
                            <?php if ($e['activo']): ?>
                                Activo
                            <?php else: ?>
                                <span class="dato-vacio">Dado de baja</span>
                            <?php endif; ?>
                        </td>

                        <td class="nowrap">
                            <div class="form-linea">
                                <a href="empleado-form.php?id=<?= (int) $e['id_empleado'] ?>"
                                   class="boton boton--secundario boton--chico">Editar</a>

                                <!-- La baja va por POST y no por link: modifica
                                     datos, y con un link el navegador la podría
                                     disparar solo al precargar la página. -->
                                <form method="POST" action="empleados.php"
                                      onsubmit="return confirm('<?= $e['activo']
                                          ? '¿Dar de baja a este empleado? No va a poder ingresar al sistema.'
                                          : '¿Reactivar a este empleado?' ?>');">
                                    <input type="hidden" name="id_empleado" value="<?= (int) $e['id_empleado'] ?>">
                                    <input type="hidden" name="accion" value="<?= $e['activo'] ? 'baja' : 'reactivar' ?>">
                                    <button type="submit" class="boton boton--secundario boton--chico">
                                        <?= $e['activo'] ? 'Dar de baja' : 'Reactivar' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="totales">
            <span>En el listado: <b><?= count($empleados) ?></b></span>
            <span>Activos: <b><?= $total_activos ?></b></span>
            <span>Dados de baja: <b><?= count($empleados) - $total_activos ?></b></span>
        </div>
    </div>

</main>

<?php require_once 'includes/pie.php'; ?>