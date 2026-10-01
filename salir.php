<?php
/* Cerrar sesión. Con session_destroy() solo no alcanza: la cookie queda
   en el navegador y la sesión se puede llegar a reusar, así que también
   vaciamos $_SESSION y vencemos la cookie. */

session_start();

$_SESSION = [];

/* Fecha de vencimiento pasada es la forma de borrar una cookie. */
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

session_destroy();

header('Location: index.php');
exit;