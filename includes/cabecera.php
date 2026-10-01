<?php
/* Cabecera de todas las pantallas: el <head> y el CSS están una sola vez
   acá y no repetidos en los siete archivos.
   Se usa con $titulo, $pagina_activa y, en el login, $sin_menu = true y
   $fuente_google = true. */

/* Acá arriba porque el logo lo necesitan el menú y el login. */
require_once __DIR__ . '/logo.php';

if (!isset($titulo))        { $titulo = 'Asistens'; }
if (!isset($pagina_activa)) { $pagina_activa = ''; }
if (!isset($sin_menu))      { $sin_menu = false; }
if (!isset($fuente_google)) { $fuente_google = false; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?> | Asistens</title>

    <link rel="icon" type="image/png" href="img/favicon.png">

    <?php if ($fuente_google): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>

    <!-- El orden de estas cinco líneas no se toca: en CSS gana la última
         regla leída, y adaptable.css tiene que poder corregir a las otras
         cuatro en pantalla chica o al imprimir. -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/navegacion.css">
    <link rel="stylesheet" href="css/componentes.css">
    <link rel="stylesheet" href="css/pantallas.css">
    <link rel="stylesheet" href="css/adaptable.css">
</head>
<body>

<?php
/* Con __DIR__ el include anda desde donde sea que llamen a la cabecera. */
if (!$sin_menu) {
    require_once __DIR__ . '/menu.php';
}