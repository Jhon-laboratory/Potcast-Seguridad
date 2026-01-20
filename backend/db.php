<?php
// Configuración Azure SQL
define('DB_SERVER', 'Jorgeserver.database.windows.net');
define('DB_DATABASE', 'DPL');
define('DB_USERNAME', 'Jmmc');
define('DB_PASSWORD', 'ChaosSoldier01');
define('SCHEMA', 'externos');

try {
    $conn = new PDO(
        "sqlsrv:server=" . DB_SERVER . ";Database=" . DB_DATABASE,
        DB_USERNAME,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        )
    );
} catch (PDOException $e) {
    die("Error al conectar: " . $e->getMessage());
}
