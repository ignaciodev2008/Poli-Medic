-- Poli Medic - ejemplo DCL para Base de Datos II
-- Ejecutar desde phpMyAdmin con una cuenta administradora de MySQL.
-- Antes de ejecutar, reemplazá las contraseñas de ejemplo por claves seguras.
-- Este archivo no modifica la configuración actual de includes/config.php.
-- Los roles requieren una versión de MySQL/MariaDB que admita roles.

USE centro_medico_salud;

CREATE ROLE IF NOT EXISTS 'rol_polimedic_consulta';
CREATE ROLE IF NOT EXISTS 'rol_polimedic_app';

-- Rol de consulta: permite leer la información del sistema, sin modificarla.
GRANT SELECT ON centro_medico_salud.* TO 'rol_polimedic_consulta';

-- Rol de aplicación: permisos necesarios para las operaciones habituales del sistema.
-- No incluye DROP, ALTER, CREATE ni permisos de administración del servidor.
GRANT SELECT, INSERT, UPDATE, DELETE ON centro_medico_salud.* TO 'rol_polimedic_app';

-- Usuario de ejemplo para la aplicación. Cambiar la contraseña antes de ejecutarlo.
CREATE USER IF NOT EXISTS 'polimedic_app'@'localhost'
    IDENTIFIED BY 'CAMBIAR_ESTA_CLAVE_ANTES_DE_USAR';
GRANT 'rol_polimedic_app' TO 'polimedic_app'@'localhost';
SET DEFAULT ROLE 'rol_polimedic_app' FOR 'polimedic_app'@'localhost';

-- Usuario de ejemplo para consultas de solo lectura y demostraciones.
CREATE USER IF NOT EXISTS 'polimedic_consulta'@'localhost'
    IDENTIFIED BY 'CAMBIAR_ESTA_OTRA_CLAVE_ANTES_DE_USAR';
GRANT 'rol_polimedic_consulta' TO 'polimedic_consulta'@'localhost';
SET DEFAULT ROLE 'rol_polimedic_consulta' FOR 'polimedic_consulta'@'localhost';

-- Verificación de los permisos asignados:
SHOW GRANTS FOR 'rol_polimedic_consulta';
SHOW GRANTS FOR 'rol_polimedic_app';
SHOW GRANTS FOR 'polimedic_app'@'localhost';
SHOW GRANTS FOR 'polimedic_consulta'@'localhost';
