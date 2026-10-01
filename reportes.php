<?php
/* La planilla que RRHH saca a fin de mes: días, horas y licencias de cada
   uno. Es la única pantalla pensada para el papel, de ahí el @media print
   del CSS que esconde la barra y los filtros. */

require_once 'includes/proteger.php';
exigir_rol('rrhh');
require_once 'config/conexion.php';


/* Mes y año en vez de un rango libre, así nadie puede pedir un período
   torcido por error. */

$mes = (int) ($_GET['mes'] ?? date('n'));
$anio = (int) ($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12)        { $mes  = (int) date('n'); }
if ($anio < 2000 || $anio > 2100) { $anio = (int) date('Y'); }

$primer_dia = sprintf('%04d-%02d-01', $anio, $mes);
$ultimo_dia = date('Y-m-t', strtotime($primer_dia));

$f_area = (int) ($_GET['area'] ?? 0);


/* Dos cosas raras de esta consulta:
   Filtramos con BETWEEN y no con MONTH(fecha) = :mes, porque una función
   sobre la columna le tapa el índice a MySQL.
   Y los :ini1, :ini2, :ini3 valen lo mismo pero van repetidos con otro
   nombre: la conexión tiene EMULATE_PREPARES en false y ahí no se puede
   reusar un marcador. Probamos con uno solo y no andaba. */

$condiciones = ["e.activo = 1"];
$parametros  = [
    ':ini1' => $primer_dia, ':fin1' => $ultimo_dia,
    ':ini2' => $primer_dia, ':fin2' => $ultimo_dia,
    ':ini3' => $primer_dia, ':fin3' => $ultimo_dia,
];

if ($f_area > 0) {
    $condiciones[] = "e.id_area = :area";
    $parametros[':area'] = $f_area;
}

$sql = "
    SELECT e.legajo,
           CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           e.cargo,
           a.nombre AS area,

           /* Días con la jornada cerrada */
           COUNT(CASE WHEN f.hora_salida IS NOT NULL THEN 1 END) AS dias,

           /* Horas trabajadas. Se suman los MINUTOS y recién al final
              se divide por 60, para no arrastrar redondeos. */
           ROUND(SUM(CASE WHEN f.hora_salida IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, f.hora_entrada, f.hora_salida)
                     ELSE 0 END) / 60, 2) AS horas,

           /* Fichadas del período que quedaron sin cerrar: esas horas
              no están contadas en la columna anterior. */
           COUNT(CASE WHEN f.id_fichada IS NOT NULL AND f.hora_salida IS NULL
                 THEN 1 END) AS abiertas,

           /* Días de licencia aprobada DENTRO del mes.
              LEAST y GREATEST recortan la licencia a los límites del
              período: si alguien se toma del 25 de agosto al 5 de
              septiembre, en el reporte de agosto cuentan 7 días y en
              el de septiembre los otros 5. */
           (SELECT IFNULL(SUM(
                       DATEDIFF(LEAST(l.fecha_hasta,  :fin1),
                                GREATEST(l.fecha_desde, :ini1)) + 1), 0)
              FROM licencias l
             WHERE l.id_empleado = e.id_empleado
               AND l.estado      = 'aprobada'
               AND l.fecha_desde <= :fin2
               AND l.fecha_hasta >= :ini2
           ) AS dias_licencia

      FROM empleados e
      LEFT JOIN areas a ON a.id_area = e.id_area
      LEFT JOIN fichadas f
             ON f.id_empleado = e.id_empleado
            AND f.fecha BETWEEN :ini3 AND :fin3
     WHERE " . implode(' AND ', $condiciones) . "
     GROUP BY e.id_empleado, e.legajo, e.apellido, e.nombre, e.cargo, a.nombre
     ORDER BY e.apellido, e.nombre";

$consulta = $conexion->prepare($sql);
$consulta->execute($parametros);
$filas = $consulta->fetchAll();


/* Los totales los sumamos en PHP sobre lo que ya trajimos. */

$total_horas     = 0;
$total_dias      = 0;
$total_licencias = 0;
$total_abiertas  = 0;

foreach ($filas as $f) {
    $total_horas     += (float) $f['horas'];
    $total_dias      += (int)   $f['dias'];
    $total_licencias += (int)   $f['dias_licencia'];
    $total_abiertas  += (int)   $f['abiertas'];
}

$areas = $conexion->query("SELECT id_area, nombre FROM areas ORDER BY nombre")->fetchAll();

$anios = $conexion->query(
    "SELECT DISTINCT YEAR(fecha) AS anio FROM fichadas ORDER BY anio DESC"
)->fetchAll();

if (count($anios) === 0) {
    $anios = [['anio' => date('Y')]];
}

$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
          'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

$area_elegida = 'Todas las áreas';
foreach ($areas as $a) {
    if ((int) $a['id_area'] === $f_area) { $area_elegida = $a['nombre']; }
}

