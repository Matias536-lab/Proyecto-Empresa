<?php
/* Asistencia: el empleado ficha entrada y salida y ve su historial.
   RRHH ve el de todos y además puede cerrar las jornadas que quedaron
   abiertas. El id del empleado sale siempre de $_SESSION y nunca del
   formulario: si viniera de afuera cualquiera ficharía por otro. */

require_once 'includes/proteger.php';
require_once 'config/conexion.php';

$es_rrhh     = (($_SESSION['rol'] ?? '') === 'rrhh');
$id_empleado = (int) $_SESSION['id_empleado'];


/* Fichar y cerrar jornadas viven aparte, así este archivo queda solo
   con los datos y la pantalla. */

require_once 'includes/acciones-asistencia.php';


/* Cada acción redirige con un código corto y acá lo pasamos a texto. */

$avisos = [
    'entrada_ok' => ['ok',    'Entrada registrada correctamente.'],
    'salida_ok'  => ['ok',    'Salida registrada. Jornada cerrada.'],
    'cerrada_ok' => ['ok',    'La jornada quedó cerrada.'],
    'ya_ficho'   => ['aviso', 'Ya habías registrado tu entrada de hoy.'],
    'sin_abrir'  => ['aviso', 'No hay ninguna jornada abierta para cerrar.'],
    'hora_mala'  => ['error', 'La hora ingresada no es válida.'],
    'hora_menor' => ['error', 'La salida no puede ser anterior a la entrada.'],
    'no_existe'  => ['error', 'Esa jornada ya fue cerrada por otra persona.'],
];

$aviso = $avisos[$_GET['msg'] ?? ''] ?? null;


$consulta = $conexion->prepare(
    "SELECT id_fichada, hora_entrada, hora_salida
       FROM fichadas
      WHERE id_empleado = :id AND fecha = CURDATE()"
);
$consulta->execute([':id' => $id_empleado]);
$mi_fichada = $consulta->fetch();

if (!$mi_fichada) {
    $mi_estado = 'sin_entrada';
} elseif ($mi_fichada['hora_salida'] === null) {
    $mi_estado = 'adentro';
} else {
    $mi_estado = 'cerrada';
}

/* El primer valor del contador lo saca PHP de la hora del servidor; el
   JS después lo hace correr. Si el JS no anda el número igual queda
   bien, y si cerrás el navegador y volvés no arranca de cero.
   Trabajamos todo en segundos para no pelearnos sumando horas. */

$ahora_seg = ((int) date('H')) * 3600 + ((int) date('i')) * 60 + (int) date('s');

function en_segundos($hora)
{
    $p = explode(':', (string) $hora);
    return ((int) ($p[0] ?? 0)) * 3600 + ((int) ($p[1] ?? 0)) * 60 + (int) ($p[2] ?? 0);
}

function como_reloj($segundos)
{
    $segundos = max(0, (int) $segundos);
    return floor($segundos / 3600) . ':'
         . str_pad((string) floor(($segundos % 3600) / 60), 2, '0', STR_PAD_LEFT) . ':'
         . str_pad((string) ($segundos % 60), 2, '0', STR_PAD_LEFT);
}

$entrada_seg  = 0;
$trabajado    = 0;

if ($mi_estado === 'adentro') {
    $entrada_seg = en_segundos($mi_fichada['hora_entrada']);
    /* El max(0) es por si quedó una entrada con hora posterior a la
       actual: el contador no puede ir para atrás. */
    $trabajado   = max(0, $ahora_seg - $entrada_seg);

} elseif ($mi_estado === 'cerrada') {
    $entrada_seg = en_segundos($mi_fichada['hora_entrada']);
    $trabajado   = max(0, en_segundos($mi_fichada['hora_salida']) - $entrada_seg);
}


/* Fichadas viejas sin salida. Esas horas no entran en ningún reporte
   hasta que RRHH las cierre; el empleado ve solo las suyas. */

$sql_abiertas = "
    SELECT f.id_fichada, f.fecha, f.hora_entrada,
           e.legajo, CONCAT(e.apellido, ', ', e.nombre) AS empleado
      FROM fichadas f
      INNER JOIN empleados e ON e.id_empleado = f.id_empleado
     WHERE f.hora_salida IS NULL
       AND f.fecha < CURDATE()
       " . ($es_rrhh ? "" : "AND f.id_empleado = :id") . "
     ORDER BY f.fecha DESC";

