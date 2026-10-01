<?php
/* Liquidación: mes a mes, cuánto le toca cobrar a cada uno. No guarda
   nada, recalcula cada vez que se abre. Uno de verdad guardaría el
   recibo emitido; lo dejamos así a propósito.

   Reglas del cálculo, las pone la empresa y no nosotros:
   - Se paga por hora trabajada, y solo de jornadas cerradas (con entrada
     y salida). Las abiertas no se pagan hasta que RRHH las arregle.
   - Licencia con goce se paga a 8 h por día. Si no, irse de vacaciones
     sería no cobrar.
   - Licencia sin goce no se paga, pero tampoco cuenta como falta.
   - Falta injustificada = día hábil sin fichada y sin licencia. No se
     paga, y tampoco se descuenta aparte: sería cobrar dos veces la misma
     falta. Por eso la columna "Dejó de cobrar" es informativa nada más.
   - No contamos los días anteriores al ingreso ni los del mes que
     todavía no pasaron. */

require_once 'includes/proteger.php';
exigir_rol('rrhh');
require_once 'config/conexion.php';


/* Los días de licencia no tienen fichada, así que no tienen horas reales:
   los pagamos con esto. Va con nombre para que no quede un 8 suelto. */
define('JORNADA_ESTANDAR', 8);


/* Mes y año, no un rango libre: una liquidación va del 1 al último día. */

$mes  = (int) ($_GET['mes']  ?? date('n'));
$anio = (int) ($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12)        { $mes  = (int) date('n'); }
if ($anio < 2000 || $anio > 2100) { $anio = (int) date('Y'); }

$primer_dia = sprintf('%04d-%02d-01', $anio, $mes);
$ultimo_dia = date('Y-m-t', strtotime($primer_dia));

/* Si el mes todavía está corriendo, las faltas se cuentan hasta hoy. */
$hasta_dia = min($ultimo_dia, date('Y-m-d'));


$sql_horas = "
    SELECT e.id_empleado, e.legajo, e.cargo, e.fecha_ingreso, e.valor_hora,
           CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           a.nombre AS area,

           /* Solo las jornadas cerradas. Se suman los MINUTOS y recién
              al final se divide por 60, para no arrastrar redondeos. */
           ROUND(SUM(CASE WHEN f.hora_salida IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, f.hora_entrada, f.hora_salida)
                     ELSE 0 END) / 60, 2) AS horas,

           COUNT(CASE WHEN f.hora_salida IS NOT NULL THEN 1 END) AS dias_trabajados,
           COUNT(CASE WHEN f.id_fichada IS NOT NULL
                       AND f.hora_salida IS NULL THEN 1 END) AS sin_cerrar

      FROM empleados e
      LEFT JOIN areas a ON a.id_area = e.id_area
      LEFT JOIN fichadas f
             ON f.id_empleado = e.id_empleado
            AND f.fecha BETWEEN :ini AND :fin
     WHERE e.activo = 1
     GROUP BY e.id_empleado, e.legajo, e.cargo, e.fecha_ingreso, e.valor_hora,
              e.apellido, e.nombre, a.nombre
     ORDER BY e.apellido, e.nombre";

$consulta = $conexion->prepare($sql_horas);
$consulta->execute([':ini' => $primer_dia, ':fin' => $ultimo_dia]);
$empleados = $consulta->fetchAll();


/* Traemos todas las fichadas del mes de una y armamos $ficho[id][fecha].
   Preguntar por día a la base serían 180 consultas para lo mismo. */

