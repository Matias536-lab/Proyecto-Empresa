<?php
/* Login. El mismo archivo muestra el form y valida el POST.
   Todo el PHP va arriba del HTML: si imprimís aunque sea un carácter
   antes, header() ya no funciona y nos comimos ese error una vez. */

session_start();

if (isset($_SESSION['id_usuario'])) {
    header('Location: ' . ($_SESSION['rol'] === 'rrhh' ? 'dashboard.php' : 'asistencia.php'));
    exit;
}

require_once 'config/conexion.php';

$error           = '';
$usuario_escrito = '';

/* Lo rebotó el guardián de sesión de otra página. */
if (isset($_GET['motivo']) && $_GET['motivo'] === 'sesion') {
    $error = 'Tenés que iniciar sesión para entrar a esa página.';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Al usuario le hacemos trim porque si lo pegan queda con un espacio
       al final y no entraba nunca. A la clave no: el espacio puede ser parte. */
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['password'] ?? '';

    $usuario_escrito = $usuario;

    if ($usuario === '' || $clave === '') {

        $error = 'Completá el usuario y la contraseña.';

    } else {

        /* Fijate que el usuario va como :usuario y no pegado en el string.
           Si lo concatenamos, con ' OR '1'='1 entra sin contraseña. */
        $sql = "SELECT u.id_usuario, u.usuario, u.password, u.rol,
                       e.id_empleado, e.nombre, e.apellido
                FROM usuarios u
                INNER JOIN empleados e ON e.id_empleado = u.id_empleado
                WHERE u.usuario = :usuario
                  AND u.activo  = 1
                  AND e.activo  = 1
                LIMIT 1";

        $consulta = $conexion->prepare($sql);
        $consulta->execute([':usuario' => $usuario]);
        $fila = $consulta->fetch();

        if ($fila && password_verify($clave, $fila['password'])) {

            /* Cambiamos el id de sesión al entrar, si no alguien te pasa un
               link con la sesión ya fijada y después se mete con esa. */
            session_regenerate_id(true);

            $_SESSION['id_usuario']  = $fila['id_usuario'];
            $_SESSION['id_empleado'] = $fila['id_empleado'];
            $_SESSION['usuario']     = $fila['usuario'];
            $_SESSION['rol']         = $fila['rol'];
            $_SESSION['nombre']      = $fila['nombre'] . ' ' . $fila['apellido'];

            $conexion->prepare("UPDATE usuarios SET ultimo_acceso = NOW()
                                WHERE id_usuario = :id")
                     ->execute([':id' => $fila['id_usuario']]);

            header('Location: ' . ($fila['rol'] === 'rrhh' ? 'dashboard.php' : 'asistencia.php'));
            exit;

        } else {

            /* Un solo mensaje para los dos casos. Si decimos "ese usuario no
               existe" le estamos regalando la lista de usuarios a cualquiera. */
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

/* Única pantalla sin menú, todavía no hay sesión. */
$titulo        = 'Iniciar sesión';
$sin_menu      = true;
$fuente_google = true;

require_once 'includes/cabecera.php';
?>

<div class="login">

    <section class="login__presentacion">

        <div class="login__marca">
            <?php logo(78); ?>
            <div>
                <h1>Asistens</h1>
                <span>Gestión de empleados</span>
            </div>
        </div>

        <div class="login__mensaje">

            <h2>El registro del personal, en un solo lugar.</h2>

            <!-- Los módulos van como dl, igual que las cifras del panel:
                 etiqueta a la izquierda y dato a la derecha. Así el login no
                 parece una portada pegada adelante del sistema. -->
            <dl class="login__modulos">
                <div class="login__modulo">
                    <dt>Legajos</dt>
                    <dd>Alta, edición y baja del personal</dd>
                </div>
                <div class="login__modulo">
                    <dt>Asistencia</dt>
                    <dd>Entradas, salidas y horas trabajadas</dd>
                </div>
                <div class="login__modulo">
                    <dt>Licencias</dt>
                    <dd>Solicitud, aprobación y rechazo</dd>
                </div>
            </dl>
        </div>


    </section>


    <section class="login__formulario">
        <div class="login__caja">

            <h2>Iniciar sesión</h2>
            <p>Ingresá con el usuario que te asignó el administrador.</p>

            <?php if ($error !== ''): ?>
            <div class="alerta alerta--error" id="alertaError">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span id="textoError"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php else: ?>
            <div class="alerta alerta--error oculto" id="alertaError">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span id="textoError"></span>
            </div>
            <?php endif; ?>

            <form id="formLogin" method="POST" action="" novalidate>

                <div class="campo">
                    <label for="usuario">Usuario</label>
                    <div class="campo__con-icono">
                        <svg class="campo__icono" width="18" height="18" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <!-- Si falló el login le devolvemos lo que ya tipeó.
                             Va con htmlspecialchars, si no nos meten un script. -->
                        <input type="text" id="usuario" name="usuario"
                               value="<?= htmlspecialchars($usuario_escrito, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ej: nombre.apellido" autocomplete="username"
                               maxlength="30" autofocus>
                    </div>
                </div>

                <div class="campo">
                    <label for="password">Contraseña</label>
                    <div class="campo__con-icono">
                        <svg class="campo__icono" width="18" height="18" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <!-- La clave no se devuelve rellenada nunca. -->
                        <input type="password" id="password" name="password"
                               placeholder="Ingresá tu contraseña" autocomplete="current-password">

                        <button type="button" class="campo__ojo" id="botonOjo"
                                aria-label="Mostrar u ocultar contraseña">
                            <svg id="ojoAbierto" width="19" height="19" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="ojoCerrado" class="oculto" width="19" height="19" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="boton boton--primario boton--grande" id="botonEntrar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    Iniciar sesión
                </button>
            </form>

            <p class="login__nota">
                ¿No tenés usuario? Las cuentas las crea el área de Recursos Humanos.
            </p>

        </div>
    </section>

</div>

<?php
$script_pagina = 'login.js';
require_once 'includes/pie.php';
?>