$consulta = $conexion->prepare($sql_abiertas);
$consulta->execute($es_rrhh ? [] : [':id' => $id_empleado]);
$abiertas = $consulta->fetchAll();


/* Los filtros van por GET porque no modifican nada: así el link de una
   consulta se puede guardar o pasarle a un compañero. */

$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

/* Si alguien escribe cualquier cosa en la URL volvemos al valor por
   defecto en vez de mandarle basura a MySQL. */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) { $desde = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) { $hasta = date('Y-m-d'); }

/* El empleado ve solo lo suyo, mande lo que mande por GET. */
$filtro_empleado = $es_rrhh ? (int) ($_GET['empleado'] ?? 0) : $id_empleado;

$condiciones = ["f.fecha BETWEEN :desde AND :hasta"];
$parametros  = [':desde' => $desde, ':hasta' => $hasta];

if ($filtro_empleado > 0) {
    $condiciones[] = "f.id_empleado = :emp";
    $parametros[':emp'] = $filtro_empleado;
}

$donde = implode(' AND ', $condiciones);


/* Los totales del pie los suma MySQL sobre el período completo, no PHP
   sobre las filas de la pantalla. Como se muestran cinco días por
   página, sumar lo que está a la vista daría el total de esos cinco
   días y seguiría diciendo "Total de horas". */

$sql_resumen = "
    SELECT COUNT(DISTINCT f.fecha) AS dias,
           COUNT(*)                AS registros,
           SUM(CASE WHEN f.hora_salida IS NOT NULL THEN 1 ELSE 0 END) AS jornadas,
           SUM(ROUND(TIMESTAMPDIFF(MINUTE, f.hora_entrada,
                     f.hora_salida) / 60, 2)) AS horas
      FROM fichadas f
      INNER JOIN empleados e ON e.id_empleado = f.id_empleado
     WHERE $donde";

$consulta = $conexion->prepare($sql_resumen);
$consulta->execute($parametros);
$resumen = $consulta->fetch();

$total_dias  = (int)   ($resumen['jornadas']  ?? 0);
$total_horas = (float) ($resumen['horas']     ?? 0);
$total_regs  = (int)   ($resumen['registros'] ?? 0);


/* Cortamos por días y no por filas. Si un día tiene seis fichadas y la
   página corta en la cuarta, el subtotal de ese día queda incompleto. */

$dias_por_pagina = 5;

$pagina = (int) ($_GET['pagina'] ?? 1);
if ($pagina < 1) { $pagina = 1; }

$total_paginas = max(1, (int) ceil(((int) ($resumen['dias'] ?? 0)) / $dias_por_pagina));
if ($pagina > $total_paginas) { $pagina = $total_paginas; }

$desplazamiento = ($pagina - 1) * $dias_por_pagina;

/* LIMIT y OFFSET van pegados en el texto del SQL y no como :marcadores.
   Se puede porque los dos pasaron por (int) y no puede llegar otra cosa
   que un número. */
$sql_dias = "
    SELECT DISTINCT f.fecha
      FROM fichadas f
      INNER JOIN empleados e ON e.id_empleado = f.id_empleado
     WHERE $donde
     ORDER BY f.fecha DESC
     LIMIT $dias_por_pagina OFFSET $desplazamiento";

$consulta = $conexion->prepare($sql_dias);
$consulta->execute($parametros);
$dias_pagina = $consulta->fetchAll();


$historial = [];

if (count($dias_pagina) > 0) {

    /* El IN necesita un marcador por valor. Los armamos acá para no
       pegar las fechas dentro del SQL. */
    $marcadores   = [];
    $par_historial = $parametros;

    foreach ($dias_pagina as $i => $d) {
        $marcadores[]              = ':d' . $i;
        $par_historial[':d' . $i]  = $d['fecha'];
    }

    $sql_historial = "
        SELECT f.fecha, f.hora_entrada, f.hora_salida, f.observacion,
               e.legajo, CONCAT(e.apellido, ', ', e.nombre) AS empleado,
               a.nombre AS area,
               ROUND(TIMESTAMPDIFF(MINUTE, f.hora_entrada,
                     f.hora_salida) / 60, 2) AS horas
          FROM fichadas f
          INNER JOIN empleados e ON e.id_empleado = f.id_empleado
          LEFT  JOIN areas     a ON a.id_area     = e.id_area
         WHERE $donde
           AND f.fecha IN (" . implode(', ', $marcadores) . ")
         ORDER BY f.fecha DESC, e.apellido ASC";

    $consulta = $conexion->prepare($sql_historial);
    $consulta->execute($par_historial);
    $historial = $consulta->fetchAll();
}


