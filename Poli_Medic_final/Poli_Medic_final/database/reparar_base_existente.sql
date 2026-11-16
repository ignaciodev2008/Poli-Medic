-- Ejecutar en phpMyAdmin seleccionando la base centro_medico_salud.
-- Este archivo conserva los registros existentes y completa las tablas necesarias
-- para relaciones de especialidades, agendas y notificaciones.
USE centro_medico_salud;

CREATE TABLE IF NOT EXISTS profesional_especialidades (
  profesional_id INT NOT NULL,
  especialidad_id INT NOT NULL,
  PRIMARY KEY (profesional_id, especialidad_id),
  CONSTRAINT fk_pe_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
  CONSTRAINT fk_pe_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS horarios_profesionales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  profesional_id INT NOT NULL,
  especialidad_id INT NOT NULL,
  dia_semana TINYINT NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  duracion_minutos SMALLINT NOT NULL DEFAULT 30,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_hp_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
  CONSTRAINT fk_hp_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id),
  CONSTRAINT chk_hp_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB;

ALTER TABLE turnos ADD COLUMN IF NOT EXISTS duracion_minutos SMALLINT NOT NULL DEFAULT 30;
INSERT IGNORE INTO profesional_especialidades (profesional_id, especialidad_id)
SELECT id, especialidad_id FROM profesionales;

CREATE TABLE IF NOT EXISTS notificaciones (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  turno_id INT NULL,
  tipo VARCHAR(40) NOT NULL,
  mensaje VARCHAR(255) NOT NULL,
  leida TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notificacion_evento (turno_id, tipo),
  CONSTRAINT fk_notificacion_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Comprobación visual: estas consultas deben devolver tablas/filas sin error.
SELECT DATABASE() AS base_seleccionada;
SELECT COUNT(*) AS pacientes_guardados FROM pacientes;
SELECT COUNT(*) AS profesionales_guardados FROM profesionales;
SELECT COUNT(*) AS especialidades_guardadas FROM especialidades;
SELECT COUNT(*) AS turnos_guardados FROM turnos;
SELECT COUNT(*) AS notificaciones_guardadas FROM notificaciones;
