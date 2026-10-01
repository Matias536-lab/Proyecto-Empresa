<?php
/* Única puerta a los adjuntos: la carpeta archivos/ está cerrada con su
   .htaccess, escribir la ruta a mano no lleva a nada.
   Pedimos el id de la licencia y no el nombre del archivo, así primero
   miramos de quién es y después decidimos si la mostramos. */

require_once 'includes/proteger.php';
require_once 'config/conexion.php';
require_once 'includes/subir-justificativo.php';

$es_rrhh     = (($_SESSION['rol'] ?? '') === 'rrhh');
$id_empleado = (int) $_SESSION['id_empleado'];

$id_licencia = (int) ($_GET['id'] ?? 0);


$consulta = $conexion->prepare("
    SELECT id_empleado, justificativo, justificativo_nombre
      FROM licencias
     WHERE id_licencia = :id");
$consulta->execute([':id' => $id_licencia]);
$lic = $consulta->fetch();


/* Los tres cortes de abajo contestan lo mismo a propósito. Si dijéramos
   "no existe" en un caso y "no es tuya" en el otro, al que va probando
   números le estaríamos confirmando cuáles licencias existen. */

if (!$lic || empty($lic['justificativo'])) {
    header('Location: licencias.php?msg=sin_archivo');
    exit;
}

if (!$es_rrhh && (int) $lic['id_empleado'] !== $id_empleado) {
    header('Location: licencias.php?msg=sin_archivo');
    exit;
}

/* Puede estar en la base y no en el disco, si alguien lo borró a mano. */
$ruta = CARPETA_JUSTIFICATIVOS . basename($lic['justificativo']);

if (!is_file($ruta)) {
    header('Location: licencias.php?msg=sin_archivo');
    exit;
}


/* Volvemos a leer el tipo del contenido, igual que al subirlo. Si en la
   carpeta quedó algo que no corresponde, el navegador recibe el tipo
   real y no el que sugiere la extensión. */

$lector = finfo_open(FILEINFO_MIME_TYPE);
$tipo   = finfo_file($lector, $ruta);
finfo_close($lector);

/* Si no es uno de los tres tipos, no se muestra: se baja como binario. */
$permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'application/octet-stream';
}

/* Le sacamos comillas y saltos de línea porque este nombre viaja dentro
   de una cabecera HTTP: con un salto se pueden inyectar otras. */
$nombre_visible = $lic['justificativo_nombre'] ?: 'justificativo';
$nombre_visible = str_replace(["\r", "\n", '"'], '', $nombre_visible);

header('Content-Type: ' . $tipo);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="' . $nombre_visible . '"');

/* Sin esto, algunos navegadores viejos adivinan el tipo mirando el
   contenido, y si les parece HTML lo ejecutan. */
header('X-Content-Type-Options: nosniff');

/* Un certificado médico no queda en la caché de nadie. */
header('Cache-Control: private, no-store');

readfile($ruta);
exit;