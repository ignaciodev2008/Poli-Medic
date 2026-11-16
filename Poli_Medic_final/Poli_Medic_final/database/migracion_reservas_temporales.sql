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
