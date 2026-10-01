/* Mueve el número grande del panel de jornada. Separado del PHP para
   que quede en caché y el editor nos marque los errores. */

/* OJO con la hora: el navegador nunca decide qué hora es. La hora la
   manda PHP en data-servidor y acá solo contamos los segundos que
   pasaron desde que cargó la página. Si la pusiera el navegador,
   cualquiera adelanta el reloj de su compu y ficha la hora que quiere.
   Para medir una duración no importa si el reloj está bien puesto. */

(function () {
  var caja = document.getElementById("jornada");

  /* El panel de jornada está solo en esta pantalla, así que si no
       aparece salimos sin hacer ruido. */
  if (!caja) {
    return;
  }

  var cifra = document.getElementById("jornadaCifra");
  if (!cifra) {
    return;
  }

  /* Este renglón chico existe solo si la persona está adentro, puede
       venir vacío. */
  var relojChico = document.getElementById("jornadaReloj");

  /* parseInt con base 10 para que un "08" no se lea en octal en
       navegadores viejos. */
  var modo = caja.getAttribute("data-modo");
  var segServidor = parseInt(caja.getAttribute("data-servidor"), 10);
  var segEntrada = parseInt(caja.getAttribute("data-entrada"), 10);

  /* Jornada cerrada: el total ya es histórico, no se mueve más. */
  if (modo === "fijo" || isNaN(segServidor)) {
    return;
  }

  /* Esto no sirve para saber la hora, solo para restarlo después. */
  var arranque = Date.now();

  function dosDigitos(numero) {
    return String(numero).padStart(2, "0");
  }

  function comoReloj(segundos) {
    if (segundos < 0) {
      segundos = 0;
    }
    var h = Math.floor(segundos / 3600);
    var m = Math.floor((segundos % 3600) / 60);
    var s = segundos % 60;
    return h + ":" + dosDigitos(m) + ":" + dosDigitos(s);
  }

  /* Segundos desde medianoche en el servidor. Acá está el truco. */
  function segundosEnElServidor() {
    var transcurridos = Math.floor((Date.now() - arranque) / 1000);
    return segServidor + transcurridos;
  }

  function actualizar() {
    var ahora = segundosEnElServidor();

    if (modo === "contador") {
      cifra.textContent = comoReloj(ahora - segEntrada);

      if (relojChico) {
        /* El % 86400 es por si dejan la pantalla abierta y pasa
                   la medianoche: sin eso marcaría "25:03" y no "01:03". */
        var delDia = ahora % 86400;
        relojChico.textContent =
          dosDigitos(Math.floor(delDia / 3600)) +
          ":" +
          dosDigitos(Math.floor((delDia % 3600) / 60));
      }
    } else {
      var t = ahora % 86400;
      cifra.textContent =
        dosDigitos(Math.floor(t / 3600)) +
        ":" +
        dosDigitos(Math.floor((t % 3600) / 60)) +
        ":" +
        dosDigitos(t % 60);
    }
  }

  /* PHP ya dejó el valor bien escrito en el HTML, así que si este
       archivo no carga el número igual se ve bien. Queda quieto nomás. */
  actualizar();
  setInterval(actualizar, 1000);
})();
