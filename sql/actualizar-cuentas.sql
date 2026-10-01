--  Cuentas presentables + datos de ejemplo con movimiento del día de hoy.
--  No toca la estructura: ni tablas, ni columnas, ni empleados.
--
--  phpMyAdmin -> base gestion_personal -> solapa SQL -> pegar y Continuar.
--  Antes hacé la copia: solapa "Exportar" -> Continuar. Si sale mal se
--  vuelve desde "Importar".
--  Todo va con CURDATE(), así que funciona el día que lo corras.

USE gestion_personal;


--  1) CUENTAS. Pasan a nombre.apellido, como en cualquier empresa.
--  La contraseña de las cinco es:  Asistens26
--  Lo de abajo no es la contraseña, es el hash de password_hash(). Son
--  todos distintos aunque la clave sea la misma porque password_hash le
--  mete un valor al azar (salt), así en la base no se nota que repiten.

UPDATE usuarios SET usuario = 'laura.gimenez',
    password = '$2y$12$hHjI8YY1RGgQN7gSRzA7S.EqTtBMSfn4MXWN2YimrIceNvLWJROvm'
  WHERE id_empleado = 1;

UPDATE usuarios SET usuario = 'martin.sosa',
    password = '$2y$12$FUkHpweyo4Gw1133k4bVPONuvq4KfYK3GOv.PSLNBi3N6CZgLtYdK'
  WHERE id_empleado = 2;

UPDATE usuarios SET usuario = 'carolina.ferreyra',
    password = '$2y$12$KqIpdI.VHkgggY2hz88nMOyUxCkkLDgV.aj2I6XC7num/D3OqDlCG'
  WHERE id_empleado = 3;

UPDATE usuarios SET usuario = 'diego.quiroga',
    password = '$2y$12$Oacswa4JZypnZiEtVrVNguRLwy8VEw.sJklm4N.y9EYrzWfg9KOp6'
  WHERE id_empleado = 4;

UPDATE usuarios SET usuario = 'ana.ledesma',
    password = '$2y$12$AnlZXJNkghziTd.iEVvCUeTGMJ3vdg3.MO9IOnoBA6v5eiYfMO/BS'
  WHERE id_empleado = 5;


--  2) LA JORNADA DE HOY. Armada para que en el panel se vean los cuatro
--  estados juntos: Quiroga y Ledesma trabajando, Paz terminada,
--  Ferreyra de licencia, Giménez y Sosa sin fichar.
--  Esos dos quedan sin fichar a propósito: son las cuentas que usamos en
--  la demo y si tuvieran la entrada cargada el botón ya diría "Marcar
--  salida".

DELETE FROM fichadas WHERE fecha = CURDATE();

INSERT INTO fichadas (id_empleado, fecha, hora_entrada, hora_salida, observacion) VALUES
    (4, CURDATE(), '08:30:00', NULL, NULL),
    (5, CURDATE(), '08:00:00', NULL, NULL),
    (6, CURDATE(), '06:00:00', '10:30:00', 'Turno de depósito');


--  3) LICENCIAS

--  3a) La de Ferreyra pasa a aprobada y se corre a esta semana. Es el
--      único de los cuatro estados que no sale de una fichada.
UPDATE licencias
   SET estado              = 'aprobada',
       fecha_desde         = DATE_SUB(CURDATE(), INTERVAL 1 DAY),
       fecha_hasta         = DATE_ADD(CURDATE(), INTERVAL 8 DAY),
       dias_solicitados    = 10,
       id_usuario_resuelve = 1,
       fecha_resolucion    = DATE_SUB(CURDATE(), INTERVAL 3 DAY),
       comentario          = 'Vacaciones acordadas con el área.'
 WHERE id_empleado = 3 AND estado = 'pendiente';

--  3b) Las pendientes se corren a hace dos días. Con fechas más viejas
--      parece que RRHH tiene todo abandonado.
UPDATE licencias
   SET fecha_solicitud = DATE_SUB(CURDATE(), INTERVAL 2 DAY)
 WHERE estado = 'pendiente';

