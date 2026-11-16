USE centro_medico_salud;
CREATE TABLE IF NOT EXISTS profesional_especialidades (
 profesional_id INT NOT NULL, especialidad_id INT NOT NULL, PRIMARY KEY (profesional_id,especialidad_id),
 CONSTRAINT fk_pe_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
 CONSTRAINT fk_pe_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id)
);
CREATE TABLE IF NOT EXISTS horarios_profesionales (
 id INT AUTO_INCREMENT PRIMARY KEY, profesional_id INT NOT NULL, especialidad_id INT NOT NULL,
 dia_semana TINYINT NOT NULL, hora_inicio TIME NOT NULL, hora_fin TIME NOT NULL, duracion_minutos SMALLINT NOT NULL DEFAULT 30, activo TINYINT(1) NOT NULL DEFAULT 1,
 CONSTRAINT fk_hp_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
 CONSTRAINT fk_hp_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id), CONSTRAINT chk_hp_horas CHECK (hora_fin > hora_inicio)
);
ALTER TABLE turnos ADD COLUMN IF NOT EXISTS duracion_minutos SMALLINT NOT NULL DEFAULT 30;
INSERT IGNORE INTO profesional_especialidades (profesional_id,especialidad_id) SELECT id,especialidad_id FROM profesionales;
