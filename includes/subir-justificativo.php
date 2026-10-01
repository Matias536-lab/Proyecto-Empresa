<?php
/* Recibe el adjunto, lo revisa y lo guarda en archivos/licencias con otro
   nombre. Está aparte porque recibir un archivo de afuera es lo más
   peligroso del sistema y queríamos tenerlo corto y en un solo lugar.
   Devuelve ok, error, archivo, nombre y vacio (vacio = no adjuntó nada). */


define('CARPETA_JUSTIFICATIVOS', __DIR__ . '/../archivos/licencias/');

/* 4 MB. Un certificado escaneado pesa menos de 1; un tope más alto solo
   le sirve a alguien que quiera llenarnos el disco. */
define('MAXIMO_JUSTIFICATIVO', 4 * 1024 * 1024);

/* La extensión con la que queda guardado sale de esta tabla y no del
   nombre que mandó el usuario. */
$TIPOS_PERMITIDOS = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];


function guardar_justificativo($campo)
{
    global $TIPOS_PERMITIDOS;

    $vacio = ['ok' => false, 'vacio' => true, 'error' => '',
              'archivo' => null, 'nombre' => null];

    /* UPLOAD_ERR_NO_FILE no es un error, es que dejó el campo vacío. Si
       eso vale o no lo decide quien llama, según el tipo de licencia. */
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return $vacio;
    }

    $archivo = $_FILES[$campo];

    if ($archivo['error'] !== UPLOAD_ERR_OK) {

        $mensajes = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo es demasiado grande.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo es demasiado grande.',
            UPLOAD_ERR_PARTIAL    => 'La subida se cortó por la mitad. Probá de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene dónde guardar el archivo.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo.',
        ];

        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => $mensajes[$archivo['error']] ?? 'No se pudo subir el archivo.'];
    }

    if ($archivo['size'] > MAXIMO_JUSTIFICATIVO) {
        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => 'El archivo no puede pesar más de 4 MB.'];
    }

    if ($archivo['size'] === 0) {
        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => 'El archivo está vacío.'];
    }

    /* Confirma que el temporal lo puso PHP al recibir el formulario y no
       que alguien nos hizo apuntar a otro archivo del servidor. */
    if (!is_uploaded_file($archivo['tmp_name'])) {
        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => 'El archivo no llegó de forma válida.'];
    }

    /* El tipo lo miramos por adentro y no por la extensión: el nombre y
       el $archivo['type'] los elige quien manda el formulario, renombrar
       virus.php a foto.jpg tarda dos segundos. finfo lee los primeros
       bytes (un PDF arranca con %PDF) y eso no se disfraza. */
    $lector = finfo_open(FILEINFO_MIME_TYPE);
    $tipo   = finfo_file($lector, $archivo['tmp_name']);
    finfo_close($lector);

    if (!isset($TIPOS_PERMITIDOS[$tipo])) {
        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => 'Solo se aceptan archivos PDF, JPG o PNG.'];
    }

    /* Si adjuntó "certificado.php" y adentro es un PDF, queda .pdf. */
    $extension = $TIPOS_PERMITIDOS[$tipo];

    /* El nombre lo inventa el servidor, 32 caracteres al azar. No usamos
       nada de lo que mandó el usuario: así no puede meter barras para
       salirse de la carpeta ni adivinar el archivo de otro. */
    $nombre_nuevo = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!is_dir(CARPETA_JUSTIFICATIVOS)) {
        @mkdir(CARPETA_JUSTIFICATIVOS, 0775, true);
    }

    if (!move_uploaded_file($archivo['tmp_name'], CARPETA_JUSTIFICATIVOS . $nombre_nuevo)) {
        return ['ok' => false, 'vacio' => false, 'archivo' => null, 'nombre' => null,
                'error' => 'No se pudo guardar el archivo en el servidor.'];
    }

    /* El original queda guardado solo para mostrarlo en pantalla. */
    $nombre_original = basename($archivo['name']);
    if (strlen($nombre_original) > 160) {
        $nombre_original = substr($nombre_original, -160);
    }

    return ['ok' => true, 'vacio' => false, 'error' => '',
            'archivo' => $nombre_nuevo, 'nombre' => $nombre_original];
}


/* Para cuando la solicitud no se llega a guardar y el archivo quedaría
   suelto en la carpeta sin ninguna licencia que lo nombre. */
function borrar_justificativo($nombre_archivo)
{
    if (!$nombre_archivo) { return; }

    /* basename() de nuevo: no armamos rutas con texto sin limpiar, ni
       cuando viene de nuestro propio código. */
    $ruta = CARPETA_JUSTIFICATIVOS . basename($nombre_archivo);

    if (is_file($ruta)) { @unlink($ruta); }
}