-- Poli Medic - requisitos de Base de Datos II
-- Ejecutar con la base centro_medico_salud seleccionada.
-- Crear una copia de seguridad antes de ejecutar en una base con datos reales.

CREATE TABLE IF NOT EXISTS auditoria_estados_turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    turno_id INT NOT NULL,
    estado_anterior VARCHAR(20) NOT NULL,
    estado_nuevo VARCHAR(20) NOT NULL,
    cambiado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_mysql VARCHAR(100) NOT NULL,
    INDEX idx_auditoria_turno (turno_id),
    INDEX idx_auditoria_fecha (cambiado_en)
) ENGINE=InnoDB;

-- Reemplazar el trigger de forma segura si se vuelve a ejecutar la migración.
DROP TRIGGER IF EXISTS trg_auditar_estado_turno;

DELIMITER $$
CREATE TRIGGER trg_auditar_estado_turno
AFTER UPDATE ON turnos
FOR EACH ROW
BEGIN
    IF NOT (OLD.estado <=> NEW.estado) THEN
        INSERT INTO auditoria_estados_turnos
            (turno_id, estado_anterior, estado_nuevo, usuario_mysql)
        VALUES
            (OLD.id, OLD.estado, NEW.estado, CURRENT_USER());
    END IF;
END$$
DELIMITER ;
