<?php
/* El logo en un solo lugar. Se usa con logo(34), el número son píxeles.
   El disco blanco de atrás (clase .logo, en base.css) va porque el logo
   es azul y la barra de arriba también: sin el disco se perdía. */

function logo($px = 34, $clase = '')
{
    $px = (int) $px;
    printf(
        '<img src="img/logo.png" alt="Asistens" class="logo %s" width="%d" height="%d">',
        htmlspecialchars($clase, ENT_QUOTES, 'UTF-8'),
        $px,
        $px
    );
}