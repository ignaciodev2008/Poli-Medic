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

