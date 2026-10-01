<?php
/* Licencias: el empleado pide, RRHH aprueba o rechaza. Una vez resuelta
   no se toca más, queda de historial con quién decidió y cuándo. */

require_once 'includes/proteger.php';
require_once 'config/conexion.php';

$es_rrhh     = (($_SESSION['rol'] ?? '') === 'rrhh');
$id_empleado = (int) $_SESSION['id_empleado'];
$id_usuario  = (int) $_SESSION['id_usuario'];

/* Los tipos salen de la base así el máximo de días no queda escrito
   a mano en el código. */
$tipos = $conexion->query(
    "SELECT id_tipo, nombre, dias_maximos, con_goce, exige_justificativo
       FROM tipos_licencia WHERE activo = 1 ORDER BY nombre"
)->fetchAll();

$errores = [];
$form    = ['id_tipo' => '', 'fecha_desde' => '', 'fecha_hasta' => '', 'motivo' => ''];


/* Solicitar, aprobar, rechazar y cancelar viven en otro archivo. */

require_once 'includes/acciones-licencias.php';


$avisos = [
    'solicitada'   => ['ok',    'Tu solicitud quedó registrada y espera respuesta de Recursos Humanos.'],
    'aprobada'     => ['ok',    'La licencia fue aprobada.'],
    'rechazada'    => ['ok',    'La licencia fue rechazada y el motivo quedó registrado.'],
    'cancelada'    => ['ok',    'La solicitud se canceló.'],
    'falta_motivo' => ['error', 'Para rechazar una licencia hay que escribir el motivo.'],
    'ya_resuelta'  => ['aviso', 'Esa solicitud ya había sido resuelta por otra persona.'],
    'sin_archivo'  => ['aviso', 'Ese justificativo no está disponible.'],
    'archivo_enorme' => ['error', 'El archivo que adjuntaste es demasiado grande. El máximo es 4 MB.'],
];

$aviso = $avisos[$_GET['msg'] ?? ''] ?? null;


/* RRHH ve todas las pendientes; el empleado, solo las suyas. */
$sql_pend = "
    SELECT l.id_licencia, l.fecha_solicitud, l.fecha_desde, l.fecha_hasta,
           l.dias_solicitados, l.motivo,
           l.justificativo, l.justificativo_nombre,
           CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           e.legajo, t.nombre AS tipo, t.con_goce,
           DATEDIFF(CURDATE(), l.fecha_solicitud) AS dias_esperando
      FROM licencias l
      INNER JOIN empleados      e ON e.id_empleado = l.id_empleado
      INNER JOIN tipos_licencia t ON t.id_tipo     = l.id_tipo
     WHERE l.estado = 'pendiente'
       " . ($es_rrhh ? "" : "AND l.id_empleado = :id") . "
     ORDER BY l.fecha_solicitud ASC";

$c = $conexion->prepare($sql_pend);
$c->execute($es_rrhh ? [] : [':id' => $id_empleado]);
$pendientes = $c->fetchAll();

$f_estado   = $_GET['estado'] ?? 'todas';
$f_empleado = $es_rrhh ? (int) ($_GET['empleado'] ?? 0) : $id_empleado;

$cond = ["l.estado <> 'pendiente'"];
$par  = [];

if (in_array($f_estado, ['aprobada', 'rechazada'], true)) {
    $cond[] = "l.estado = :estado";
    $par[':estado'] = $f_estado;
}
if ($f_empleado > 0) {
    $cond[] = "l.id_empleado = :emp";
    $par[':emp'] = $f_empleado;
}

$sql_hist = "
    SELECT l.id_licencia, l.fecha_solicitud, l.fecha_desde, l.fecha_hasta,
           l.dias_solicitados, l.motivo, l.estado, l.fecha_resolucion, l.comentario,
           l.justificativo, l.justificativo_nombre,
           CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           t.nombre AS tipo,
           CONCAT(r.apellido, ', ', r.nombre) AS resuelta_por
      FROM licencias l
      INNER JOIN empleados      e ON e.id_empleado = l.id_empleado
      INNER JOIN tipos_licencia t ON t.id_tipo     = l.id_tipo
      LEFT  JOIN usuarios       u ON u.id_usuario  = l.id_usuario_resuelve
      LEFT  JOIN empleados      r ON r.id_empleado = u.id_empleado
     WHERE " . implode(' AND ', $cond) . "
     ORDER BY l.fecha_resolucion DESC, l.id_licencia DESC";

