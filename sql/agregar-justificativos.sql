--  NO HACE FALTA EN UNA INSTALACIÓN NUEVA. gestion_personal.sql ya crea
--  estas columnas. Este archivo es el que usamos para agregarlas a la base
--  que ya teníamos cargada, y queda como registro de qué se agregó y cuándo.
--  Si lo corrés sobre una base recién importada va a decir que la columna
--  ya existe, y está bien que lo diga.

-- Justificativos de licencia + limpieza de datos de prueba.
-- Se ejecuta UNA SOLA VEZ.
-- En phpMyAdmin fijate a la izquierda que esté seleccionada la base
-- gestion_personal. Si dice otra cosa, no lo ejecutes.

USE gestion_personal;


-- 1) Fichadas de 0 horas: quedaron de probar el botón, entrada y salida
-- a la misma hora. Andan bien pero en la demo quedan raras.
-- Corré primero este SELECT para ver qué se va a borrar.

SELECT f.id_fichada, f.fecha, f.hora_entrada, f.hora_salida,
       CONCAT(e.apellido, ', ', e.nombre) AS empleado
  FROM fichadas f
  INNER JOIN empleados e ON e.id_empleado = f.id_empleado
 WHERE f.hora_salida IS NOT NULL
   AND f.hora_entrada = f.hora_salida;

-- Si salió lo que esperabas, ahora sí el DELETE. Solo pega en jornadas
-- de duración cero, que nunca son datos reales.

DELETE FROM fichadas
 WHERE hora_salida IS NOT NULL
   AND hora_entrada = hora_salida;


-- 2) Dos nombres para el mismo archivo. El nombre que manda el usuario
-- lo escribe él y puede traer barras, un .php o cualquier cosa, así que
-- ese solo se muestra en pantalla. Para guardar en disco usamos uno
-- aleatorio que inventa el sistema.
-- El archivo va al disco, en la base guardamos nada más el nombre.

ALTER TABLE licencias
    ADD COLUMN justificativo        VARCHAR(80)  NULL DEFAULT NULL
        COMMENT 'Nombre del archivo en el servidor (lo genera el sistema)'
        AFTER motivo,
    ADD COLUMN justificativo_nombre VARCHAR(160) NULL DEFAULT NULL
        COMMENT 'Nombre original del archivo, solo para mostrar'
        AFTER justificativo;


-- 3) Enfermedad no se puede pedir sin certificado. La regla la ponemos
-- en la base y no en el PHP: si comparás el nombre del tipo en el código
-- y mañana alguien lo renombra, la regla deja de aplicarse y nadie se
-- entera. Así alcanza cambiar un 0 por un 1.

ALTER TABLE tipos_licencia
    ADD COLUMN exige_justificativo TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = no se puede pedir sin adjuntar un archivo';

-- El LIKE cubre que esté escrito con o sin mayúscula y con o sin acento.
UPDATE tipos_licencia
   SET exige_justificativo = 1
 WHERE nombre LIKE '%nfermedad%';


-- Control final: Enfermedad tiene que salir con 1 y el resto con 0.

SELECT id_tipo, nombre, dias_maximos, con_goce, exige_justificativo
  FROM tipos_licencia
 ORDER BY exige_justificativo DESC, nombre;