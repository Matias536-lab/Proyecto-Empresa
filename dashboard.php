<?php
/* Panel de RRHH: quién está hoy y qué licencias hay para resolver.
   Los empleados se agrupan por estado en cuatro cajas. */

require_once 'includes/proteger.php';
exigir_rol('rrhh');
require_once 'config/conexion.php';


/* Una sola consulta para las cuatro cajas y los totales, y clasificamos
   en PHP. El join a fichadas tiene que ser LEFT: con INNER se nos caía de
   la lista justo el que no fichó, que es el que queremos ver.
   Las dos subconsultas de licencia parecen repetidas al lado pero no se
   pueden juntar, un SELECT anidado devuelve una columna sola. */

$sql_personal = "
    SELECT e.legajo,
           CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           e.cargo,
           a.nombre AS area,
           f.hora_entrada,
           f.hora_salida,
           /* Solo calculamos acá las jornadas YA CERRADAS, donde los
              dos horarios están guardados en la base. Las que siguen
              abiertas se calculan en PHP más abajo: si usáramos
              CURTIME(), dependeríamos del reloj del servidor MySQL,
              que puede estar desfasado del reloj de PHP. */
           ROUND(TIMESTAMPDIFF(MINUTE, f.hora_entrada,
                 f.hora_salida) / 60, 2) AS horas,

           (SELECT l.fecha_hasta
              FROM licencias l
             WHERE l.id_empleado = e.id_empleado
               AND l.estado = 'aprobada'
               AND CURDATE() BETWEEN l.fecha_desde AND l.fecha_hasta
             LIMIT 1
           ) AS licencia_hasta,

           (SELECT t.nombre
              FROM licencias l
              INNER JOIN tipos_licencia t ON t.id_tipo = l.id_tipo
             WHERE l.id_empleado = e.id_empleado
               AND l.estado = 'aprobada'
               AND CURDATE() BETWEEN l.fecha_desde AND l.fecha_hasta
             LIMIT 1
           ) AS licencia_tipo

    FROM empleados e
    LEFT JOIN areas    a ON a.id_area = e.id_area
    LEFT JOIN fichadas f ON f.id_empleado = e.id_empleado
                        AND f.fecha = CURDATE()
    WHERE e.activo = 1
    ORDER BY e.apellido, e.nombre";

$personal = $conexion->query($sql_personal)->fetchAll();


$grupos = [
    'trabajando' => [],
    'cerrada'    => [],
    'licencia'   => [],
    'sin_fichar' => [],
];

$horas_del_dia = 0;

foreach ($personal as $p) {

    /* El orden de los if no es al azar: la fichada se pregunta antes que
       la licencia. La fichada es un hecho, la licencia aprobada es un plan,
       y si se contradicen gana el hecho. Preguntando al revés, el que tenía
       licencia y venía igual salía como "De licencia" y la fichada no se veía. */

    if ($p['hora_entrada'] !== null && $p['hora_salida'] === null) {
        $estado = 'trabajando';
    } elseif ($p['hora_entrada'] !== null) {
        $estado = 'cerrada';
    } elseif ($p['licencia_hasta'] !== null) {
        $estado = 'licencia';
    } else {
        $estado = 'sin_fichar';
    }

    /* Con el reloj de PHP, el mismo del encabezado, así los dos números
       no se contradicen en pantalla. */
    if ($estado === 'trabajando') {
        $entrada = strtotime(date('Y-m-d') . ' ' . $p['hora_entrada']);
        $p['horas'] = max(0, round((time() - $entrada) / 3600, 2));
    }

    $horas_del_dia += (float) $p['horas'];

    $grupos[$estado][] = $p;
}


/* Los títulos van acá arriba y no en el HTML para que abajo quede un
   foreach solo en vez de cuatro bloques casi iguales. */
$cajas = [
    'trabajando' => ['Trabajando ahora',  'Ficharon la entrada y siguen adentro'],
    'cerrada'    => ['Ya terminaron',     'Ficharon entrada y salida'],
    'licencia'   => ['De licencia',       'Con permiso aprobado para hoy'],
    'sin_fichar' => ['Todavía sin fichar', 'No registraron la entrada'],
];


$sql_pendientes = "
    SELECT CONCAT(e.apellido, ', ', e.nombre) AS empleado,
           t.nombre AS tipo,
           l.fecha_desde, l.fecha_hasta, l.dias_solicitados,
           DATEDIFF(CURDATE(), l.fecha_solicitud) AS dias_esperando
    FROM licencias l
    INNER JOIN empleados      e ON e.id_empleado = l.id_empleado
    INNER JOIN tipos_licencia t ON t.id_tipo     = l.id_tipo
    WHERE l.estado = 'pendiente'
    ORDER BY l.fecha_solicitud ASC";

$pendientes = $conexion->query($sql_pendientes)->fetchAll();


/* La fecha en castellano la armamos a mano. Probamos con setlocale y en
   el server de la escuela no andaba, no tenía el idioma instalado. */

$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

