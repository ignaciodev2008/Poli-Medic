-- Centro Médico Salud: soporte para Mi Turno y unicidad del DNI.
-- Hacer una copia de seguridad antes de ejecutar sobre una base existente.
USE centro_medico_salud;

-- La tabla de pacientes existente se reutiliza. No se crea otra tabla.
-- Si la instalación antigua no tiene un índice UNIQUE para DNI, se agrega.
-- Si existen DNI duplicados, MySQL rechazará el ALTER sin borrar registros:
-- primero hay que revisar y reconciliar esos pacientes manualmente.
SET @has_unique_dni := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='pacientes'
    AND column_name='dni' AND non_unique=0
);
SET @sql_unique_dni := IF(@has_unique_dni=0,
  'ALTER TABLE pacientes ADD UNIQUE KEY uq_pacientes_dni (dni)',
  'SELECT 1'
);
PREPARE stmt_dni FROM @sql_unique_dni;
EXECUTE stmt_dni;
DEALLOCATE PREPARE stmt_dni;
