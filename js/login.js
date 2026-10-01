/* Login, parte del navegador. Acá no se verifica ninguna contraseña:
   eso lo hace index.php contra MySQL.
   Lo que validamos acá es comodidad, no seguridad: el JS se apaga con
   F12 y el formulario se manda igual, así que index.php revisa todo otra vez. */


const formulario  = document.getElementById('formLogin');
const inpUsuario  = document.getElementById('usuario');
const inpPassword = document.getElementById('password');
const alertaError = document.getElementById('alertaError');
const textoError  = document.getElementById('textoError');
const botonEntrar = document.getElementById('botonEntrar');
const botonOjo    = document.getElementById('botonOjo');
const ojoAbierto  = document.getElementById('ojoAbierto');
const ojoCerrado  = document.getElementById('ojoCerrado');


botonOjo.addEventListener('click', function () {
    const estaOculta = inpPassword.type === 'password';

    inpPassword.type = estaOculta ? 'text' : 'password';
    ojoAbierto.classList.toggle('oculto', estaOculta);
    ojoCerrado.classList.toggle('oculto', !estaOculta);

    inpPassword.focus();
});


function mostrarError(mensaje) {
    textoError.textContent = mensaje;
    alertaError.classList.remove('oculto');
}

function ocultarError() {
    alertaError.classList.add('oculto');
}

function marcarCampo(campo, hayError) {
    campo.classList.toggle('error', hayError);
}


function validarFormulario() {
    const usuario  = inpUsuario.value.trim();
    const password = inpPassword.value;

    marcarCampo(inpUsuario, false);
    marcarCampo(inpPassword, false);
    ocultarError();

    if (usuario === '') {
        marcarCampo(inpUsuario, true);
        inpUsuario.focus();
        mostrarError('Ingresá tu nombre de usuario.');
        return false;
    }

    if (usuario.length < 3) {
        marcarCampo(inpUsuario, true);
        inpUsuario.focus();
        mostrarError('El usuario debe tener al menos 3 caracteres.');
        return false;
    }

    if (password === '') {
        marcarCampo(inpPassword, true);
        inpPassword.focus();
        mostrarError('Ingresá tu contraseña.');
        return false;
    }

    if (password.length < 6) {
        marcarCampo(inpPassword, true);
        inpPassword.focus();
        mostrarError('La contraseña debe tener al menos 6 caracteres.');
        return false;
    }

    return true;
}


/* Si la validación pasa no frenamos nada: el formulario se va solo a
   index.php, que es el que consulta la base. */
formulario.addEventListener('submit', function (evento) {

    if (!validarFormulario()) {
        evento.preventDefault();
        return;
    }

    /* Bloqueamos el botón así nadie aprieta dos veces y salen dos
       intentos de login. */
    botonEntrar.disabled = true;
    botonEntrar.textContent = 'Verificando...';
});


/* Apenas empiezan a corregir, el cartel de error se va. */
[inpUsuario, inpPassword].forEach(function (campo) {
    campo.addEventListener('input', function () {
        marcarCampo(campo, false);
        ocultarError();
    });
});