$fecha_larga = $dias[(int) date('w')] . ' ' . date('j')
             . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y');

$pagina_activa = 'dashboard';
$titulo        = 'Panel';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado">
        <div>
            <!-- El título va como pregunta a propósito: es lo que la de RRHH
                 viene a averiguar cuando abre esto. -->
            <h2>¿Quién está hoy?</h2>
            <p><?= ucfirst($fecha_larga) ?> &middot; actualizado a las <?= date('H:i') ?></p>
        </div>
        <a href="asistencia.php" class="boton boton--secundario">Historial completo</a>
    </div>


    <!-- Si mañana hace falta un quinto estado, se agrega al arreglo $cajas
         de arriba y la caja sale sola, este HTML no se toca. -->
    <div class="grupos">

        <?php foreach ($cajas as $clave => $caja): ?>
        <?php $gente = $grupos[$clave]; ?>

        <section class="grupo <?= $clave === 'sin_fichar' ? 'grupo--apagado' : '' ?>">

            <div class="grupo__cab">
                <span class="grupo__n"><?= count($gente) ?></span>
                <h3><?= $caja[0] ?></h3>
                <p><?= $caja[1] ?></p>
            </div>

            <?php if (count($gente) === 0): ?>
                <ul class="grupo__lista grupo__lista--vacia">
                    <li>Nadie por ahora.</li>
                </ul>
            <?php else: ?>
                <ul class="grupo__lista">
                    <?php foreach ($gente as $p): ?>
                    <li>
                        <b><?= limpio($p['empleado']) ?></b>

                        <!-- El renglón de abajo cambia según el grupo: del que
                             trabaja importa desde qué hora, del que cerró
                             cuántas horas hizo, del de licencia hasta cuándo. -->
                        <span>
                        <?php if ($clave === 'trabajando'): ?>

                            <?= limpio($p['area'] ?? 'Sin área') ?>
                            &middot; entró <?= limpio(substr($p['hora_entrada'], 0, 5)) ?>

                            <?php if ($p['licencia_hasta'] !== null): ?>
                            <!-- Parece un error y no lo es: tenía licencia
                                 aprobada y vino igual. RRHH quiere ver eso. -->
                            <br><strong>Tenía licencia y vino igual</strong>
                            <?php endif; ?>

                        <?php elseif ($clave === 'cerrada'): ?>

                            <?= limpio($p['area'] ?? 'Sin área') ?>
                            &middot; <?= number_format((float) $p['horas'], 2, ',', '') ?> h

                            <?php if ($p['licencia_hasta'] !== null): ?>
                            <br><strong>Tenía licencia y vino igual</strong>
                            <?php endif; ?>

                        <?php elseif ($clave === 'licencia'): ?>

                            <?= limpio($p['licencia_tipo'] ?? 'Licencia') ?>
                            hasta el <?= date('d/m', strtotime($p['licencia_hasta'])) ?>

                        <?php else: ?>

                            <?= limpio($p['area'] ?? 'Sin área') ?>

                        <?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </section>

        <?php endforeach; ?>

    </div>


    <p class="resumen-hoy">
        <?= count($personal) ?> empleados activos
        &middot;
        <b><?= number_format($horas_del_dia, 1, ',', '') ?></b> horas acumuladas entre todos hoy
    </p>


    <div class="caja">

        <div class="caja__titulo">
            <h3>Licencias esperando respuesta</h3>
            <?php if (count($pendientes) > 0): ?>
            <a href="licencias.php" class="boton boton--primario boton--chico">Resolver</a>
            <?php endif; ?>
        </div>

        <?php if (count($pendientes) === 0): ?>
            <p class="vacio">No hay solicitudes pendientes.</p>
        <?php else: ?>

        <div class="tabla-contenedor">
            <table class="tabla tabla--densa tabla--aireada">
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Licencia pedida</th>
                        <th>Espera</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendientes as $l): ?>
                <tr>
                    <td><strong><?= limpio($l['empleado']) ?></strong></td>

                    <td>
                        <?= limpio($l['tipo']) ?>
                        <span class="dato-sub">
                            del <?= date('d/m/Y', strtotime($l['fecha_desde'])) ?>
                            al <?= date('d/m/Y', strtotime($l['fecha_hasta'])) ?>
                            &middot; <?= (int) $l['dias_solicitados'] ?>
                            <?= (int) $l['dias_solicitados'] === 1 ? 'día' : 'días' ?>
                        </span>
                    </td>

                    <td class="nowrap">
                        <?php $espera = (int) $l['dias_esperando']; ?>
                        <?php if ($espera >= 5): ?>
                            <!-- Negrita y no color, así la urgencia se sigue
                                 viendo cuando lo imprimen en blanco y negro. -->
                            <strong>hace <?= $espera ?> días</strong>
                        <?php elseif ($espera === 1): ?>
                            hace 1 día
                        <?php elseif ($espera > 0): ?>
                            hace <?= $espera ?> días
                        <?php else: ?>
                            <span class="texto-suave">hoy</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

</main>

<?php require_once 'includes/pie.php'; ?>