/* Ya vienen ordenadas por fecha y PHP conserva el orden de inserción,
   así que los días quedan del más nuevo al más viejo sin ordenar nada. */

$por_dia = [];
foreach ($historial as $h) {
    $por_dia[$h['fecha']][] = $h;
}


/* Anterior y Siguiente tienen que arrastrar los filtros: si filtraste
   por un empleado y pasás de página, no lo podés perder. */
function link_pagina($numero)
{
    global $filtro_empleado, $desde, $hasta, $es_rrhh;

    $partes = ['desde' => $desde, 'hasta' => $hasta, 'pagina' => $numero];
    if ($es_rrhh && $filtro_empleado > 0) {
        $partes['empleado'] = $filtro_empleado;
    }
    return 'asistencia.php?' . http_build_query($partes);
}

$lista_empleados = [];
if ($es_rrhh) {
    $lista_empleados = $conexion->query(
        "SELECT id_empleado, CONCAT(apellido, ', ', nombre) AS nombre
           FROM empleados WHERE activo = 1 ORDER BY apellido, nombre"
    )->fetchAll();
}

/* Los nombres los ponemos a mano para no depender de las librerías de
   idioma, que en el servidor de la escuela no estaban instaladas. */

$dias  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

$pagina_activa = 'asistencia';
$titulo        = 'Asistencia';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado">
        <div>
            <h2>Asistencia</h2>
            <p>
                <?= $es_rrhh
                    ? 'Registro horario de todo el personal.'
                    : 'Tu registro de entradas y salidas.' ?>
            </p>
        </div>
    </div>


    <?php if ($aviso): ?>
    <div class="alerta alerta--<?= $aviso[0] === 'ok' ? 'ok' : ($aviso[0] === 'error' ? 'error' : 'aviso') ?>">
        <span><?= limpio($aviso[1]) ?></span>
    </div>
    <?php endif; ?>


    <!-- Los data-* le pasan los valores al JS sin mezclar código.
         El que importa es data-servidor: sin eso el JS usaría el reloj
         de la máquina del empleado, que puede estar mal o cambiado a
         propósito. -->
    <div class="jornada"
         id="jornada"
         data-modo="<?= $mi_estado === 'sin_entrada' ? 'reloj' : ($mi_estado === 'adentro' ? 'contador' : 'fijo') ?>"
         data-servidor="<?= $ahora_seg ?>"
         data-entrada="<?= $entrada_seg ?>">

        <div class="jornada__datos">

            <p class="jornada__quien"><?= limpio($_SESSION['nombre']) ?></p>

            <?php if ($mi_estado === 'sin_entrada'): ?>

                <div class="jornada__cifra jornada__cifra--apagada" id="jornadaCifra"><?= date('H:i:s') ?></div>
                <span class="jornada__que">Hora del servidor</span>
                <p class="jornada__detalle">Todavía no registraste tu entrada de hoy.</p>

            <?php elseif ($mi_estado === 'adentro'): ?>

                <div class="jornada__cifra" id="jornadaCifra"><?= como_reloj($trabajado) ?></div>
                <span class="jornada__que">
                    Trabajando desde las <?= substr($mi_fichada['hora_entrada'], 0, 5) ?>
                </span>
                <p class="jornada__detalle">
                    Son las <b id="jornadaReloj"><?= date('H:i') ?></b> en el servidor.
                </p>

            <?php else: ?>

                <div class="jornada__cifra" id="jornadaCifra"><?= como_reloj($trabajado) ?></div>
                <span class="jornada__que">Jornada completa</span>
                <p class="jornada__detalle">
                    De <b><?= substr($mi_fichada['hora_entrada'], 0, 5) ?></b>
                    a <b><?= substr($mi_fichada['hora_salida'], 0, 5) ?></b>.
                </p>

            <?php endif; ?>

        </div>

        <div class="jornada__accion">
            <?php if ($mi_estado === 'sin_entrada'): ?>
                <form method="POST" action="asistencia.php">
                    <input type="hidden" name="accion" value="entrada">
                    <button type="submit" class="jornada__boton">Marcar entrada</button>
                </form>

            <?php elseif ($mi_estado === 'adentro'): ?>
                <form method="POST" action="asistencia.php">
                    <input type="hidden" name="accion" value="salida">
                    <button type="submit" class="jornada__boton jornada__boton--salida">Marcar salida</button>
                </form>

            <?php else: ?>
                <span class="estado estado--cerrado">Jornada cerrada</span>
            <?php endif; ?>
        </div>

    </div>


    <?php if (count($abiertas) > 0): ?>
    <div class="caja">
        <div class="caja__titulo">
            <h3>Jornadas sin cerrar de días anteriores</h3>
            <span class="texto-suave"><?= count($abiertas) ?></span>
        </div>

        <div style="padding:10px 12px; border-bottom:1px solid var(--borde); font-size:13px; color:var(--texto-suave)">
            Estas fichadas tienen entrada pero no salida, así que
            <strong>sus horas no se cuentan en ningún reporte</strong>.
            <?= $es_rrhh
                ? 'Cargá la hora de salida para cerrarlas.'
                : 'Avisale a Recursos Humanos para que las corrija.' ?>
        </div>

        <div class="tabla-contenedor">
            <table class="tabla tabla--densa">
                <thead>
                    <tr>
                        <?php if ($es_rrhh): ?><th>Empleado</th><?php endif; ?>
                        <th>Fecha</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($abiertas as $a): ?>
                    <tr>
                        <?php if ($es_rrhh): ?>
                        <td class="nowrap">
                            <strong><?= limpio($a['empleado']) ?></strong>
                            <span class="texto-suave" style="display:block;font-size:11.5px">
                                <?= limpio($a['legajo']) ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="num nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                        <td class="num nowrap"><?= substr($a['hora_entrada'], 0, 5) ?></td>
                        <td>
                            <?php if ($es_rrhh): ?>
                            <form method="POST" action="asistencia.php" class="form-linea">
                                <input type="hidden" name="accion" value="cerrar">
                                <input type="hidden" name="id_fichada" value="<?= (int) $a['id_fichada'] ?>">
                                <input type="time" name="hora_salida" required>
                                <button type="submit" class="boton boton--secundario boton--chico">Cerrar</button>
                            </form>
                            <?php else: ?>
                                <span class="estado estado--ninguno">Pendiente de corrección</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>


    <div class="caja">
        <div class="caja__titulo">
            <h3><?= $es_rrhh ? 'Historial de fichadas' : 'Mi historial' ?></h3>
            <!-- El total del período, no el de la página. -->
            <span class="texto-suave"><?= $total_regs ?> registros</span>
        </div>

        <form method="GET" action="asistencia.php" class="filtros">

            <?php if ($es_rrhh): ?>
            <div class="filtros__grupo">
                <label for="empleado">Empleado</label>
                <select name="empleado" id="empleado">
                    <option value="0">Todos</option>
                    <?php foreach ($lista_empleados as $e): ?>
                    <option value="<?= (int) $e['id_empleado'] ?>"
                        <?= $filtro_empleado === (int) $e['id_empleado'] ? 'selected' : '' ?>>
                        <?= limpio($e['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filtros__grupo">
                <label for="desde">Desde</label>
                <input type="date" id="desde" name="desde" value="<?= limpio($desde) ?>">
            </div>

            <div class="filtros__grupo">
                <label for="hasta">Hasta</label>
                <input type="date" id="hasta" name="hasta" value="<?= limpio($hasta) ?>">
            </div>

            <button type="submit" class="boton boton--primario">Filtrar</button>
            <a href="asistencia.php" class="boton boton--secundario">Limpiar</a>
        </form>

        <!-- La fecha va arriba del grupo y no en una columna: con cinco
             empleados fichando el mismo día se repetía cinco veces
             seguidas. Ahí mismo entra el subtotal. -->
        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <?php if ($es_rrhh): ?><th>Empleado</th><?php endif; ?>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Horas</th>
                        <th>Observación</th>
                    </tr>
                </thead>
                <tbody>
                <?php $columnas = $es_rrhh ? 5 : 4; ?>

                <?php if (count($por_dia) === 0): ?>
                    <tr>
                        <td colspan="<?= $columnas ?>" class="vacio">
                            No hay fichadas en el período elegido.
                        </td>
                    </tr>

                <?php else: ?>
                    <?php foreach ($por_dia as $fecha_dia => $fichadas_dia): ?>

                    <?php
                    /* Acá sí sumamos en PHP porque la página trae los
                       días enteros, ninguno queda partido. */
                    $horas_dia = 0;
                    foreach ($fichadas_dia as $f) {
                        $horas_dia += (float) $f['horas'];
                    }
                    ?>

                    <tr class="dia-cab">
                        <td colspan="<?= $columnas ?>">
                            <b><?= ucfirst($dias[(int) date('w', strtotime($fecha_dia))]) ?>
                               <?= date('j', strtotime($fecha_dia)) ?>
                               de <?= $meses[(int) date('n', strtotime($fecha_dia))] ?></b>
                            <span>
                                <?= count($fichadas_dia) ?>
                                <?= count($fichadas_dia) === 1 ? 'fichada' : 'fichadas' ?>
                                &middot; <?= number_format($horas_dia, 2, ',', '') ?> horas
                            </span>
                        </td>
                    </tr>

                    <?php foreach ($fichadas_dia as $h): ?>
                    <tr>
                        <?php if ($es_rrhh): ?>
                        <td>
                            <strong><?= limpio($h['empleado']) ?></strong>
                            <span class="dato-sub"><?= limpio($h['area'] ?? 'Sin área') ?></span>
                        </td>
                        <?php endif; ?>
                        <td class="num nowrap"><?= substr($h['hora_entrada'], 0, 5) ?></td>
                        <td class="num nowrap">
                            <?php if ($h['hora_salida'] === null): ?>
                                <span class="dato-vacio">Sin cerrar</span>
                            <?php else: ?>
                                <?= substr($h['hora_salida'], 0, 5) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num nowrap">
                            <?= $h['horas'] !== null ? limpio($h['horas']) : '—' ?>
                        </td>
                        <td class="texto-suave"><?= limpio($h['observacion'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>


        <!-- Cuando no se pueden usar los apagamos en vez de esconderlos:
             un botón que aparece y desaparece mueve todo de lugar. -->
        <?php if ($total_paginas > 1): ?>
        <div class="paginas">
            <span class="paginas__texto">
                Mostrando <b><?= count($por_dia) ?></b>
                <?= count($por_dia) === 1 ? 'día' : 'días' ?>
                de <b><?= (int) $resumen['dias'] ?></b>
                &middot; página <?= $pagina ?> de <?= $total_paginas ?>
            </span>

            <span class="paginas__botones">
                <?php if ($pagina > 1): ?>
                    <a href="<?= link_pagina($pagina - 1) ?>"
                       class="boton boton--secundario boton--chico">&larr; Anterior</a>
                <?php else: ?>
                    <span class="boton boton--secundario boton--chico paginas__apagado">&larr; Anterior</span>
                <?php endif; ?>

                <?php if ($pagina < $total_paginas): ?>
                    <a href="<?= link_pagina($pagina + 1) ?>"
                       class="boton boton--secundario boton--chico">Siguiente &rarr;</a>
                <?php else: ?>
                    <span class="boton boton--secundario boton--chico paginas__apagado">Siguiente &rarr;</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>


        <div class="totales">
            <span>Fichadas: <b><?= $total_regs ?></b></span>
            <span>Jornadas cerradas: <b><?= $total_dias ?></b></span>
            <span>Total de horas: <b><?= number_format($total_horas, 2, ',', '') ?></b></span>
            <?php if ($total_dias > 0): ?>
            <span>Promedio por jornada: <b><?= number_format($total_horas / $total_dias, 2, ',', '') ?></b></span>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php
$script_pagina = 'asistencia.js';
require_once 'includes/pie.php';
?>