--  3c) Esta es la que usamos en la demo: pedís una licencia que caiga
--      dentro de estas fechas y el sistema la rechaza por superposición
--      y te dice con cuál se pisa. El tipo 1 es Vacaciones, hasta 14 días.
INSERT INTO licencias
    (id_empleado, id_tipo, fecha_solicitud, fecha_desde, fecha_hasta,
     dias_solicitados, motivo, estado)
VALUES
    (2, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY),
        DATE_ADD(CURDATE(), INTERVAL 10 DAY),
        DATE_ADD(CURDATE(), INTERVAL 17 DAY),
        8, 'Vacaciones de temporada', 'pendiente');


--  4) LAS ÚLTIMAS DOS SEMANAS. Sin esto el reporte del mes sale casi todo
--  en cero y con eso cerramos la demo.
--  Va con INSERT IGNORE porque la tabla tiene un UNIQUE (id_empleado,
--  fecha): si la fichada ya estaba cargada la saltea y no corta con error.

INSERT IGNORE INTO fichadas (id_empleado, fecha, hora_entrada, hora_salida, observacion) VALUES
    (2, DATE_SUB(CURDATE(), INTERVAL  1 DAY), '08:00:00', '17:05:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  1 DAY), '08:30:00', '16:30:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  1 DAY), '08:00:00', '16:00:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  1 DAY), '07:00:00', '15:00:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL  4 DAY), '08:02:00', '17:00:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL  4 DAY), '09:05:00', '18:03:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  4 DAY), '08:32:00', '16:35:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  4 DAY), '08:00:00', '16:05:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  4 DAY), '07:05:00', '15:02:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '08:05:00', '17:02:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '09:02:00', '18:01:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '08:35:00', '16:32:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '08:02:00', '16:00:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '07:02:00', '15:05:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL  6 DAY), '08:01:00', '17:08:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL  6 DAY), '09:08:00', '18:05:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  6 DAY), '08:31:00', '16:30:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  6 DAY), '08:00:00', '16:02:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  6 DAY), '07:00:00', '15:00:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '08:08:00', '17:01:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '09:00:00', '18:00:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '08:30:00', '16:38:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '08:05:00', '16:00:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '07:03:00', '15:01:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL  8 DAY), '08:00:00', '17:04:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL  8 DAY), '09:03:00', '18:02:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL  8 DAY), '08:34:00', '16:31:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL  8 DAY), '08:00:00', '16:08:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL  8 DAY), '07:00:00', '15:03:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL 11 DAY), '08:03:00', '17:00:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL 11 DAY), '09:06:00', '18:04:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL 11 DAY), '08:32:00', '16:34:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL 11 DAY), '08:01:00', '16:00:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL 11 DAY), '07:01:00', '15:00:00', NULL),
    (2, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '08:06:00', '17:06:00', NULL),
    (3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '09:01:00', '18:00:00', NULL),
    (4, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '08:38:00', '16:30:00', NULL),
    (5, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '08:00:00', '16:03:00', NULL),
    (6, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '07:04:00', '15:02:00', NULL);


--  Control final: corré esto y fijate que los números cierren. Tienen que
--  dar 5 cuentas, 3 fichadas de hoy, 2 trabajando y 1 de licencia.

SELECT 'Cuentas'            AS control, GROUP_CONCAT(usuario ORDER BY usuario SEPARATOR ' · ') AS resultado FROM usuarios
UNION ALL
SELECT 'Fichadas de hoy',    CAST(COUNT(*) AS CHAR) FROM fichadas WHERE fecha = CURDATE()
UNION ALL
SELECT 'Trabajando ahora',   CAST(COUNT(*) AS CHAR) FROM fichadas WHERE fecha = CURDATE() AND hora_salida IS NULL
UNION ALL
SELECT 'De licencia hoy',    CAST(COUNT(*) AS CHAR) FROM licencias WHERE estado = 'aprobada' AND CURDATE() BETWEEN fecha_desde AND fecha_hasta
UNION ALL
SELECT 'Licencias pendientes', CAST(COUNT(*) AS CHAR) FROM licencias WHERE estado = 'pendiente'
UNION ALL
SELECT 'Fichadas del mes',   CAST(COUNT(*) AS CHAR) FROM fichadas WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE());