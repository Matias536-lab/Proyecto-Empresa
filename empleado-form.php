<?php
/* Alta y edición en el mismo archivo: si viene ?id=5 edita, si no, da
   de alta. Lo juntamos porque el formulario y las validaciones son los
   mismos, y separados había que corregir cada cosa dos veces. */

require_once 'includes/proteger.php';
exigir_rol('rrhh');
require_once 'config/conexion.php';

$id_empleado = (int) ($_GET['id'] ?? 0);
$es_edicion  = $id_empleado > 0;

/* Si el envío falla, acá vuelven los valores que la persona ya había
   tipeado. Si no, tiene que escribir todo otra vez. */
$datos = [
    'legajo' => '', 'nombre' => '', 'apellido' => '', 'dni' => '',
    'email' => '', 'telefono' => '', 'cargo' => '', 'valor_hora' => '',
    'fecha_ingreso' => date('Y-m-d'), 'id_area' => '',
    'usuario' => '', 'rol' => 'empleado',
];

$errores       = [];
$tenia_usuario = false;


if ($es_edicion) {

    $consulta = $conexion->prepare("
        SELECT e.*, u.usuario, u.rol
          FROM empleados e
          LEFT JOIN usuarios u ON u.id_empleado = e.id_empleado
         WHERE e.id_empleado = :id");
    $consulta->execute([':id' => $id_empleado]);
    $fila = $consulta->fetch();

    if (!$fila) {
        header('Location: empleados.php');
        exit;
    }

    foreach ($datos as $campo => $_) {
        if (array_key_exists($campo, $fila) && $fila[$campo] !== null) {
            $datos[$campo] = $fila[$campo];
        }
    }

    $tenia_usuario = ($fila['usuario'] !== null);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* trim porque un legajo pegado con un espacio al final el UNIQUE de
       la base lo toma como distinto. */
    foreach ($datos as $campo => $_) {
        $datos[$campo] = trim($_POST[$campo] ?? '');
    }

    $clave       = $_POST['clave'] ?? '';
    $quiere_user = ($datos['usuario'] !== '');

    /* Validamos todo acá, del lado del servidor. El required del HTML lo
       desactivás con F12; esto no se saltea. */

    if ($datos['legajo'] === '') {
        $errores['legajo'] = 'El legajo es obligatorio.';
    } elseif (strlen($datos['legajo']) > 10) {
        $errores['legajo'] = 'El legajo no puede tener más de 10 caracteres.';
    }

    if ($datos['nombre'] === '')   { $errores['nombre']   = 'El nombre es obligatorio.'; }
    if ($datos['apellido'] === '') { $errores['apellido'] = 'El apellido es obligatorio.'; }

    if ($datos['dni'] === '') {
        $errores['dni'] = 'El DNI es obligatorio.';
    } elseif (!preg_match('/^\d{7,10}$/', $datos['dni'])) {
        $errores['dni'] = 'El DNI debe tener entre 7 y 10 dígitos, sin puntos.';
    }

    if ($datos['email'] !== '' && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'Ese correo no tiene un formato válido.';
    }

    /* El valor hora es opcional, se lo podés cargar después. Cambiamos la
       coma por punto porque casi todos escriben 3500,50. */
    $datos['valor_hora'] = str_replace(',', '.', $datos['valor_hora']);

    if ($datos['valor_hora'] !== '') {
        if (!is_numeric($datos['valor_hora'])) {
            $errores['valor_hora'] = 'El valor hora tiene que ser un número.';
        } elseif ((float) $datos['valor_hora'] <= 0) {
            $errores['valor_hora'] = 'El valor hora tiene que ser mayor que cero.';
        } elseif ((float) $datos['valor_hora'] > 99999999) {
            $errores['valor_hora'] = 'Ese valor hora es demasiado alto.';
        }
    }

    if ($datos['fecha_ingreso'] === '') {
        $errores['fecha_ingreso'] = 'La fecha de ingreso es obligatoria.';
    } elseif ($datos['fecha_ingreso'] > date('Y-m-d')) {
        $errores['fecha_ingreso'] = 'La fecha de ingreso no puede ser futura.';
    }

    /* Trampa: el "id_empleado != :id" va para la edición. Sin eso el
       empleado se encuentra a sí mismo y dice que el legajo está repetido. */
    $excluir = $es_edicion ? " AND id_empleado != :id" : "";
    $extra   = $es_edicion ? [':id' => $id_empleado] : [];

    if (!isset($errores['legajo'])) {
        $c = $conexion->prepare("SELECT 1 FROM empleados WHERE legajo = :v $excluir");
        $c->execute([':v' => $datos['legajo']] + $extra);
        if ($c->fetch()) { $errores['legajo'] = 'Ya existe otro empleado con ese legajo.'; }
    }

    if (!isset($errores['dni'])) {
        $c = $conexion->prepare("SELECT 1 FROM empleados WHERE dni = :v $excluir");
        $c->execute([':v' => $datos['dni']] + $extra);
        if ($c->fetch()) { $errores['dni'] = 'Ya existe otro empleado con ese DNI.'; }
    }

    if ($quiere_user) {

        if (strlen($datos['usuario']) < 3) {
            $errores['usuario'] = 'El usuario debe tener al menos 3 caracteres.';
        } elseif (!preg_match('/^[a-z0-9._-]+$/i', $datos['usuario'])) {
            $errores['usuario'] = 'Solo se permiten letras, números, punto, guion y guion bajo.';
        } else {
            $c = $conexion->prepare(
                "SELECT 1 FROM usuarios WHERE usuario = :v"
                . ($es_edicion ? " AND id_empleado != :id" : ""));
            $c->execute([':v' => $datos['usuario']] + $extra);
            if ($c->fetch()) { $errores['usuario'] = 'Ese nombre de usuario ya está tomado.'; }
        }

        /* En la edición, si el campo de clave queda vacío le dejamos la
           que ya tenía. */
        $hace_falta_clave = !$es_edicion || !$tenia_usuario;

        if ($hace_falta_clave && $clave === '') {
            $errores['clave'] = 'Poné una contraseña para el nuevo usuario.';
        } elseif ($clave !== '' && strlen($clave) < 6) {
            $errores['clave'] = 'La contraseña debe tener al menos 6 caracteres.';
        }
    }


    /* Tocamos dos tablas, así que va todo en una transacción. Si falla el
       usuario no queremos que quede el empleado cargado a medias. */

    if (count($errores) === 0) {

        $conexion->beginTransaction();

        try {
            $area = ($datos['id_area'] === '') ? null : (int) $datos['id_area'];

            $campos = [
                ':legajo'   => $datos['legajo'],
                ':nombre'   => $datos['nombre'],
                ':apellido' => $datos['apellido'],
                ':dni'      => $datos['dni'],
                ':email'    => $datos['email'] !== '' ? $datos['email'] : null,
                ':telefono' => $datos['telefono'] !== '' ? $datos['telefono'] : null,
                ':cargo'    => $datos['cargo'] !== '' ? $datos['cargo'] : null,
                /* Vacío va NULL y no 0: un sueldo en cero y uno sin
                   asignar no son lo mismo. */
                ':valor'    => $datos['valor_hora'] !== '' ? $datos['valor_hora'] : null,
                ':fecha'    => $datos['fecha_ingreso'],
                ':area'     => $area,
            ];

            if ($es_edicion) {
                $conexion->prepare("
                    UPDATE empleados
                       SET legajo = :legajo, nombre = :nombre, apellido = :apellido,
                           dni = :dni, email = :email, telefono = :telefono,
                           cargo = :cargo, valor_hora = :valor,
                           fecha_ingreso = :fecha, id_area = :area
                     WHERE id_empleado = :id
                ")->execute($campos + [':id' => $id_empleado]);

                $id_guardado = $id_empleado;

            } else {
                $conexion->prepare("
                    INSERT INTO empleados
                        (legajo, nombre, apellido, dni, email, telefono,
                         cargo, valor_hora, fecha_ingreso, id_area, activo)
                    VALUES
                        (:legajo, :nombre, :apellido, :dni, :email, :telefono,
                         :cargo, :valor, :fecha, :area, 1)
                ")->execute($campos);

                $id_guardado = (int) $conexion->lastInsertId();
            }

            if ($quiere_user) {

                if ($tenia_usuario) {
                    $conexion->prepare("
                        UPDATE usuarios SET usuario = :u, rol = :r
                         WHERE id_empleado = :id
                    ")->execute([':u' => $datos['usuario'], ':r' => $datos['rol'], ':id' => $id_guardado]);

                    if ($clave !== '') {
                        $conexion->prepare("
                            UPDATE usuarios SET password = :p WHERE id_empleado = :id
                        ")->execute([
                            ':p'  => password_hash($clave, PASSWORD_DEFAULT),
                            ':id' => $id_guardado
                        ]);
                    }

                } else {
                    /* password_hash no tiene vuelta atrás: en la base nunca
                       queda la contraseña en texto. */
                    $conexion->prepare("
                        INSERT INTO usuarios (id_empleado, usuario, password, rol, activo)
                        VALUES (:id, :u, :p, :r, 1)
                    ")->execute([
                        ':id' => $id_guardado,
                        ':u'  => $datos['usuario'],
                        ':p'  => password_hash($clave, PASSWORD_DEFAULT),
                        ':r'  => $datos['rol'],
                    ]);
                }
            }

            $conexion->commit();

            header('Location: empleados.php?msg=' . ($es_edicion ? 'editado' : 'creado'));
            exit;

        } catch (PDOException $e) {
            $conexion->rollBack();
            $errores['general'] = MODO_DESARROLLO
                ? 'Error al guardar: ' . $e->getMessage()
                : 'No se pudieron guardar los datos. Intentá de nuevo.';
        }
    }
}

$areas = $conexion->query("SELECT id_area, nombre FROM areas ORDER BY nombre")->fetchAll();

$pagina_activa = 'empleados';
$titulo        = $es_edicion ? 'Editar empleado' : 'Nuevo empleado';

require_once 'includes/cabecera.php';
?>

<main class="contenido">

    <div class="encabezado">
        <div>
            <h2><?= $es_edicion ? 'Editar empleado' : 'Nuevo empleado' ?></h2>
            <p>
                <?= $es_edicion
                    ? 'Modificá los datos del legajo y guardá los cambios.'
                    : 'Completá los datos para dar de alta a una persona.' ?>
            </p>
        </div>
        <a href="empleados.php" class="boton boton--secundario">Volver al listado</a>
    </div>

    <?php if (isset($errores['general'])): ?>
    <div class="alerta alerta--error"><span><?= limpio($errores['general']) ?></span></div>
    <?php endif; ?>

    <?php if (count($errores) > 0): ?>
    <div class="alerta alerta--error">
        <span>Revisá los campos marcados: hay <?= count($errores) ?> dato(s) para corregir.</span>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate style="max-width:820px">

        <div class="caja">
            <div class="caja__titulo"><h3>Datos personales</h3></div>

            <div class="panel__cuerpo">
                <div class="formulario-grilla">

                    <div class="campo">
                        <label for="legajo">Legajo *</label>
                        <input type="text" id="legajo" name="legajo" maxlength="10"
                               class="<?= isset($errores['legajo']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['legajo']) ?>" placeholder="EMP008">
                        <span class="campo__error"><?= limpio($errores['legajo'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="dni">DNI *</label>
                        <input type="text" id="dni" name="dni" maxlength="10"
                               class="<?= isset($errores['dni']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['dni']) ?>" placeholder="Sin puntos">
                        <span class="campo__error"><?= limpio($errores['dni'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="apellido">Apellido *</label>
                        <input type="text" id="apellido" name="apellido" maxlength="50"
                               class="<?= isset($errores['apellido']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['apellido']) ?>">
                        <span class="campo__error"><?= limpio($errores['apellido'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="nombre">Nombre *</label>
                        <input type="text" id="nombre" name="nombre" maxlength="50"
                               class="<?= isset($errores['nombre']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['nombre']) ?>">
                        <span class="campo__error"><?= limpio($errores['nombre'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="email">Correo electrónico</label>
                        <input type="text" id="email" name="email" maxlength="100"
                               class="<?= isset($errores['email']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['email']) ?>">
                        <span class="campo__error"><?= limpio($errores['email'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="telefono">Teléfono</label>
                        <input type="text" id="telefono" name="telefono" maxlength="20"
                               value="<?= limpio($datos['telefono']) ?>">
                        <span class="campo__error"></span>
                    </div>

                </div>
            </div>
        </div>


        <div class="caja">
            <div class="caja__titulo"><h3>Datos laborales</h3></div>

            <div class="panel__cuerpo">
                <div class="formulario-grilla">

                    <div class="campo">
                        <label for="cargo">Cargo</label>
                        <input type="text" id="cargo" name="cargo" maxlength="50"
                               value="<?= limpio($datos['cargo']) ?>" placeholder="Operario, Vendedora...">
                        <span class="campo__error"></span>
                    </div>

                    <div class="campo">
                        <label for="valor_hora">Valor hora</label>
                        <input type="text" id="valor_hora" name="valor_hora"
                               class="<?= isset($errores['valor_hora']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['valor_hora']) ?>"
                               placeholder="Ej: 3500.00" inputmode="decimal">
                        <span class="campo__ayuda">
                            Cuánto cobra por hora trabajada. Si se deja vacío, el
                            empleado <strong>no se puede liquidar</strong> hasta que
                            se le cargue.
                        </span>
                        <span class="campo__error"><?= limpio($errores['valor_hora'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="id_area">Área</label>
                        <select id="id_area" name="id_area">
                            <option value="">Sin asignar</option>
                            <?php foreach ($areas as $a): ?>
                            <option value="<?= (int) $a['id_area'] ?>"
                                <?= (string) $datos['id_area'] === (string) $a['id_area'] ? 'selected' : '' ?>>
                                <?= limpio($a['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__error"></span>
                    </div>

                    <div class="campo">
                        <label for="fecha_ingreso">Fecha de ingreso *</label>
                        <input type="date" id="fecha_ingreso" name="fecha_ingreso"
                               class="<?= isset($errores['fecha_ingreso']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['fecha_ingreso']) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="campo__error"><?= limpio($errores['fecha_ingreso'] ?? '') ?></span>
                    </div>

                </div>
            </div>
        </div>


        <div class="caja">
            <div class="caja__titulo">
                <h3>Acceso al sistema</h3>
                <span class="texto-suave">Opcional</span>
            </div>

            <div class="panel__cuerpo">

                <p class="texto-suave" style="margin-bottom:16px">
                    Si dejás el usuario vacío, la persona queda cargada en el legajo pero
                    <strong>no va a poder entrar al sistema ni fichar</strong>. Se le puede
                    crear el usuario más adelante editando este mismo formulario.
                </p>

                <div class="formulario-grilla">

                    <div class="campo">
                        <label for="usuario">Nombre de usuario</label>
                        <input type="text" id="usuario" name="usuario" maxlength="30"
                               class="<?= isset($errores['usuario']) ? 'error' : '' ?>"
                               value="<?= limpio($datos['usuario']) ?>"
                               placeholder="Ej: jperez" autocomplete="off">
                        <span class="campo__error"><?= limpio($errores['usuario'] ?? '') ?></span>
                    </div>

                    <div class="campo">
                        <label for="rol">Perfil</label>
                        <select id="rol" name="rol">
                            <option value="empleado" <?= $datos['rol'] === 'empleado' ? 'selected' : '' ?>>Empleado</option>
                            <option value="rrhh"     <?= $datos['rol'] === 'rrhh'     ? 'selected' : '' ?>>Recursos Humanos</option>
                        </select>
                        <small>Recursos Humanos ve el panel y administra a todo el personal.</small>
                    </div>

                    <div class="campo ancho-total">
                        <label for="clave">
                            <?= $tenia_usuario ? 'Cambiar contraseña' : 'Contraseña' ?>
                        </label>
                        <input type="password" id="clave" name="clave"
                               class="<?= isset($errores['clave']) ? 'error' : '' ?>"
                               autocomplete="new-password"
                               placeholder="<?= $tenia_usuario
                                   ? 'Dejalo vacío para conservar la actual'
                                   : 'Mínimo 6 caracteres' ?>">
                        <span class="campo__error"><?= limpio($errores['clave'] ?? '') ?></span>
                        <small>
                            Se guarda encriptada. Ni el administrador puede verla después:
                            si se pierde, hay que asignar una nueva desde acá.
                        </small>
                    </div>

                </div>
            </div>
        </div>


        <div class="formulario__acciones">
            <a href="empleados.php" class="boton boton--secundario">Cancelar</a>
            <button type="submit" class="boton boton--primario">
                <?= $es_edicion ? 'Guardar cambios' : 'Dar de alta' ?>
            </button>
        </div>

    </form>

</main>

<?php require_once 'includes/pie.php'; ?>