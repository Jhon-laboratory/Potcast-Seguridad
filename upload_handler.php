<?php
// upload_handler.php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar sesión
if (!isset($_SESSION['usuario'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Incluir autoload de composer
require_once __DIR__ . '/vendor/autoload.php';
use phpseclib3\Net\SFTP;

// Configuración SFTP
$sftp_host = '192.168.1.5';
$sftp_port = 22;
$sftp_user = 'mediauser';
$sftp_pass = 'Mortadela1';
$remote_dir = '/videos/';

// Configurar headers JSON
header('Content-Type: application/json');

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Verificar si se subió un archivo
if (!isset($_FILES['mediaFile']) || $_FILES['mediaFile']['error'] !== UPLOAD_ERR_OK) {
    $error_msg = 'Error en la subida del archivo. ';
    
    if (isset($_FILES['mediaFile']['error'])) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por PHP',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida'
        ];
        
        $error_code = $_FILES['mediaFile']['error'];
        $error_msg .= isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : "Código error: $error_code";
    }
    
    echo json_encode(['success' => false, 'error' => $error_msg]);
    exit;
}

$file = $_FILES['mediaFile'];

// Validar tipo de archivo
$allowed_video = ['video/mp4', 'video/avi', 'video/quicktime', 'video/x-msvideo', 
                  'video/x-matroska', 'video/x-ms-wmv', 'video/webm'];
$allowed_audio = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/flac'];
$allowed_types = array_merge($allowed_video, $allowed_audio);

if (!in_array($file['type'], $allowed_types)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Tipo de archivo no permitido. Tipos aceptados: ' . implode(', ', array_unique($allowed_types))
    ]);
    exit;
}

// Validar tamaño (500MB)
if ($file['size'] > 500 * 1024 * 1024) {
    echo json_encode([
        'success' => false, 
        'error' => 'Archivo muy grande. Tamaño máximo: 500MB'
    ]);
    exit;
}

// Conectar al SFTP
try {
    $sftp = new SFTP($sftp_host, $sftp_port);
    
    if (!$sftp->login($sftp_user, $sftp_pass)) {
        echo json_encode(['success' => false, 'error' => 'Error de autenticación SFTP']);
        exit;
    }
    
    // Verificar si el directorio existe, si no crearlo
    if (!$sftp->file_exists($remote_dir)) {
        if (!$sftp->mkdir($remote_dir, 0777, true)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo crear el directorio remoto']);
            exit;
        }
    }
    
    // Generar nombre único para el archivo
    $original_name = basename($file['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
    // Limpiar nombre de archivo
    $clean_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
    $timestamp = date('Ymd_His');
    $random_string = substr(md5(uniqid()), 0, 6);
    $unique_name = "{$timestamp}_{$random_string}_{$clean_name}.{$extension}";
    
    $remote_path = $remote_dir . $unique_name;
    
    // Subir archivo al SFTP
    $upload_result = $sftp->put($remote_path, $file['tmp_name'], SFTP::SOURCE_LOCAL_FILE);
    
    if ($upload_result) {
        // Verificar que el archivo se subió correctamente
        $remote_stat = $sftp->stat($remote_path);
        
        if ($remote_stat && $remote_stat['size'] > 0) {
            echo json_encode([
                'success' => true,
                'filename' => $unique_name,
                'original_name' => $original_name,
                'size' => $file['size'],
                'message' => 'Archivo subido exitosamente'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'Error al verificar el archivo subido'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'Error al subir el archivo al servidor SFTP'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error de conexión SFTP: ' . $e->getMessage()
    ]);
}
?>