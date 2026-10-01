<?php
/* Acciones de licencias. Lo incluye licencias.php, que tenía 600 líneas
   entre la lógica y el dibujo de la pantalla.
   $conexion, $es_rrhh, $id_empleado, $tipos, $errores y $form vienen de
   licencias.php: el include comparte las variables, no hay que pasarlas. */

require_once __DIR__ . '/subir-justificativo.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Trampa: si el envío pasa post_max_size, PHP tira todo el formulario
       y llega acá con $_POST y $_FILES vacíos, sin avisar nada. Sin este
       if la pantalla se recarga como si la persona no hubiera hecho nada. */
    if (empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Location: licencias.php?msg=archivo_enorme');
        exit;
    }

    $accion = $_POST['accion'] ?? '';

    /* Solicitar una licencia. */
    if ($accion === 'solicitar') {

        foreach ($form as $campo => $_) {
            $form[$campo] = trim($_POST[$campo] ?? '');
        }

        $tipo_elegido = null;
        foreach ($tipos as $t) {
            if ((string) $t['id_tipo'] === $form['id_tipo']) { $tipo_elegido = $t; }
        }
        if ($tipo_elegido === null) {
            $errores['id_tipo'] = 'Elegí un tipo de licencia.';
        }

        $formato = '/^\d{4}-\d{2}-\d{2}$/';

        if (!preg_match($formato, $form['fecha_desde'])) {
            $errores['fecha_desde'] = 'Indicá la fecha de inicio.';
        }
        if (!preg_match($formato, $form['fecha_hasta'])) {
            $errores['fecha_hasta'] = 'Indicá la fecha de fin.';
        }

        $dias = 0;

        if (!isset($errores['fecha_desde']) && !isset($errores['fecha_hasta'])) {

            if ($form['fecha_hasta'] < $form['fecha_desde']) {
                $errores['fecha_hasta'] = 'La fecha de fin no puede ser anterior a la de inicio.';
            } else {

                /* Los días los calculamos nosotros: si los cargara el
                   usuario podría pedir dos semanas y anotar 2 días.
                   El +1 es porque del 4 al 4 es un día, no cero. */
                $desde = new DateTime($form['fecha_desde']);
                $hasta = new DateTime($form['fecha_hasta']);
                $dias  = (int) $desde->diff($hasta)->days + 1;

                if ($tipo_elegido && $tipo_elegido['dias_maximos'] !== null
                    && $dias > (int) $tipo_elegido['dias_maximos']) {
                    $errores['fecha_hasta'] =
                        'El tipo "' . $tipo_elegido['nombre'] . '" permite hasta '
                        . (int) $tipo_elegido['dias_maximos'] . ' días, y estás pidiendo ' . $dias . '.';
                }

                /* Dos períodos se pisan cuando cada uno empieza antes de que
                   termine el otro; de ahí los dos <= cruzados. Las
                   rechazadas no las miramos porque no ocupan lugar. */
                $choque = $conexion->prepare("
                    SELECT l.fecha_desde, l.fecha_hasta, t.nombre AS tipo
                      FROM licencias l
                      INNER JOIN tipos_licencia t ON t.id_tipo = l.id_tipo
                     WHERE l.id_empleado = :id
                       AND l.estado IN ('pendiente', 'aprobada')
                       AND :desde <= l.fecha_hasta
                       AND :hasta >= l.fecha_desde
                     LIMIT 1");
                $choque->execute([
                    ':id'    => $id_empleado,
                    ':desde' => $form['fecha_desde'],
                    ':hasta' => $form['fecha_hasta'],
                ]);
                $pisada = $choque->fetch();

                if ($pisada) {
                    $errores['fecha_desde'] =
                        'Ya tenés una licencia de ' . $pisada['tipo'] . ' del '
                        . date('d/m/Y', strtotime($pisada['fecha_desde'])) . ' al '
                        . date('d/m/Y', strtotime($pisada['fecha_hasta'])) . '.';
                }
            }
        }

        if (strlen($form['motivo']) > 300) {
            $errores['motivo'] = 'El motivo no puede superar los 300 caracteres.';
        }


        /* El archivo lo revisamos junto con el resto para que un solo
           envío muestre todos los errores de una. */

        $subida = guardar_justificativo('justificativo');

        if (!$subida['ok'] && !$subida['vacio']) {
            $errores['justificativo'] = $subida['error'];
        }

        /* Qué tipos exigen certificado sale de la columna
           exige_justificativo, no del PHP: mañana se cambia un 0 por un 1
           en la base y no hay que tocar código. */
        if ($tipo_elegido
            && !empty($tipo_elegido['exige_justificativo'])
            && $subida['vacio']) {

            $errores['justificativo'] =
                'Una licencia de ' . $tipo_elegido['nombre']
                . ' no se puede pedir sin adjuntar el certificado.';
        }


        if (count($errores) === 0) {

            $conexion->prepare("
                INSERT INTO licencias
                    (id_empleado, id_tipo, fecha_solicitud, fecha_desde, fecha_hasta,
                     dias_solicitados, motivo, justificativo, justificativo_nombre, estado)
                VALUES
                    (:emp, :tipo, :hoy, :desde, :hasta, :dias, :motivo,
                     :archivo, :nombre_archivo, 'pendiente')
            ")->execute([
                ':emp'            => $id_empleado,
                ':tipo'           => (int) $form['id_tipo'],
                ':hoy'            => date('Y-m-d'),
                ':desde'          => $form['fecha_desde'],
                ':hasta'          => $form['fecha_hasta'],
                ':dias'           => $dias,
                ':motivo'         => $form['motivo'] !== '' ? $form['motivo'] : null,
                ':archivo'        => $subida['archivo'],
                ':nombre_archivo' => $subida['nombre'],
            ]);

            header('Location: licencias.php?msg=solicitada');
            exit;
        }

        /* El archivo ya está en el disco antes de saber si el resto del
           formulario estaba bien. Si hubo errores esa licencia no se crea
           nunca, así que lo borramos o queda basura para siempre. La
           persona lo tiene que adjuntar de nuevo, no le encontramos vuelta. */
        if ($subida['ok']) {
            borrar_justificativo($subida['archivo']);
        }
    }

    /* Aprobar o rechazar, solo RRHH. */
    if (in_array($accion, ['aprobar', 'rechazar'], true) && $es_rrhh) {

        $id_licencia = (int) ($_POST['id_licencia'] ?? 0);
        $comentario  = trim($_POST['comentario'] ?? '');

        /* Rechazo sin comentario no sirve: el empleado no sabe si le
           conviene pedirla de nuevo. */
        if ($accion === 'rechazar' && $comentario === '') {
            header('Location: licencias.php?msg=falta_motivo');
            exit;
        }

        /* El estado = 'pendiente' del WHERE es para que si dos de RRHH
           aprietan a la vez, el segundo no pise la decisión del primero. */
        $resolver = $conexion->prepare("
            UPDATE licencias
               SET estado              = :estado,
                   id_usuario_resuelve = :usuario,
                   fecha_resolucion    = :hoy,
                   comentario          = :comentario
             WHERE id_licencia = :id
               AND estado      = 'pendiente'");

        $resolver->execute([
            ':estado'     => $accion === 'aprobar' ? 'aprobada' : 'rechazada',
            ':usuario'    => $id_usuario,
            ':hoy'        => date('Y-m-d'),
            ':comentario' => $comentario !== '' ? $comentario : null,
            ':id'         => $id_licencia,
        ]);

        $msg = $resolver->rowCount() > 0
             ? ($accion === 'aprobar' ? 'aprobada' : 'rechazada')
             : 'ya_resuelta';

        header('Location: licencias.php?msg=' . $msg);
        exit;
    }

    /* Cancelar una solicitud propia. Acá sí borramos la fila, al revés que
       con los empleados: una pendiente no tiene nada colgando ni ninguna
       decisión que guardar. El id_empleado y el estado del WHERE son la
       seguridad, solo propia y solo pendiente. */
    if ($accion === 'cancelar') {

        $id_licencia = (int) ($_POST['id_licencia'] ?? 0);

        /* Este SELECT parece de más pero no lo es: después del DELETE ya
           no hay forma de saber qué archivo tenía colgado. */
        $buscar = $conexion->prepare("
            SELECT justificativo
              FROM licencias
             WHERE id_licencia = :id
               AND id_empleado = :emp
               AND estado      = 'pendiente'");
        $buscar->execute([':id' => $id_licencia, ':emp' => $id_empleado]);
        $vieja = $buscar->fetch();

        $borrar = $conexion->prepare("
            DELETE FROM licencias
             WHERE id_licencia = :id
               AND id_empleado = :emp
               AND estado      = 'pendiente'");
        $borrar->execute([':id' => $id_licencia, ':emp' => $id_empleado]);

        /* El archivo se borra solo si el DELETE sacó la fila de verdad. */
        if ($borrar->rowCount() > 0 && $vieja && $vieja['justificativo']) {
            borrar_justificativo($vieja['justificativo']);
        }

        header('Location: licencias.php?msg=' . ($borrar->rowCount() > 0 ? 'cancelada' : 'ya_resuelta'));
        exit;
    }
}