$pagina_activa = 'reportes';
$titulo        = 'Reporte mensual';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado no-imprimir">
        <div>
            <h2>Reporte mensual de horas</h2>
            <p>Horas trabajadas y días de licencia por empleado.</p>
        </div>
        <!-- Desde el diálogo de impresión se puede elegir "Guardar como
             PDF", que es como lo archivan sin gastar papel -->
        <button type="button" class="boton boton--primario" onclick="window.print()">
            Imprimir reporte
        </button>
    </div>


    <!-- Encabezado que sale solo en el papel -->
    <div class="solo-impresion" style="margin-bottom:14px; border-bottom:2px solid #000; padding-bottom:8px">
        <h2 style="font-size:15pt; margin-bottom:4px">
            ASISTENS &mdash; Reporte mensual de horas
        </h2>
        <p style="font-size:10pt">
            Período: <strong><?= $meses[$mes] ?> <?= $anio ?></strong>
            &nbsp;&middot;&nbsp; Área: <strong><?= limpio($area_elegida) ?></strong>
            &nbsp;&middot;&nbsp; Emitido el <?= date('d/m/Y') ?> a las <?= date('H:i') ?>
            por <?= limpio($_SESSION['nombre']) ?>
        </p>
    </div>


    <!-- Son los mismos totales del pie, arriba para verlos antes de leer
         la planilla. Van con no-imprimir: en papel el total se busca al
         final de la columna y para eso está la fila TOTAL. -->
    <div class="cifras-mes no-imprimir">
        <div class="cifra-mes">
            <b><?= count($filas) ?></b>
            <span><?= count($filas) === 1 ? 'Empleado' : 'Empleados' ?></span>
        </div>
        <div class="cifra-mes">
            <b><?= $total_dias ?></b>
            <span>Jornadas cerradas</span>
        </div>
        <div class="cifra-mes">
            <b><?= number_format($total_horas, 1, ',', '') ?></b>
            <span>Horas del mes</span>
        </div>
        <div class="cifra-mes">
            <b><?= $total_licencias ?></b>
            <span>Días de licencia</span>
        </div>
    </div>


    <div class="caja">
        <div class="caja__titulo">
            <h3><?= $meses[$mes] ?> de <?= $anio ?></h3>
            <span class="texto-suave">
                Del <?= date('d/m/Y', strtotime($primer_dia)) ?>
                al <?= date('d/m/Y', strtotime($ultimo_dia)) ?>
            </span>
        </div>

        <form method="GET" action="reportes.php" class="filtros">

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

            <button type="submit" class="boton boton--primario">Generar</button>
        </form>

        <!-- El área va en el renglón chico bajo el nombre y no en columna
             aparte: es una columna menos para seguir con el ojo. Acá sí
             queda tabla, porque el reporte es comparar los mismos números
             entre varios y encima se imprime. -->
        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <th>Legajo</th>
                        <th>Empleado</th>
                        <th>Días trab.</th>
                        <th>Horas</th>
                        <th>Promedio</th>
                        <th>Días licencia</th>
                        <th>Sin cerrar</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($filas) === 0): ?>
                    <tr><td colspan="7" class="vacio">No hay empleados activos en esa área.</td></tr>
                <?php else: ?>
                    <?php foreach ($filas as $f): ?>
                    <tr>
                        <td class="num texto-suave nowrap"><?= limpio($f['legajo']) ?></td>

                        <td>
                            <strong><?= limpio($f['empleado']) ?></strong>
                            <span class="dato-sub">
                                <?= limpio($f['cargo'] ?? 'Sin cargo') ?>
                                &middot;
                                <?= limpio($f['area'] ?? 'Sin área') ?>
                            </span>
                        </td>

                        <td class="num"><?= (int) $f['dias'] ?></td>
                        <td class="num"><strong><?= number_format((float) $f['horas'], 2, ',', '') ?></strong></td>

                        <td class="num">
                            <?= $f['dias'] > 0
                                ? number_format($f['horas'] / $f['dias'], 2, ',', '')
                                : '—' ?>
                        </td>

                        <td class="num">
                            <?= $f['dias_licencia'] > 0 ? (int) $f['dias_licencia'] : '—' ?>
                        </td>

                        <td class="num">
                            <?php if ($f['abiertas'] > 0): ?>
                                <!-- Negrita porque son horas que trabajó y el reporte no cuenta -->
                                <strong><?= (int) $f['abiertas'] ?></strong>
                            <?php else: ?>
                                <span class="texto-suave">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <!-- Cada total cae justo abajo de su columna, que es como
                     uno lo busca. Va en tfoot y no en tbody porque no es
                     un empleado más. -->
                <?php if (count($filas) > 0): ?>
                <tfoot>
                    <tr class="fila-total">
                        <td colspan="2">
                            TOTAL &mdash; <?= count($filas) ?>
                            <?= count($filas) === 1 ? 'empleado' : 'empleados' ?>
                        </td>
                        <td class="num"><?= $total_dias ?></td>
                        <td class="num"><?= number_format($total_horas, 2, ',', '') ?></td>
                        <td class="num">
                            <?= $total_dias > 0
                                ? number_format($total_horas / $total_dias, 2, ',', '')
                                : '—' ?>
                        </td>
                        <td class="num"><?= $total_licencias ?></td>
                        <td class="num"><?= $total_abiertas > 0 ? $total_abiertas : '—' ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>


    <?php if ($total_abiertas > 0): ?>
    <div class="alerta alerta--aviso no-imprimir">
        <span>
            Hay <strong><?= $total_abiertas ?></strong> fichada(s) del período sin hora de salida.
            Esas horas no están sumadas en el reporte. Se corrigen desde
            <a href="asistencia.php" style="text-decoration:underline">Asistencia</a>
            antes de dar el mes por cerrado.
        </span>
    </div>
    <?php endif; ?>

    <p class="texto-suave no-imprimir" style="margin-top:12px">
        <strong>Cómo se calcula:</strong> se cuentan solo las jornadas con entrada
        y salida registradas. El promedio es horas dividido días trabajados.
        Los días de licencia son los que caen dentro del mes: una licencia que
        cruza dos meses se reparte entre los dos reportes.
    </p>

</main>

<?php require_once 'includes/pie.php'; ?>