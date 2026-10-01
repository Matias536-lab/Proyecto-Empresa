<?php
/* Conexión a la base y único lugar con usuario, clave y nombre.
   Usamos PDO y no mysqli por las consultas preparadas. */


/* En false muestra un mensaje neutro. Va así en la entrega: el error
   crudo de MySQL canta el nombre de la base, el usuario y la consulta. */
define('MODO_DESARROLLO', false);


/* PHP no mira el reloj de Windows: sin esta línea queda en UTC y date()
   devolvía tres horas de más. Lo peor no era la hora en pantalla, era que
   pasadas las 21 en UTC ya es el día siguiente, así que la fichada se
   guardaba con la fecha de mañana y CURDATE() no la encontraba nunca. */
date_default_timezone_set('America/Argentina/Buenos_Aires');


/* Si les cambiaron el puerto de MySQL, va ;port=3307 en el DSN. */
$db_host    = 'localhost';
$db_nombre  = 'gestion_personal';
$db_usuario = 'root';
$db_clave   = '';            // En XAMPP, root no tiene contraseña.


/* Sin el charset utf8mb4 acá las tildes y las ñ salen como "Ferná?ndez". */
$dsn = "mysql:host={$db_host};dbname={$db_nombre};charset=utf8mb4";

$opciones = [
    /* Sin esto una consulta mal escrita no hace nada y no avisa. */
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    /* Preparadas por MySQL y no simuladas por PHP: ahí está la protección
       de verdad contra inyección SQL. */
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {

    $conexion = new PDO($dsn, $db_usuario, $db_clave, $opciones);

    /* MySQL resuelve CURDATE() con su propio reloj, así que también hay
       que avisarle. Probamos con el nombre de la ciudad y no andaba:
       necesita unas tablas que XAMPP no trae. Con -03:00 va. */
    $conexion->exec("SET time_zone = '-03:00'");

} catch (PDOException $e) {

    /* Casi siempre es MySQL apagado en XAMPP o la base sin importar. */

    if (MODO_DESARROLLO) {
        die('<h2>No se pudo conectar a la base de datos</h2>'
          . '<p><strong>Detalle:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>'
          . '<p>Revisá que MySQL esté encendido en el panel de XAMPP '
          . 'y que la base <code>' . htmlspecialchars($db_nombre) . '</code> exista.</p>');
    } else {
        die('<h2>El sistema no está disponible en este momento</h2>'
          . '<p>Volvé a intentar en unos minutos.</p>');
    }
}