$c = $conexion->prepare($sql_hist);
$c->execute($par);
$historial = $c->fetchAll();

$lista_empleados = [];
if ($es_rrhh) {
    $lista_empleados = $conexion->query(
        "SELECT id_empleado, CONCAT(apellido, ', ', nombre) AS nombre
           FROM empleados WHERE activo = 1 ORDER BY apellido, nombre"
    )->fetchAll();
}

$pagina_activa = 'licencias';
$titulo        = 'Licencias';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado">
        <div>
            <h2>Licencias</h2>
            <p>
                <?= $es_rrhh
                    ? 'Solicitudes del personal y resolución de las pendientes.'
                    : 'Pedí una licencia y seguí el estado de tus solicitudes.' ?>
            </p>
        </div>
    </div>

    <?php if ($aviso): ?>
    <div class="alerta alerta--<?= $aviso[0] === 'ok' ? 'ok' : ($aviso[0] === 'error' ? 'error' : 'aviso') ?>">
        <span><?= limpio($aviso[1]) ?></span>
    </div>
    <?php endif; ?>


    <div class="caja">
        <div class="caja__titulo">
            <h3><?= $es_rrhh ? 'Solicitudes por resolver' : 'Mis solicitudes pendientes' ?></h3>
            <span class="texto-suave"><?= count($pendientes) ?></span>
        </div>

        <?php if (count($pendientes) === 0): ?>
            <p class="vacio">No hay solicitudes esperando respuesta.</p>
        <?php else: ?>

        <!-- Lista y no tabla: acá no se comparan solicitudes, se lee una
             y se decide, y lo que hace decidir es el motivo que escribió
             la persona. En una celda ese texto queda espachurrado y el
             form de resolver estiraba la fila entera. -->
        <ul class="pedidos">
            <?php foreach ($pendientes as $l): ?>
            <li class="pedido">

                <div class="pedido__datos">

                    <?php if ($es_rrhh): ?>
                    <div class="pedido__quien">
                        <b><?= limpio($l['empleado']) ?></b>
                        <span><?= limpio($l['legajo']) ?></span>
                    </div>
                    <?php endif; ?>

                    <p class="pedido__que">
                        <?= limpio($l['tipo']) ?><?php if (!$l['con_goce']): ?>
                            <span class="texto-suave">(sin goce de sueldo)</span>
                        <?php endif; ?>
                        &middot; del <b><?= date('d/m/Y', strtotime($l['fecha_desde'])) ?></b>
                        al <b><?= date('d/m/Y', strtotime($l['fecha_hasta'])) ?></b>
                        &middot; <b><?= (int) $l['dias_solicitados'] ?></b>
                        <?= (int) $l['dias_solicitados'] === 1 ? 'día' : 'días' ?>
                    </p>

                    <?php if (!empty($l['motivo'])): ?>
                    <!-- Entrecomillado porque son palabras del empleado. -->
                    <p class="pedido__motivo">&ldquo;<?= limpio($l['motivo']) ?>&rdquo;</p>
                    <?php endif; ?>

                    <?php if (!empty($l['justificativo'])): ?>
                    <!-- El link no apunta al archivo sino a descargar.php
                         con el id de la licencia: ahí se chequea si quien
                         pide lo tiene permitido ver. -->
                    <p class="pedido__adjunto">
                        <a href="descargar.php?id=<?= (int) $l['id_licencia'] ?>" target="_blank">
                            Ver el justificativo adjunto
                        </a>
                        <span><?= limpio($l['justificativo_nombre'] ?? '') ?></span>
                    </p>
                    <?php endif; ?>

                    <p class="pedido__espera">
                        <?php $espera = (int) $l['dias_esperando']; ?>
                        <?php if ($espera >= 5): ?>
                            <!-- Negrita y sin color, así se nota impreso. -->
                            <strong>Pedida hace <?= $espera ?> días</strong>
                        <?php elseif ($espera === 1): ?>
                            Pedida ayer
                        <?php elseif ($espera > 0): ?>
                            Pedida hace <?= $espera ?> días
                        <?php else: ?>
                            Pedida hoy
                        <?php endif; ?>
                    </p>
                </div>


                <div class="pedido__accion">
                    <?php if ($es_rrhh): ?>

                    <!-- Un form con dos botones: cada uno manda su propio
                         valor en accion y el comentario sirve para los dos. -->
                    <form method="POST" action="licencias.php" class="pedido__resolver">
                        <input type="hidden" name="id_licencia" value="<?= (int) $l['id_licencia'] ?>">

                        <input type="text" name="comentario" maxlength="300"
                               placeholder="Comentario (obligatorio si rechazás)">

                        <div class="pedido__botones">
                            <button type="submit" name="accion" value="aprobar"
                                    class="boton boton--primario boton--chico">Aprobar</button>

                            <!-- Sin rojo: lo que frena un rechazo por error
                                 es que se exija el motivo, no el color. -->
                            <button type="submit" name="accion" value="rechazar"
                                    class="boton boton--secundario boton--chico">Rechazar</button>
                        </div>
                    </form>

                    <?php else: ?>
                    <form method="POST" action="licencias.php"
                          onsubmit="return confirm('¿Cancelar esta solicitud?');">
                        <input type="hidden" name="accion" value="cancelar">
                        <input type="hidden" name="id_licencia" value="<?= (int) $l['id_licencia'] ?>">
                        <button type="submit" class="boton boton--secundario boton--chico">
                            Cancelar solicitud
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>


    <div class="caja">
        <div class="caja__titulo">
            <h3>Solicitar una licencia</h3>
            <span class="texto-suave">A nombre de <?= limpio($_SESSION['nombre']) ?></span>
        </div>

        <div class="panel__cuerpo">
            <!-- Sin el enctype el navegador manda solo el nombre del
                 archivo y llega vacío. Nos pasó y perdimos un rato. -->
            <form method="POST" action="licencias.php" novalidate
                  enctype="multipart/form-data">
                <input type="hidden" name="accion" value="solicitar">

                <div class="formulario-grilla">

                    <div class="campo">
                        <label for="id_tipo">Tipo de licencia *</label>
                        <select id="id_tipo" name="id_tipo"
                                class="<?= isset($errores['id_tipo']) ? 'error' : '' ?>">
                            <option value="">Elegí una opción</option>
                            <?php foreach ($tipos as $t): ?>
                            <option value="<?= (int) $t['id_tipo'] ?>"
                                <?= $form['id_tipo'] === (string) $t['id_tipo'] ? 'selected' : '' ?>>
                                <?= limpio($t['nombre']) ?><?php
                                    if ($t['dias_maximos'] !== null) {
                                        echo ' (hasta ' . (int) $t['dias_maximos'] . ' días)';
                                    }
                                    if (!$t['con_goce']) { echo ' — sin goce'; }
                                ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__error"><?= limpio($errores['id_tipo'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="motivo_input">Motivo</label>
                        <input type="text" id="motivo_input" name="motivo" maxlength="300"
                               class="<?= isset($errores['motivo']) ? 'error' : '' ?>"
                               value="<?= limpio($form['motivo']) ?>"
                               placeholder="Opcional, pero ayuda a que te la aprueben">
                        <span class="campo__error"><?= limpio($errores['motivo'] ?? '') ?></span>
                    </div>

                    <!-- accept es comodidad nada más, se esquiva en dos
                         clics. La validación en serio la hace el servidor
                         mirando los primeros bytes del archivo. -->
                    <div class="campo campo--ancho">
                        <label for="justificativo">Justificativo</label>
                        <input type="file" id="justificativo" name="justificativo"
                               class="<?= isset($errores['justificativo']) ? 'error' : '' ?>"
                               accept=".pdf,.jpg,.jpeg,.png">
                        <span class="campo__ayuda">
                            Certificado médico o comprobante. PDF, JPG o PNG, hasta 4 MB.
                            <?php
                            /* Sale de la base: si mañana cambia, el texto
                               de la pantalla cambia solo. */
                            $obligan = [];
                            foreach ($tipos as $t) {
                                if (!empty($t['exige_justificativo'])) {
                                    $obligan[] = $t['nombre'];
                                }
                            }
                            ?>
                            <?php if (count($obligan) > 0): ?>
                            <br><strong>Obligatorio para <?= limpio(implode(', ', $obligan)) ?>.</strong>
                            <?php endif; ?>
                        </span>
                        <span class="campo__error"><?= limpio($errores['justificativo'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="fecha_desde">Desde *</label>
                        <input type="date" id="fecha_desde" name="fecha_desde"
                               class="<?= isset($errores['fecha_desde']) ? 'error' : '' ?>"
                               value="<?= limpio($form['fecha_desde']) ?>">
                        <span class="campo__error"><?= limpio($errores['fecha_desde'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="fecha_hasta">Hasta *</label>
                        <input type="date" id="fecha_hasta" name="fecha_hasta"
                               class="<?= isset($errores['fecha_hasta']) ? 'error' : '' ?>"
                               value="<?= limpio($form['fecha_hasta']) ?>">
                        <span class="campo__error"><?= limpio($errores['fecha_hasta'] ?? '') ?></span>
                    </div>

                </div>

                <p class="texto-suave" style="margin-bottom:14px">
                    La cantidad de días la calcula el sistema a partir de las fechas.
                    Para una licencia de un solo día, poné la misma fecha en los dos campos.
                </p>

                <div class="formulario__acciones">
                    <button type="submit" class="boton boton--primario">Enviar solicitud</button>
                </div>
            </form>
        </div>
    </div>


    <div class="caja">
        <div class="caja__titulo">
            <h3><?= $es_rrhh ? 'Historial de licencias resueltas' : 'Mis licencias resueltas' ?></h3>
            <span class="texto-suave"><?= count($historial) ?> registros</span>
        </div>

        <form method="GET" action="licencias.php" class="filtros">
            <?php if ($es_rrhh): ?>
            <div class="filtros__grupo">
                <label for="empleado">Empleado</label>
                <select name="empleado" id="empleado">
                    <option value="0">Todos</option>
                    <?php foreach ($lista_empleados as $e): ?>
                    <option value="<?= (int) $e['id_empleado'] ?>"
                        <?= $f_empleado === (int) $e['id_empleado'] ? 'selected' : '' ?>>
                        <?= limpio($e['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filtros__grupo">
                <label for="estado">Resultado</label>
                <select name="estado" id="estado">
                    <option value="todas"     <?= $f_estado === 'todas'     ? 'selected' : '' ?>>Todas</option>
                    <option value="aprobada"  <?= $f_estado === 'aprobada'  ? 'selected' : '' ?>>Aprobadas</option>
                    <option value="rechazada" <?= $f_estado === 'rechazada' ? 'selected' : '' ?>>Rechazadas</option>
                </select>
            </div>

            <button type="submit" class="boton boton--primario">Filtrar</button>
            <a href="licencias.php" class="boton boton--secundario">Limpiar</a>
        </form>

        <!-- Cuatro columnas: el tipo y los días van abajo del nombre y
             quién resolvió abajo del resultado. -->
        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <th><?= $es_rrhh ? 'Empleado' : 'Licencia' ?></th>
                        <th>Período</th>
                        <th>Resultado</th>
                        <th>Comentario</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($historial) === 0): ?>
                    <tr>
                        <td colspan="4" class="vacio">
                            No hay licencias resueltas con esos criterios.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historial as $l): ?>
                    <tr>
                        <td>
                            <?php if ($es_rrhh): ?>
                                <strong><?= limpio($l['empleado']) ?></strong>
                            <?php else: ?>
                                <strong><?= limpio($l['tipo']) ?></strong>
                            <?php endif; ?>
                            <span class="dato-sub">
                                <?php if ($es_rrhh): ?><?= limpio($l['tipo']) ?> &middot; <?php endif; ?>
                                <?= (int) $l['dias_solicitados'] ?>
                                <?= (int) $l['dias_solicitados'] === 1 ? 'día' : 'días' ?>
                            </span>

                            <?php if (!empty($l['justificativo'])): ?>
                            <!-- El adjunto queda a mano también acá: es la
                                 prueba de por qué se aprobó. -->
                            <span class="dato-sub">
                                <a href="descargar.php?id=<?= (int) $l['id_licencia'] ?>" target="_blank">
                                    Ver justificativo
                                </a>
                            </span>
                            <?php endif; ?>
                        </td>

                        <td class="num nowrap">
                            <?= date('d/m/Y', strtotime($l['fecha_desde'])) ?>
                            al
                            <?= date('d/m/Y', strtotime($l['fecha_hasta'])) ?>
                        </td>

                        <!-- Sin colores, igual que en el resto del sistema. -->
                        <td class="nowrap">
                            <?php if ($l['estado'] === 'aprobada'): ?>
                                <strong>Aprobada</strong>
                            <?php else: ?>
                                <span class="dato-vacio">Rechazada</span>
                            <?php endif; ?>
                            <span class="dato-sub">
                                <?= $l['fecha_resolucion']
                                    ? date('d/m/Y', strtotime($l['fecha_resolucion'])) : '—' ?>
                                &middot; <?= limpio($l['resuelta_por'] ?? 'Sistema') ?>
                            </span>
                        </td>

                        <td class="texto-suave"><?= limpio($l['comentario'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once 'includes/pie.php'; ?>