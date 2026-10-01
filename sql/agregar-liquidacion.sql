--  NO HACE FALTA EN UNA INSTALACIÓN NUEVA. gestion_personal.sql ya crea
--  estas columnas. Este archivo es el que usamos para agregarlas a la base
--  que ya teníamos cargada, y queda como registro de qué se agregó y cuándo.
--  Si lo corrés sobre una base recién importada va a decir que la columna
--  ya existe, y está bien que lo diga.

-- Liquidación de sueldos. Se ejecuta UNA SOLA VEZ.
-- Fijate antes en phpMyAdmin que esté seleccionada gestion_personal.
-- Agrega lo único que faltaba: cuánto cobra por hora cada empleado.

USE gestion_personal;


-- Valor hora y no sueldo mensual: horas, faltas y licencias se miden en
-- tiempo, así la cuenta sale sola sin inventar cuánto vale un día.
-- DECIMAL y no FLOAT porque FLOAT no puede guardar 0,10 exacto y
-- sumando aparecen diferencias de centavos. Para plata siempre DECIMAL.
-- NULL por defecto a propósito: un empleado nuevo no tiene sueldo
-- asignado todavía, y eso no es lo mismo que cobrar cero.

ALTER TABLE empleados
    ADD COLUMN valor_hora DECIMAL(10,2) NULL DEFAULT NULL
        COMMENT 'Cuánto cobra por hora trabajada. NULL = sin asignar'
        AFTER cargo;


-- Valores de prueba, cargados por legajo porque el legajo no cambia.
-- A Paz lo dejamos en NULL a propósito, para mostrar que el sistema
-- avisa cuando falta el dato en vez de liquidar cualquier cosa.

UPDATE empleados SET valor_hora = 4800.00 WHERE legajo = 'EMP001';  -- Jefa de RRHH
UPDATE empleados SET valor_hora = 3200.00 WHERE legajo = 'EMP002';  -- Operario
UPDATE empleados SET valor_hora = 3500.00 WHERE legajo = 'EMP003';  -- Vendedora
UPDATE empleados SET valor_hora = 4100.00 WHERE legajo = 'EMP004';  -- Técnico
UPDATE empleados SET valor_hora = 3600.00 WHERE legajo = 'EMP005';  -- Administrativa


-- Control final: tienen que salir los 6 empleados, cinco con número y
-- Paz con NULL.

SELECT legajo, apellido, nombre, cargo, valor_hora
  FROM empleados
 ORDER BY legajo;