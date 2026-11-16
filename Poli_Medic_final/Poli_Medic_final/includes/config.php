<?php
// Conexión única a MySQL para el portal y el panel administrativo.
$host = 'localhost';
$database = 'centro_medico_salud';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $error) {
    http_response_code(500);
    $isLocal = in_array(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), ['localhost', '127.0.0.1', 'localhost:80', '127.0.0.1:80'], true);
    if ($isLocal) {
        exit('No se pudo conectar con MySQL para la base de datos ' . htmlspecialchars($database, ENT_QUOTES, 'UTF-8') . '. Detalle técnico: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') . '. Revisá que MySQL esté iniciado y que usuario/contraseña coincidan en includes/config.php.');
    }
    exit('No se pudo conectar con MySQL. Revisá la configuración de la base de datos.');
}
