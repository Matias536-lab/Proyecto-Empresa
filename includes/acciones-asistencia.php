<?php
/* Lo que escribe en la base al apretar un botón. No se abre solo: lo
   incluye asistencia.php antes de dibujar.
   $id_empleado sale siempre de $_SESSION y nunca del POST ni de la URL;
   si viniera de afuera cualquiera ficharía por otro cambiando un número. */

/* Después de guardar, header + exit (post-redirect-get). Sin eso, al dar
   F5 el navegador reenvía el formulario y ficha dos veces. */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion = $_POST['accion'] ?? '';

    /* La hora la pone PHP y no MySQL, así lo que se guarda es lo mismo
       que se ve en pantalla sin depender de dos relojes. */
    $ahora = date('H:i:s');
    $hoy   = date('Y-m-d');

    if ($accion === 'entrada') {

        /* La clave única (id_empleado, fecha) ya lo impide, pero
           preferimos un mensaje claro antes que un error de MySQL. */
        $verificar = $conexion->prepare(
            "SELECT id_fichada FROM fichadas
             WHERE id_empleado = :id AND fecha = :hoy"
        );
        $verificar->execute([':id' => $id_empleado, ':hoy' => $hoy]);

        if ($verificar->fetch()) {
            header('Location: asistencia.php?msg=ya_ficho');
            exit;
        }

        $conexion->prepare(
            "INSERT INTO fichadas (id_empleado, fecha, hora_entrada)
             VALUES (:id, :fecha, :hora)"
        )->execute([':id' => $id_empleado, ':fecha' => $hoy, ':hora' => $ahora]);

        header('Location: asistencia.php?msg=entrada_ok');
        exit;
    }

    if ($accion === 'salida') {

        /* Si alguna de las tres condiciones falla, el UPDATE no toca
           ninguna fila y no pasa nada. No es un error que falte el else. */
        $actualizar = $conexion->prepare(
            "UPDATE fichadas
                SET hora_salida = :hora
              WHERE id_empleado = :id
                AND fecha       = :hoy
                AND hora_salida IS NULL"
        );
        $actualizar->execute([
            ':hora' => $ahora, ':id' => $id_empleado, ':hoy' => $hoy
        ]);

        /* 0 filas cambiadas = no había ninguna jornada abierta. */
        $msg = $actualizar->rowCount() > 0 ? 'salida_ok' : 'sin_abrir';
        header('Location: asistencia.php?msg=' . $msg);
        exit;
    }

    /* Cerrar a mano una jornada que quedó abierta de otro día. Solo RRHH. */
    if ($accion === 'cerrar' && $es_rrhh) {

        $id_fichada = (int) ($_POST['id_fichada'] ?? 0);
        $hora       = $_POST['hora_salida'] ?? '';

        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
            header('Location: asistencia.php?msg=hora_mala');
            exit;
        }

        /* Comparamos horas como texto: en formato HH:MM:SS ordena igual
           que en número, así que no hace falta convertir nada. */
        $consulta = $conexion->prepare(
            "SELECT hora_entrada FROM fichadas
             WHERE id_fichada = :id AND hora_salida IS NULL"
        );
        $consulta->execute([':id' => $id_fichada]);
        $fila = $consulta->fetch();

        if (!$fila) {
            header('Location: asistencia.php?msg=no_existe');
            exit;
        }

        if ($hora . ':00' <= $fila['hora_entrada']) {
            header('Location: asistencia.php?msg=hora_menor');
            exit;
        }

        $conexion->prepare(
            "UPDATE fichadas
                SET hora_salida = :hora,
                    observacion = CONCAT(IFNULL(observacion, ''),
                                  ' [Cerrada manualmente por RRHH]')
              WHERE id_fichada = :id AND hora_salida IS NULL"
        )->execute([':hora' => $hora . ':00', ':id' => $id_fichada]);

        header('Location: asistencia.php?msg=cerrada_ok');
        exit;
    }
}