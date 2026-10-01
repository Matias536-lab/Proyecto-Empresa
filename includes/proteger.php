<?php
/* Va en la primera línea de toda página que no deba verse sin login. Sin
   esto cualquiera escribe localhost/asistens/dashboard.php y entra. */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['id_usuario'])) {
    header('Location: index.php?motivo=sesion');
    exit;   /* Sin el exit PHP sigue de largo y manda el HTML igual. */
}


/* En las pantallas de RRHH, exigir_rol('rrhh') después del require. Un
   empleado que escriba la dirección a mano vuelve a la suya. */
function exigir_rol($rol_necesario)
{
    if (($_SESSION['rol'] ?? '') !== $rol_necesario) {
        header('Location: asistencia.php?motivo=permiso');
        exit;
    }
}


/* Todo dato de la base que se muestre pasa por acá. Si un empleado se
   llamara <script>alert('hola')</script>, sin esto el navegador lo
   ejecuta en vez de mostrarlo. Eso es XSS. */
function limpio($texto)
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}