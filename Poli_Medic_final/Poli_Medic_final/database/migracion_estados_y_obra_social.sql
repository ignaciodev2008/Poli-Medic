-- Poli Medic: retirar Pendiente del flujo nuevo y agregar obra social sin borrar datos.
-- Ejecutar una sola vez sobre la base centro_medico_salud después de hacer un respaldo.
USE centro_medico_salud;

-- VARCHAR conserva literalmente los estados históricos existentes (incluido Pendiente),
-- pero el sistema nuevo solo permite crear/cambiar a Confirmado, Atendido o Cancelado.
ALTER TABLE turnos MODIFY estado VARCHAR(20) NOT NULL DEFAULT 'Confirmado';

-- Agregar columnas únicamente si todavía no existen. No se toca la columna motivo de turnos:
-- los motivos históricos permanecen guardados, aunque ya no se soliciten en el formulario del paciente.
SET @has_obra_social := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pacientes' AND column_name='obra_social');
SET @sql_obra_social := IF(@has_obra_social=0,'ALTER TABLE pacientes ADD COLUMN obra_social VARCHAR(30) NULL','SELECT 1');
PREPARE stmt FROM @sql_obra_social; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_obra_social_otra := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pacientes' AND column_name='obra_social_otra');
SET @sql_obra_social_otra := IF(@has_obra_social_otra=0,'ALTER TABLE pacientes ADD COLUMN obra_social_otra VARCHAR(150) NULL','SELECT 1');
PREPARE stmt FROM @sql_obra_social_otra; EXECUTE stmt; DEALLOCATE PREPARE stmt;
