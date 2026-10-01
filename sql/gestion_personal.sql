-- Base del sistema de gestión de personal - proyecto de 7mo
-- Se importa desde phpMyAdmin: pestaña "Importar" -> este archivo -> Continuar.
-- OJO CON ESTO: el DROP DATABASE de abajo borra gestion_personal entera, con
-- todos los datos que haya cargados. Este archivo es para instalar de cero,
-- no lo corras nunca sobre una base que ya se está usando.


DROP DATABASE IF EXISTS gestion_personal;

CREATE DATABASE gestion_personal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE gestion_personal;


-- Sectores de la empresa

CREATE TABLE areas (
    id_area     INT           NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(50)   NOT NULL,
    descripcion VARCHAR(150)  NULL,

    PRIMARY KEY (id_area),
    UNIQUE KEY uq_areas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Empleados

CREATE TABLE empleados (
    id_empleado   INT          NOT NULL AUTO_INCREMENT,
    legajo        VARCHAR(10)  NOT NULL,
    nombre        VARCHAR(50)  NOT NULL,
    apellido      VARCHAR(50)  NOT NULL,
    dni           VARCHAR(10)  NOT NULL,
    email         VARCHAR(100) NULL,
    telefono      VARCHAR(20)  NULL,
    cargo         VARCHAR(50)  NULL,
    -- Cuánto cobra por hora. Va DECIMAL y no FLOAT: FLOAT no guarda 0,10
    -- exacto y sumando aparecen diferencias de centavos. NULL = sin asignar,
    -- que no es lo mismo que cobrar cero.
    valor_hora    DECIMAL(10,2) NULL DEFAULT NULL,
    fecha_ingreso DATE         NOT NULL,
    id_area       INT          NULL,
    activo        TINYINT(1)   NOT NULL DEFAULT 1,

    PRIMARY KEY (id_empleado),
    UNIQUE KEY uq_empleados_legajo (legajo),
    UNIQUE KEY uq_empleados_dni    (dni),
    KEY idx_empleados_area     (id_area),
    KEY idx_empleados_apellido (apellido),

    CONSTRAINT fk_empleados_area
        FOREIGN KEY (id_area) REFERENCES areas (id_area)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La baja de un empleado va por activo = 0, no por DELETE. Si lo borrás de
-- verdad te llevás puestas sus fichadas y licencias, y el reporte de horas de
-- los meses anteriores queda mal. Preferimos que el legajo siga estando.


-- Usuarios del sistema. Un empleado tiene un usuario como máximo.

CREATE TABLE usuarios (
    id_usuario  INT                       NOT NULL AUTO_INCREMENT,
    id_empleado INT                       NOT NULL,
    usuario     VARCHAR(30)               NOT NULL,
    password    VARCHAR(255)              NOT NULL,
    rol         ENUM('rrhh','empleado')   NOT NULL DEFAULT 'empleado',
    activo      TINYINT(1)                NOT NULL DEFAULT 1,
    ultimo_acceso DATETIME                NULL,

    PRIMARY KEY (id_usuario),
    UNIQUE KEY uq_usuarios_usuario  (usuario),
    UNIQUE KEY uq_usuarios_empleado (id_empleado),

    CONSTRAINT fk_usuarios_empleado
        FOREIGN KEY (id_empleado) REFERENCES empleados (id_empleado)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- En password no va la clave, va el hash que devuelve password_hash() de PHP.
-- Si guardáramos el texto, cualquiera que entre a phpMyAdmin se lleva todas
-- las contraseñas de la empresa. Al entrar comparamos con password_verify().
-- Le dejamos 255 y no 60 por si más adelante cambia el algoritmo.


-- Fichadas: una fila por empleado y por día

CREATE TABLE fichadas (
    id_fichada   INT          NOT NULL AUTO_INCREMENT,
    id_empleado  INT          NOT NULL,
    fecha        DATE         NOT NULL,
    hora_entrada TIME         NOT NULL,
    hora_salida  TIME         NULL,
    observacion  VARCHAR(200) NULL,

    PRIMARY KEY (id_fichada),
    UNIQUE KEY uq_fichada_empleado_fecha (id_empleado, fecha),
    KEY idx_fichadas_fecha (fecha),

    CONSTRAINT fk_fichadas_empleado
        FOREIGN KEY (id_empleado) REFERENCES empleados (id_empleado)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- hora_salida en NULL quiere decir que el empleado está adentro: fichó la
-- entrada y todavía no la salida. De ahí sacamos qué botón mostrarle.
--
-- El UNIQUE (id_empleado, fecha) está para que nadie fiche dos veces el mismo
-- día. Lo dejamos en la base y no solo en el PHP porque si mañana alguien
-- carga a mano desde phpMyAdmin igual lo frena.


-- Tipos de licencia. Es un catálogo para que no escriba cada uno "vacasiones".

CREATE TABLE tipos_licencia (
    id_tipo       INT          NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(50)  NOT NULL,
    dias_maximos  INT          NULL,
    con_goce      TINYINT(1)   NOT NULL DEFAULT 1,
    -- 1 = no se puede pedir sin adjuntar un archivo. La regla vive acá y no
    -- en el PHP: mañana se le pide certificado a otro tipo cambiando un 0.
    exige_justificativo TINYINT(1) NOT NULL DEFAULT 0,
    activo        TINYINT(1)   NOT NULL DEFAULT 1,

    PRIMARY KEY (id_tipo),
    UNIQUE KEY uq_tipos_licencia_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Licencias: la solicitud y cómo se resolvió

CREATE TABLE licencias (
    id_licencia         INT      NOT NULL AUTO_INCREMENT,
    id_empleado         INT      NOT NULL,
    id_tipo             INT      NOT NULL,
    fecha_solicitud     DATE     NOT NULL,
    fecha_desde         DATE     NOT NULL,
    fecha_hasta         DATE     NOT NULL,
    dias_solicitados    INT      NOT NULL,
    motivo              VARCHAR(300) NULL,
    -- Dos nombres distintos a propósito: el primero es el nombre con el que
    -- el archivo quedó en el disco, que lo inventa el sistema. El segundo es
    -- el original del empleado y se usa solo para mostrarlo en pantalla.
    justificativo        VARCHAR(80)  NULL DEFAULT NULL,
    justificativo_nombre VARCHAR(160) NULL DEFAULT NULL,
    estado              ENUM('pendiente','aprobada','rechazada')
                                 NOT NULL DEFAULT 'pendiente',
    id_usuario_resuelve INT      NULL,
    fecha_resolucion    DATE     NULL,
    comentario          VARCHAR(300) NULL,

    PRIMARY KEY (id_licencia),
    KEY idx_licencias_empleado (id_empleado),
    KEY idx_licencias_estado   (estado),
    KEY idx_licencias_fechas   (fecha_desde, fecha_hasta),

    CONSTRAINT fk_licencias_empleado
        FOREIGN KEY (id_empleado) REFERENCES empleados (id_empleado)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_licencias_tipo
        FOREIGN KEY (id_tipo) REFERENCES tipos_licencia (id_tipo)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_licencias_usuario_resuelve
        FOREIGN KEY (id_usuario_resuelve) REFERENCES usuarios (id_usuario)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT chk_licencias_rango
        CHECK (fecha_hasta >= fecha_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id_usuario_resuelve, fecha_resolucion y comentario quedan en NULL mientras
-- la licencia está pendiente. Se llenan cuando RRHH la aprueba o la rechaza.


-- Datos de prueba

INSERT INTO areas (nombre, descripcion) VALUES
    ('Administración', 'Contaduría, RRHH y tareas administrativas'),
    ('Producción',     'Planta y fabricación'),
    ('Ventas',         'Atención al cliente y comercialización'),
    ('Sistemas',       'Soporte técnico e informática'),
    ('Depósito',       'Logística, stock y despacho');

INSERT INTO empleados
    (legajo, nombre, apellido, dni, email, telefono, cargo, valor_hora, fecha_ingreso, id_area, activo)
VALUES
    ('EMP001','Laura',   'Giménez',  '30111222','laura.gimenez@empresa.com', '3814000001','Jefa de RRHH',         4800.00, '2018-03-05', 1, 1),
    ('EMP002','Martín',  'Sosa',     '32444555','martin.sosa@empresa.com',   '3814000002','Operario',             3200.00, '2020-07-15', 2, 1),
    ('EMP003','Carolina','Ferreyra', '35666777','caro.ferreyra@empresa.com', '3814000003','Vendedora',            3500.00, '2021-02-01', 3, 1),
    ('EMP004','Diego',   'Quiroga',  '28999000','diego.quiroga@empresa.com', '3814000004','Técnico de Sistemas',  4100.00, '2017-11-20', 4, 1),
    ('EMP005','Ana',     'Ledesma',  '36123456','ana.ledesma@empresa.com',   '3814000005','Administrativa',       3600.00, '2022-09-12', 1, 1),
    ('EMP006','Javier',  'Paz',      '31777888','javier.paz@empresa.com',    '3814000006','Encargado de Depósito',   NULL, '2019-05-02', 5, 1),
    ('EMP007','Rocío',   'Villalba', '38222333','rocio.villalba@empresa.com','3814000007','Operaria',             3300.00, '2023-04-10', 2, 0);
    -- A Paz le dejamos el valor hora en NULL a propósito: sirve para mostrar
    -- que el sistema avisa cuando falta el dato en vez de liquidar cualquier cosa.
    -- Rocío va con activo = 0 a propósito, así probamos que el listado
    -- principal no la muestre.

-- CREDENCIALES DE PRUEBA para entrar al sistema:
--     lgimenez  / rrhh123    (RRHH)
--     msosa     / emp123     (empleado)
--     cferreyra / emp123
--     dquiroga  / emp123
INSERT INTO usuarios (id_empleado, usuario, password, rol, activo) VALUES
    (1, 'laura.gimenez',     '$2y$12$hHjI8YY1RGgQN7gSRzA7S.EqTtBMSfn4MXWN2YimrIceNvLWJROvm', 'rrhh',     1),
    (2, 'martin.sosa',       '$2y$12$FUkHpweyo4Gw1133k4bVPONuvq4KfYK3GOv.PSLNBi3N6CZgLtYdK', 'empleado', 1),
    (3, 'carolina.ferreyra', '$2y$12$KqIpdI.VHkgggY2hz88nMOyUxCkkLDgV.aj2I6XC7num/D3OqDlCG', 'empleado', 1),
    (4, 'diego.quiroga',     '$2y$12$Oacswa4JZypnZiEtVrVNguRLwy8VEw.sJklm4N.y9EYrzWfg9KOp6', 'empleado', 1),
    (5, 'ana.ledesma',       '$2y$12$AnlZXJNkghziTd.iEVvCUeTGMJ3vdg3.MO9IOnoBA6v5eiYfMO/BS', 'empleado', 1);
    -- La contraseña de las cinco es Asistens26. Lo de arriba no es la clave,
    -- es el hash: son todos distintos aunque la clave sea la misma porque
    -- password_hash() le mete un valor al azar a cada uno.

INSERT INTO tipos_licencia (nombre, dias_maximos, con_goce, exige_justificativo) VALUES
    ('Vacaciones',        14, 1, 0),
    ('Enfermedad',        30, 1, 1),
    ('Estudio',           10, 1, 0),
    ('Duelo',              3, 1, 0),
    ('Matrimonio',        10, 1, 0),
    ('Asuntos personales', 5, 0, 0);
    -- Enfermedad es la única que exige certificado. El sistema no deja
    -- enviar el pedido sin el archivo.

-- Una semana de 4 empleados, con algún tarde y algún retiro antes.
INSERT INTO fichadas (id_empleado, fecha, hora_entrada, hora_salida, observacion) VALUES
    (2,'2026-08-24','08:02:00','17:05:00', NULL),
    (2,'2026-08-25','07:58:00','17:00:00', NULL),
    (2,'2026-08-26','08:15:00','17:02:00', 'Llegó tarde'),
    (2,'2026-08-27','08:00:00','13:00:00', 'Se retiró antes'),
    (2,'2026-08-28','08:01:00','17:10:00', NULL),

    (3,'2026-08-24','09:00:00','18:00:00', NULL),
    (3,'2026-08-25','09:05:00','18:03:00', NULL),
    (3,'2026-08-26','08:55:00','18:00:00', NULL),
    (3,'2026-08-27','09:00:00','18:00:00', NULL),
    (3,'2026-08-28','09:10:00','17:45:00', NULL),

    (4,'2026-08-24','08:30:00','16:30:00', NULL),
    (4,'2026-08-25','08:28:00','16:35:00', NULL),
    (4,'2026-08-26','08:30:00','16:30:00', NULL),
    (4,'2026-08-27','08:45:00','16:30:00', NULL),
    (4,'2026-08-28','08:30:00','16:28:00', NULL),

    (5,'2026-08-24','08:00:00','16:00:00', NULL),
    (5,'2026-08-25','08:00:00','16:00:00', NULL),
    (5,'2026-08-26','08:00:00','16:00:00', NULL),
    (5,'2026-08-27','08:02:00','16:05:00', NULL),
    (5,'2026-08-28','08:00:00','16:00:00', NULL),

    (6,'2026-08-31','07:00:00','15:00:00', NULL),

    -- Esta queda abierta a propósito, sin hora de salida.
    (2,'2026-09-01','08:00:00', NULL, NULL);

INSERT INTO licencias
    (id_empleado, id_tipo, fecha_solicitud, fecha_desde, fecha_hasta,
     dias_solicitados, motivo, estado, id_usuario_resuelve, fecha_resolucion, comentario)
VALUES
    -- Pendientes
    (3, 1, '2026-08-28','2026-09-15','2026-09-26', 12,
     'Vacaciones anuales', 'pendiente', NULL, NULL, NULL),

    (4, 3, '2026-08-30','2026-09-08','2026-09-09',  2,
     'Rendir final de la facultad', 'pendiente', NULL, NULL, NULL),

    (5, 6, '2026-09-01','2026-09-04','2026-09-04',  1,
     'Trámite personal', 'pendiente', NULL, NULL, NULL),

    -- Ya resueltas, para tener historial
    (2, 2, '2026-08-10','2026-08-11','2026-08-13',  3,
     'Gripe con certificado médico', 'aprobada', 1, '2026-08-10',
     'Certificado presentado en tiempo y forma'),

    (6, 1, '2026-07-01','2026-07-15','2026-07-26', 12,
     'Vacaciones', 'aprobada', 1, '2026-07-02', NULL),

    (3, 6, '2026-08-05','2026-08-06','2026-08-08',  3,
     'Viaje personal', 'rechazada', 1, '2026-08-05',
     'No se puede cubrir el puesto esa semana');


-- Consultas que nos dejamos anotadas de cuando armamos el PHP.

-- El reporte de horas del mes, que fue la que más nos costó:
-- SELECT e.legajo,
--        CONCAT(e.apellido, ', ', e.nombre) AS empleado,
--        COUNT(f.id_fichada) AS dias_trabajados,
--        ROUND(SUM(TIMESTAMPDIFF(MINUTE, f.hora_entrada, f.hora_salida)) / 60, 2)
--            AS horas_totales
-- FROM empleados e
-- INNER JOIN fichadas f ON f.id_empleado = e.id_empleado
-- WHERE f.hora_salida IS NOT NULL
--   AND MONTH(f.fecha) = 8
--   AND YEAR(f.fecha)  = 2026
-- GROUP BY e.id_empleado, e.legajo, e.apellido, e.nombre
-- ORDER BY e.apellido;

-- Para saber si el tipo está adentro ahora. 0 filas -> va el botón de
-- entrada, 1 fila -> va el de salida.
-- SELECT id_fichada, hora_entrada
-- FROM fichadas
-- WHERE id_empleado = 2 AND fecha = CURDATE() AND hora_salida IS NULL;

-- Los tres números del panel de RRHH:
-- SELECT
--   (SELECT COUNT(*) FROM empleados WHERE activo = 1)               AS empleados_activos,
--   (SELECT COUNT(*) FROM fichadas  WHERE fecha = CURDATE())        AS fichadas_hoy,
--   (SELECT COUNT(*) FROM licencias WHERE estado = 'pendiente')     AS licencias_pendientes;