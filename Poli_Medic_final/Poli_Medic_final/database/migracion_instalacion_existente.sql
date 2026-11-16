-- Ejecutar sobre centro_medico_salud después de exportar una copia de seguridad.
USE centro_medico_salud;
CREATE TABLE IF NOT EXISTS profesional_especialidades (
 id INT AUTO_INCREMENT PRIMARY KEY, profesional_id INT NOT NULL, especialidad_id INT NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_prof_especialidad(profesional_id,especialidad_id), CONSTRAINT fk_pe_prof_mig FOREIGN KEY (profesional_id) REFERENCES profesionales(id), CONSTRAINT fk_pe_esp_mig FOREIGN KEY (especialidad_id) REFERENCES especialidades(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO profesional_especialidades(profesional_id,especialidad_id) SELECT id,especialidad_id FROM profesionales WHERE especialidad_id IS NOT NULL;
ALTER TABLE horarios_profesionales ADD COLUMN IF NOT EXISTS especialidad_id INT NULL;
ALTER TABLE horarios_profesionales ADD COLUMN IF NOT EXISTS duracion_minutos SMALLINT NOT NULL DEFAULT 30;
-- La versión anterior permitía un solo registro por profesional y día. Se reemplaza por la clave profesional/especialidad/día.
ALTER TABLE horarios_profesionales DROP INDEX uq_profesional_dia;
-- No usar una clave UNIQUE por día: se permiten dos franjas diarias por profesional/especialidad.
ALTER TABLE horarios_profesionales ADD INDEX idx_agenda_prof_esp_dia (profesional_id,especialidad_id,dia_semana);
ALTER TABLE turnos ADD COLUMN IF NOT EXISTS duracion_minutos SMALLINT NOT NULL DEFAULT 30;
UPDATE horarios_profesionales h JOIN profesionales p ON p.id=h.profesional_id SET h.especialidad_id=p.especialidad_id WHERE h.especialidad_id IS NULL;
-- Revisar que especialidad_id no quede NULL antes de convertirlo a NOT NULL.
CREATE TABLE IF NOT EXISTS notificaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, turno_id INT NULL, tipo VARCHAR(40) NOT NULL, mensaje VARCHAR(255) NOT NULL, leida TINYINT(1) NOT NULL DEFAULT 0, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_notificacion_evento(turno_id,tipo), CONSTRAINT fk_notificacion_turno_mig FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL
) ENGINE=InnoDB;