$consulta = $conexion->prepare("
    SELECT id_empleado, fecha
      FROM fichadas
     WHERE fecha BETWEEN :ini AND :fin");
$consulta->execute([':ini' => $primer_dia, ':fin' => $ultimo_dia]);

$ficho = [];
foreach ($consulta->fetchAll() as $f) {
    $ficho[(int) $f['id_empleado']][$f['fecha']] = true;
}


/* Traemos la licencia entera y la recortamos en PHP a los bordes del mes.
   Guardamos si es con goce porque unas se pagan y otras no. */

$consulta = $conexion->prepare("
    SELECT l.id_empleado, l.fecha_desde, l.fecha_hasta, t.con_goce
      FROM licencias l
      INNER JOIN tipos_licencia t ON t.id_tipo = l.id_tipo
     WHERE l.estado = 'aprobada'
       AND l.fecha_desde <= :fin
       AND l.fecha_hasta >= :ini");
$consulta->execute([':ini' => $primer_dia, ':fin' => $ultimo_dia]);

$licencia_de = [];
foreach ($consulta->fetchAll() as $l) {

    $desde = max($l['fecha_desde'], $primer_dia);
    $hasta = min($l['fecha_hasta'], $ultimo_dia);

    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $licencia_de[(int) $l['id_empleado']][$d] = (bool) $l['con_goce'];
    }
}


$total_general = 0;
$hay_sin_valor = false;

foreach ($empleados as $i => $e) {

    $id = (int) $e['id_empleado'];

    $dias_con_goce = 0;
    $dias_sin_goce = 0;
    $faltas        = 0;

    for ($d = $primer_dia; $d <= $hasta_dia; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {

        if ((int) date('N', strtotime($d)) >= 6) { continue; }

        if ($d < $e['fecha_ingreso']) { continue; }

        /* Si vino no sumamos nada acá: las horas ya vienen del SQL. */
        if (isset($ficho[$id][$d])) { continue; }

        if (isset($licencia_de[$id][$d])) {
            if ($licencia_de[$id][$d]) { $dias_con_goce++; } else { $dias_sin_goce++; }
            continue;
        }

        $faltas++;
    }

    $valor = $e['valor_hora'] !== null ? (float) $e['valor_hora'] : null;

    if ($valor === null) {
        /* Sin valor hora no liquidamos y avisamos. Poner cero sería un
           sueldo en cero que nadie nota hasta el día de pago. */
        $hay_sin_valor = true;

        $empleados[$i]['importe_horas']    = null;
        $empleados[$i]['importe_licencia'] = null;
        $empleados[$i]['dejo_de_cobrar']   = null;
        $empleados[$i]['total']            = null;

    } else {
        $horas_licencia = $dias_con_goce * JORNADA_ESTANDAR;

        $importe_horas    = (float) $e['horas'] * $valor;
        $importe_licencia = $horas_licencia * $valor;

        /* Esto no se resta del total, es solo para mostrar. */
        $dejo_de_cobrar = $faltas * JORNADA_ESTANDAR * $valor;

        $empleados[$i]['importe_horas']    = $importe_horas;
        $empleados[$i]['importe_licencia'] = $importe_licencia;
        $empleados[$i]['dejo_de_cobrar']   = $dejo_de_cobrar;
        $empleados[$i]['total']            = $importe_horas + $importe_licencia;

        $total_general += $importe_horas + $importe_licencia;
    }

    $empleados[$i]['dias_con_goce'] = $dias_con_goce;
    $empleados[$i]['dias_sin_goce'] = $dias_sin_goce;
    $empleados[$i]['faltas']        = $faltas;
}


$anios = $conexion->query(
    "SELECT DISTINCT YEAR(fecha) AS anio FROM fichadas ORDER BY anio DESC"
)->fetchAll();

if (count($anios) === 0) { $anios = [['anio' => date('Y')]]; }

$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
          'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

$mes_incompleto = ($ultimo_dia > date('Y-m-d'));

$pagina_activa = 'liquidacion';
$titulo        = 'Liquidación';

require_once 'includes/cabecera.php';


/* Plata como acá: $ 1.234.567,89 */
function plata($monto)
{
    if ($monto === null) { return '—'; }
    return '$ ' . number_format((float) $monto, 2, ',', '.');
}
?>

<main class="contenido">

    <div class="encabezado no-imprimir">
        <div>
            <h2>Liquidación de sueldos</h2>
            <p>Lo que le corresponde cobrar a cada empleado en el mes.</p>
        </div>
        <button type="button" class="boton boton--primario" onclick="window.print()">
            Imprimir liquidación
        </button>
    </div>


    <!-- Este encabezado sale solo en el papel -->
    <div class="solo-impresion" style="margin-bottom:14px; border-bottom:2px solid #000; padding-bottom:8px">
        <h2 style="font-size:15pt; margin-bottom:4px">
            ASISTENS &mdash; Liquidación de sueldos
        </h2>
        <p style="font-size:10pt">
            Período: <strong><?= $meses[$mes] ?> <?= $anio ?></strong>
            &nbsp;&middot;&nbsp; Emitido el <?= date('d/m/Y') ?> a las <?= date('H:i') ?>
            por <?= limpio($_SESSION['nombre']) ?>
        </p>
    </div>


    <!-- Misma franja que el panel y el reporte, para que no cambie de
         pantalla en pantalla -->
    <div class="cifras-mes no-imprimir">
        <div class="cifra-mes">
            <b><?= count($empleados) ?></b>
            <span><?= count($empleados) === 1 ? 'Empleado' : 'Empleados' ?></span>
        </div>
        <div class="cifra-mes">
            <b><?= number_format(array_sum(array_column($empleados, 'horas')), 1, ',', '.') ?></b>
            <span>Horas del mes</span>
        </div>
        <div class="cifra-mes">
            <b><?= array_sum(array_column($empleados, 'faltas')) ?></b>
            <span>Faltas injustificadas</span>
        </div>
        <div class="cifra-mes">
            <b style="font-size:30px"><?= plata($total_general) ?></b>
            <span>Total a pagar</span>
        </div>
    </div>


    <?php if ($hay_sin_valor): ?>
    <div class="alerta alerta--aviso no-imprimir">
        <span>
            Hay empleados <strong>sin valor hora cargado</strong>. No se los puede
            liquidar y no suman al total. Se les carga el valor desde
            <a href="empleados.php" style="text-decoration:underline">Empleados</a>,
            en Editar.
        </span>
    </div>
    <?php endif; ?>


    <?php if ($mes_incompleto): ?>
    <div class="alerta alerta--aviso no-imprimir">
        <span>
            <strong><?= $meses[$mes] ?></strong> todavía no terminó. Las faltas se
            cuentan hasta hoy (<?= date('d/m/Y') ?>); los días que faltan del mes
            no se cuentan como ausencia.
        </span>
    </div>
    <?php endif; ?>


    <div class="caja">
        <div class="caja__titulo">
            <h3><?= $meses[$mes] ?> de <?= $anio ?></h3>
            <span class="texto-suave">
                Del <?= date('d/m/Y', strtotime($primer_dia)) ?>
                al <?= date('d/m/Y', strtotime($ultimo_dia)) ?>
            </span>
        </div>

        <form method="GET" action="liquidacion.php" class="filtros">
            <div class="filtros__grupo">
                <label for="mes">Mes</label>
                <select name="mes" id="mes">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $mes === $m ? 'selected' : '' ?>>
                        <?= $meses[$m] ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="filtros__grupo">
                <label for="anio">Año</label>
                <select name="anio" id="anio">
                    <?php foreach ($anios as $a): ?>
                    <option value="<?= (int) $a['anio'] ?>"
                        <?= $anio === (int) $a['anio'] ? 'selected' : '' ?>>
                        <?= (int) $a['anio'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="boton boton--primario">Liquidar</button>
        </form>

        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Valor hora</th>
                        <th>Horas</th>
                        <th>Por horas</th>
                        <th>Licencia paga</th>
                        <th>Faltas</th>
                        <th>Dejó de cobrar</th>
                        <th>Total</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($empleados) === 0): ?>
                    <tr><td colspan="8" class="vacio">No hay empleados activos.</td></tr>
                <?php else: ?>
                    <?php foreach ($empleados as $e): ?>
                    <tr class="<?= $e['valor_hora'] === null ? 'fila-baja' : '' ?>">

                        <td>
                            <strong><?= limpio($e['empleado']) ?></strong>
                            <span class="dato-sub">
                                <?= limpio($e['cargo'] ?? 'Sin cargo') ?>
                                &middot; <?= limpio($e['area'] ?? 'Sin área') ?>
                            </span>
                        </td>

                        <td class="num nowrap">
                            <?php if ($e['valor_hora'] === null): ?>
                                <span class="dato-vacio">Sin asignar</span>
                            <?php else: ?>
                                <?= plata($e['valor_hora']) ?>
                            <?php endif; ?>
                        </td>

                        <td class="num"><?= number_format((float) $e['horas'], 2, ',', '') ?></td>

                        <td class="num nowrap"><?= plata($e['importe_horas']) ?></td>

                        <td class="num nowrap">
                            <?= plata($e['importe_licencia']) ?>
                            <?php if ($e['dias_con_goce'] > 0): ?>
                            <span class="dato-sub">
                                <?= $e['dias_con_goce'] ?>
                                <?= $e['dias_con_goce'] === 1 ? 'día' : 'días' ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <td class="num">
                            <?php if ($e['faltas'] > 0): ?>
                                <!-- Negrita y no color: impreso en blanco y negro se nota igual -->
                                <strong><?= $e['faltas'] ?></strong>
                            <?php else: ?>
                                <span class="texto-suave">&mdash;</span>
                            <?php endif; ?>

                            <?php if ($e['dias_sin_goce'] > 0): ?>
                            <span class="dato-sub">
                                + <?= $e['dias_sin_goce'] ?> sin goce
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Informativa: no entra en el total -->
                        <td class="num nowrap texto-suave">
                            <?= $e['faltas'] > 0 ? plata($e['dejo_de_cobrar']) : '—' ?>
                        </td>

                        <td class="num nowrap">
                            <?php if ($e['total'] === null): ?>
                                <span class="dato-vacio">No liquidable</span>
                            <?php else: ?>
                                <strong><?= plata($e['total']) ?></strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <?php if (count($empleados) > 0): ?>
                <tfoot>
                    <tr class="fila-total">
                        <td colspan="7">
                            TOTAL A PAGAR &mdash; <?= $meses[$mes] ?> <?= $anio ?>
                        </td>
                        <td class="num nowrap"><?= plata($total_general) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>


    <p class="texto-suave" style="margin-top:12px; line-height:1.7">
        <strong>Cómo se calcula:</strong>
        se paga por hora trabajada, contando solo las jornadas con entrada y
        salida registradas. Los días de licencia <strong>con goce</strong> se pagan a
        <?= JORNADA_ESTANDAR ?> horas por día. Las licencias <strong>sin goce</strong> no
        se pagan. Una <strong>falta injustificada</strong> es un día hábil sin fichada y
        sin licencia aprobada: no se paga, y tampoco se descuenta aparte
        &mdash;sería cobrar dos veces la misma falta&mdash;. La columna
        <em>Dejó de cobrar</em> es informativa: muestra cuánto habría ganado si
        esos días hubiera venido.
    </p>

</main>

<?php require_once 'includes/pie.php'; ?>