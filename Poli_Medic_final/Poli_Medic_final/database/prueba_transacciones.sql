-- Poli Medic - demostración de COMMIT y ROLLBACK (DML sobre actividad).
-- Ejecutar en phpMyAdmin con centro_medico_salud seleccionada.
-- La primera transacción se deshace; la segunda queda guardada.
-- Son filas de demostración en actividad; se pueden identificar por el texto.

-- PRUEBA 1: ROLLBACK (la fila no debe quedar guardada al finalizar).
START TRANSACTION;
INSERT INTO actividad (descripcion)
VALUES ('PRUEBA ACADEMICA: esta operación debe revertirse con ROLLBACK');
SELECT * FROM actividad
WHERE descripcion = 'PRUEBA ACADEMICA: esta operación debe revertirse con ROLLBACK';
ROLLBACK;

-- Comprobar: esta consulta debe devolver cero filas.
SELECT * FROM actividad
WHERE descripcion = 'PRUEBA ACADEMICA: esta operación debe revertirse con ROLLBACK';

-- PRUEBA 2: COMMIT (la fila queda guardada).
START TRANSACTION;
INSERT INTO actividad (descripcion)
VALUES ('PRUEBA ACADEMICA: esta operación queda confirmada con COMMIT');
SELECT * FROM actividad
WHERE descripcion = 'PRUEBA ACADEMICA: esta operación queda confirmada con COMMIT';
COMMIT;

-- Comprobar: esta consulta debe devolver una fila.
SELECT * FROM actividad
WHERE descripcion = 'PRUEBA ACADEMICA: esta operación queda confirmada con COMMIT';

-- Limpieza opcional de la fila de prueba confirmada.
-- Descomentá las dos líneas siguientes solo después de observar el resultado:
-- DELETE FROM actividad WHERE descripcion = 'PRUEBA ACADEMICA: esta operación queda confirmada con COMMIT';
-- COMMIT;
