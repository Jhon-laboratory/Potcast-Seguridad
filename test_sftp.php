<?php
require __DIR__ . '/phpseclib/vendor/autoload.php';

use phpseclib3\Net\SFTP;

// ===== CONFIGURACIÓN REAL =====
$host = '192.168.1.5';
$port = 22;
$user = 'mediauser';
$pass = 'Mortadela1';
$remoteDir = '/videos/';   // 🔥 ESTA ES LA RUTA CORRECTA
// ==============================

// Conectar
$sftp = new SFTP($host, $port);
if (!$sftp->login($user, $pass)) {
    die('❌ Error de autenticación SFTP');
}

// Subida
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        die('❌ Archivo no válido');
    }

    $tmp = $_FILES['video']['tmp_name'];
    $name = basename($_FILES['video']['name']);
    $remote = $remoteDir . $name;

    // Subir archivo
    if ($sftp->put($remote, file_get_contents($tmp))) {
        echo "✅ Video subido correctamente a /videos/$name";
    } else {
        echo "❌ Error al subir el video por SFTP";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Subir video</title>
</head>
<body>

<h2>Subir video por SFTP</h2>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="video" accept="video/*" required>
    <br><br>
    <button type="submit">Subir</button>
</form>

</body>
</html>
