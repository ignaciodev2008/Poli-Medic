CREATE DATABASE IF NOT EXISTS centro_medico_salud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE centro_medico_salud;
CREATE TABLE IF NOT EXISTS especialidades (
 id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL UNIQUE, descripcion VARCHAR(255) NOT NULL DEFAULT '', activa TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS profesionales (
 id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL, especialidad_id INT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_prof_activo(activo), CONSTRAINT fk_prof_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS profesional_especialidades (
 id INT AUTO_INCREMENT PRIMARY KEY, profesional_id INT NOT NULL, especialidad_id INT NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_prof_especialidad(profesional_id,especialidad_id), CONSTRAINT fk_pe_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id), CONSTRAINT fk_pe_esp FOREIGN KEY (especialidad_id) REFERENCES especialidades(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS horarios_profesionales (
 id INT AUTO_INCREMENT PRIMARY KEY, profesional_id INT NOT NULL, especialidad_id INT NOT NULL, dia_semana TINYINT NOT NULL COMMENT '1=lunes ... 7=domingo', hora_inicio TIME NOT NULL, hora_fin TIME NOT NULL, duracion_minutos SMALLINT NOT NULL DEFAULT 30, activo TINYINT(1) NOT NULL DEFAULT 1, CHECK (dia_semana BETWEEN 1 AND 7), CHECK (hora_fin > hora_inicio), INDEX idx_agenda_consulta(profesional_id,especialidad_id,dia_semana,activo), CONSTRAINT fk_horario_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id), CONSTRAINT fk_horario_esp FOREIGN KEY (especialidad_id) REFERENCES especialidades(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS pacientes (
 id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, apellido VARCHAR(100) NOT NULL, dni VARCHAR(20) NOT NULL UNIQUE, telefono VARCHAR(40) NOT NULL DEFAULT '', email VARCHAR(150) NOT NULL DEFAULT '', obra_social VARCHAR(30) NULL, obra_social_otra VARCHAR(150) NULL, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS turnos (
 id INT AUTO_INCREMENT PRIMARY KEY, paciente_id INT NOT NULL, especialidad_id INT NOT NULL, profesional_id INT NULL, fecha DATE NOT NULL, hora TIME NOT NULL, duracion_minutos SMALLINT NOT NULL DEFAULT 30, motivo VARCHAR(255) NOT NULL DEFAULT '', estado ENUM('Confirmado','Cancelado','Atendido') NOT NULL DEFAULT 'Confirmado', creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_turno_agenda(profesional_id,fecha,estado), INDEX idx_turno_paciente(paciente_id,fecha), CONSTRAINT fk_turno_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id), CONSTRAINT fk_turno_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id), CONSTRAINT fk_turno_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS actividad (
 id INT AUTO_INCREMENT PRIMARY KEY, descripcion VARCHAR(255) NOT NULL, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS administradores (
 id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(50) NOT NULL UNIQUE, clave_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notificaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, turno_id INT NULL, tipo VARCHAR(40) NOT NULL, mensaje VARCHAR(255) NOT NULL, leida TINYINT(1) NOT NULL DEFAULT 0, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_notificacion_evento(turno_id,tipo), CONSTRAINT fk_notificacion_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL
) ENGINE=InnoDB;
INSERT INTO especialidades(nombre,descripcion) VALUES
('Cardiología','Diagnóstico y tratamiento integral para proteger la salud de tu corazón'),
('Clínica Médica','Prevención, orientación y cuidado general'),
('Ginecología','Atención integral de la salud ginecológica'),
('Kinesiología','Movimiento y recuperación'),
('Laboratorio','Análisis clínicos'),
('Nutricionista','Asesoría nutricional para optimizar tu salud integral'),
('Psicólogo','Acompañamiento profesional y herramientas para encontrar tu equilibrio'),
('Psicopedagogía','Apoyo para el aprendizaje y el desarrollo'),
('Traumatología','Especialista en traumatología y ortopedia dedicado a recuperar tu movilidad y calidad de vida')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
INSERT IGNORE INTO administradores(usuario,clave_hash) VALUES ('admin','$2y$12$vNwxItAPRvtpE7TtU8LB6.6OSULEXX4kOP8psEZatC7QaSrbsoJUy');


-- Precarga de profesionales y agendas para instalaciones nuevas.
-- PRE-CARGA IDEMPOTENTE DE ESPECIALIDADES, PROFESIONALES Y AGENDAS
-- Base: centro_medico_salud. Hacé una exportación de respaldo antes de ejecutar.
-- No elimina pacientes ni turnos. No sobrescribe agendas ya existentes.
USE centro_medico_salud;

INSERT INTO especialidades(nombre,descripcion,activa) VALUES
('Cardiología','Diagnóstico y tratamiento integral para proteger la salud de tu corazón',1),
('Clínica Médica','Prevención, orientación y cuidado general',1),
('Ginecología','Atención integral de la salud ginecológica',1),
('Kinesiología','Movimiento y recuperación',1),
('Laboratorio','Análisis clínicos',1),
('Nutricionista','Asesoría nutricional para optimizar tu salud integral',1),
('Psicólogo','Acompañamiento profesional y herramientas para encontrar tu equilibrio',1),
('Psicopedagogía','Apoyo para el aprendizaje y el desarrollo',1),
('Traumatología','Especialista en traumatología y ortopedia dedicado a recuperar tu movilidad y calidad de vida',1)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

-- Alias conocidos en versiones anteriores: no crear una segunda ficha por una diferencia de escritura.
-- Si ya existe el nombre canónico, la ficha alternativa se conserva para no alterar referencias históricas.
INSERT INTO profesionales(nombre,especialidad_id,activo)
SELECT v.nombre,e.id,1
FROM (
 SELECT 'Dr. Carlos Baez' nombre,'Clínica Médica' esp,'Dr. Carlos Baez' alias1,'' alias2
 UNION ALL SELECT 'Dr. Daniel Albornoz','Nutricionista','Dr. Daniel Albornoz','Dr. Daniel Albornos'
 UNION ALL SELECT 'Dr. Dinkelberg','Traumatología','Dr. Dinkelberg',''
 UNION ALL SELECT 'Dr. Emilia Castro','Kinesiología','Dr. Emilia Castro',''
 UNION ALL SELECT 'Dr. Hugo Prados','Psicólogo','Dr. Hugo Prados',''
 UNION ALL SELECT 'Dr. Laura Benítez','Cardiología','Dr. Laura Benítez','Dr. Laura Benitez'
 UNION ALL SELECT 'Dr. Martínez Prados','Laboratorio','Dr. Martínez Prados','Dr. Martinez Prados'
 UNION ALL SELECT 'Dra. Gloria Ojeda','Clínica Médica','Dra. Gloria Ojeda',''
 UNION ALL SELECT 'Dra. Lucía Montenegro','Psicopedagogía','Dra. Lucía Montenegro','Dra. Lucia Montenegro'
) v
JOIN especialidades e ON e.nombre=v.esp
WHERE NOT EXISTS (
 SELECT 1 FROM profesionales p
 WHERE p.nombre=v.nombre OR (v.alias2<>'' AND p.nombre=v.alias2)
);

-- Vincular especialidades sin duplicar relaciones existentes.
INSERT IGNORE INTO profesional_especialidades(profesional_id,especialidad_id,activo)
SELECT p.id,e.id,1 FROM profesionales p JOIN especialidades e ON (
 (p.nombre='Dr. Carlos Baez' AND e.nombre='Clínica Médica') OR
 ((p.nombre='Dr. Daniel Albornoz' OR p.nombre='Dr. Daniel Albornos') AND e.nombre='Nutricionista') OR
 (p.nombre='Dr. Dinkelberg' AND e.nombre='Traumatología') OR
 (p.nombre='Dr. Emilia Castro' AND e.nombre='Kinesiología') OR
 (p.nombre='Dr. Hugo Prados' AND e.nombre='Psicólogo') OR
 ((p.nombre='Dr. Laura Benítez' OR p.nombre='Dr. Laura Benitez') AND e.nombre='Cardiología') OR
 ((p.nombre='Dr. Martínez Prados' OR p.nombre='Dr. Martinez Prados') AND e.nombre='Laboratorio') OR
 (p.nombre='Dra. Gloria Ojeda' AND e.nombre IN ('Clínica Médica','Ginecología')) OR
 ((p.nombre='Dra. Lucía Montenegro' OR p.nombre='Dra. Lucia Montenegro') AND e.nombre='Psicopedagogía')
);

-- Compatibilidad con la columna heredada profesional.especialidad_id.
UPDATE profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id
JOIN especialidades e ON e.id=pe.especialidad_id
SET p.especialidad_id=pe.especialidad_id
WHERE p.especialidad_id IS NULL;

-- Quitar la restricción antigua que impedía dos franjas el mismo día, solo si existe.
SET @has_old_unique := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='horarios_profesionales' AND index_name='uq_agenda_prof_esp_dia');
SET @drop_old_unique := IF(@has_old_unique>0,'ALTER TABLE horarios_profesionales DROP INDEX uq_agenda_prof_esp_dia','SELECT 1');
PREPARE stmt FROM @drop_old_unique; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_old_unique2 := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='horarios_profesionales' AND index_name='uq_profesional_dia');
SET @drop_old_unique2 := IF(@has_old_unique2>0,'ALTER TABLE horarios_profesionales DROP INDEX uq_profesional_dia','SELECT 1');
PREPARE stmt FROM @drop_old_unique2; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_schedule_idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='horarios_profesionales' AND index_name='idx_agenda_prof_esp_dia');
SET @add_schedule_idx := IF(@has_schedule_idx=0,'ALTER TABLE horarios_profesionales ADD INDEX idx_agenda_prof_esp_dia (profesional_id,especialidad_id,dia_semana)','SELECT 1');
PREPARE stmt FROM @add_schedule_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Identificar solo profesionales sin ninguna agenda previa; sus horarios existentes nunca se reemplazan.
DROP TEMPORARY TABLE IF EXISTS seed_profesionales_sin_agenda;
CREATE TEMPORARY TABLE seed_profesionales_sin_agenda (profesional_id INT PRIMARY KEY) ENGINE=MEMORY;
INSERT INTO seed_profesionales_sin_agenda(profesional_id)
SELECT p.id FROM profesionales p
WHERE p.activo=1 AND (
 p.nombre IN ('Dr. Carlos Baez','Dr. Daniel Albornoz','Dr. Daniel Albornos','Dr. Dinkelberg','Dr. Emilia Castro','Dr. Hugo Prados','Dr. Laura Benítez','Dr. Laura Benitez','Dr. Martínez Prados','Dr. Martinez Prados','Dra. Gloria Ojeda','Dra. Lucía Montenegro','Dra. Lucia Montenegro')
) AND NOT EXISTS (SELECT 1 FROM horarios_profesionales h WHERE h.profesional_id=p.id);

-- Agendas transcritas de la captura original. Días: 1 lunes ... 7 domingo.
-- Horarios por profesional/especialidad; Gloria atiende Clínica Médica y Ginecología en las mismas franjas.
INSERT INTO horarios_profesionales(profesional_id,especialidad_id,dia_semana,hora_inicio,hora_fin,duracion_minutos,activo)
SELECT p.id,e.id,v.dia,v.ini,v.fin,30,1
FROM (
 SELECT 'Dr. Carlos Baez' nombre,'Clínica Médica' esp,1 dia,'17:00' ini,'20:00' fin UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',1,'09:00','12:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',2,'17:00','20:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',2,'09:00','12:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',3,'09:00','12:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',4,'17:00','20:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',5,'15:00','18:00' UNION ALL
 SELECT 'Dr. Carlos Baez','Clínica Médica',5,'09:00','12:00' UNION ALL
 SELECT 'Dr. Daniel Albornoz','Nutricionista',2,'12:00','18:00' UNION ALL
 SELECT 'Dr. Dinkelberg','Traumatología',3,'13:00','19:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',1,'09:00','12:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',1,'15:00','18:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',2,'09:00','12:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',2,'15:00','18:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',3,'09:00','12:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',3,'15:00','18:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',4,'09:00','12:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',4,'15:00','18:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',5,'09:00','12:00' UNION ALL
 SELECT 'Dr. Emilia Castro','Kinesiología',5,'15:00','18:00' UNION ALL
 SELECT 'Dr. Hugo Prados','Psicólogo',6,'09:00','12:00' UNION ALL
 SELECT 'Dr. Hugo Prados','Psicólogo',6,'15:00','18:00' UNION ALL
 SELECT 'Dr. Laura Benítez','Cardiología',1,'12:00','18:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',1,'09:00','12:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',2,'09:00','12:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',3,'09:00','12:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',5,'09:00','12:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',6,'09:00','12:00' UNION ALL
 SELECT 'Dr. Martínez Prados','Laboratorio',6,'15:00','18:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',1,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',1,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',2,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',2,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',3,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',4,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',5,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Clínica Médica',5,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',1,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',1,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',2,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',2,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',3,'09:00','12:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',4,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',5,'17:00','20:00' UNION ALL
 SELECT 'Dra. Gloria Ojeda','Ginecología',5,'09:00','12:00' UNION ALL
 SELECT 'Dra. Lucía Montenegro','Psicopedagogía',2,'15:00','18:00' UNION ALL
 SELECT 'Dra. Lucía Montenegro','Psicopedagogía',2,'09:00','12:00' UNION ALL
 SELECT 'Dra. Lucía Montenegro','Psicopedagogía',4,'15:00','18:00' UNION ALL
 SELECT 'Dra. Lucía Montenegro','Psicopedagogía',4,'09:00','12:00'
) v
JOIN profesionales p ON (p.nombre=v.nombre OR (v.nombre='Dr. Daniel Albornoz' AND p.nombre='Dr. Daniel Albornos') OR (v.nombre='Dr. Laura Benítez' AND p.nombre='Dr. Laura Benitez') OR (v.nombre='Dr. Martínez Prados' AND p.nombre='Dr. Martinez Prados') OR (v.nombre='Dra. Lucía Montenegro' AND p.nombre='Dra. Lucia Montenegro'))
JOIN seed_profesionales_sin_agenda sa ON sa.profesional_id=p.id
JOIN especialidades e ON e.nombre=v.esp;
DROP TEMPORARY TABLE IF EXISTS seed_profesionales_sin_agenda;



-- Poli Medic: bloqueos temporales de horarios mientras el paciente completa sus datos.
-- Ejecutar una sola vez en la base centro_medico_salud.
CREATE TABLE IF NOT EXISTS reservas_horarios_temporales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profesional_id INT NOT NULL,
    especialidad_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    duracion_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    propietario_sesion VARCHAR(128) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vence_en DATETIME NOT NULL,
    UNIQUE KEY uq_reserva_temporal_sesion (propietario_sesion),
    KEY idx_reserva_temporal_horario (profesional_id, fecha, hora, vence_en),
    KEY idx_reserva_temporal_vencimiento (vence_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
