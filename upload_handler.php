<?php
// upload_handler.php
session_start();

// DESACTIVAR ERRORES EN PRODUCCIÓN
ini_set('display_errors', 0);
error_reporting(0);

// Verificar sesión
if (!isset($_SESSION['usuario'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// ===== CONFIGURACIÓN SQL SERVER =====
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';
// ===================================

// ===== CONFIGURACIÓN SFTP =====
$sftp_host = '192.168.1.5';
$sftp_port = 22;
$sftp_user = 'mediauser';
$sftp_pass = 'Mortadela1';
$remote_dir = '/videos/';
// ==============================

// Configurar zona horaria
date_default_timezone_set('America/Lima');

// Incluir phpseclib con manejo de errores
try {
    $autoload_path = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    } else {
        require_once __DIR__ . '/phpseclib/vendor/autoload.php';
    }
    
    if (!class_exists('phpseclib3\Net\SFTP')) {
        throw new Exception('Clase SFTP no encontrada.');
    }
} catch (Exception $e) {
    error_log('Error cargando phpseclib: ' . $e->getMessage());
}

// Conectar a SQL Server
function connectSQLServer() {
    global $host, $dbname, $username, $password;
    
    try {
        $connectionInfo = array(
            "Database" => $dbname,
            "UID" => $username,
            "PWD" => $password,
            "CharacterSet" => "UTF-8",
            "ReturnDatesAsStrings" => true,
            "MultipleActiveResultSets" => false
        );
        
        $conn = sqlsrv_connect($host, $connectionInfo);
        
        if ($conn === false) {
            $errors = sqlsrv_errors();
            $error_msg = isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
            return ['success' => false, 'error' => 'Error SQL Server: ' . $error_msg];
        }
        
        return ['success' => true, 'conn' => $conn];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Excepción SQL Server: ' . $e->getMessage()];
    }
}

// Registrar archivo SFTP en la tabla Vistas
function registerSftpFileInDatabase($filename, $title, $description, $category, $type) {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return ['success' => false, 'error' => $connection['error']];
    }
    
    $conn = $connection['conn'];
    
    try {
        // Insertar directamente en la tabla Vistas
        $sql = "INSERT INTO [DPL].[externos].[Vistas] (name, descripcion, embeded, Vistas, Likes, Ultimavista) 
                VALUES (?, ?, NULL, 0, 0, GETDATE())";
        
        // Usar sftp:// como referencia para archivos SFTP
        $params = array($title, $description);
        $stmt = sqlsrv_prepare($conn, $sql, $params);
        
        if ($stmt === false || !sqlsrv_execute($stmt)) {
            $errors = sqlsrv_errors();
            $error_msg = isset($errors[0]['message']) ? $errors[0]['message'] : 'Error al insertar';
            return ['success' => false, 'error' => 'Error BD: ' . $error_msg];
        }
        
        // Obtener el ID insertado
        $sql = "SELECT SCOPE_IDENTITY() AS new_id";
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            return ['success' => false, 'error' => 'Error al obtener ID'];
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $new_id = $row ? $row['new_id'] : 0;
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return ['success' => true, 'id' => $new_id];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Excepción BD: ' . $e->getMessage()];
    }
}

// FUNCIÓN PRINCIPAL DE SUBIDA
function handleFileUpload() {
    global $sftp_host, $sftp_port, $sftp_user, $sftp_pass, $remote_dir;
    
    // Verificar que hay archivo
    if (!isset($_FILES['mediaFile']) || $_FILES['mediaFile']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No se recibió archivo válido'];
    }
    
    $file = $_FILES['mediaFile'];
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $category = $_POST['category'] ?? 'general';
    $type = $_POST['type'] ?? 'video';
    
    // Validaciones básicas
    if (empty($title)) {
        return ['success' => false, 'error' => 'El título es requerido'];
    }
    
    // Validar tipo de archivo
    $allowed_video = ['video/mp4', 'video/avi', 'video/quicktime', 'video/x-msvideo', 'video/mpeg'];
    $allowed_audio = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/x-wav'];
    $allowed_types = array_merge($allowed_video, $allowed_audio);
    
    $file_type = mime_content_type($file['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Tipo de archivo no permitido: ' . $file_type];
    }
    
    if ($file['size'] > 500 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Archivo muy grande. Máximo 500MB'];
    }
    
    try {
        // Conectar a SFTP
        $sftp = new phpseclib3\Net\SFTP($sftp_host, $sftp_port);
        
        if (!$sftp->login($sftp_user, $sftp_pass)) {
            return ['success' => false, 'error' => 'Error de autenticación SFTP'];
        }
        
        // Verificar/Crear directorio
        if (!$sftp->file_exists($remote_dir)) {
            if (!$sftp->mkdir($remote_dir, 0777, true)) {
                return ['success' => false, 'error' => 'No se pudo crear directorio remoto'];
            }
        }
        
        // Generar nombre único seguro
        $original_name = basename($file['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        
        // Limpiar nombre
        $clean_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        if (empty($clean_name)) {
            $clean_name = 'archivo';
        }
        
        $timestamp = date('Ymd_His');
        $random_string = substr(md5(uniqid()), 0, 8);
        $unique_name = "{$timestamp}_{$random_string}_{$clean_name}.{$extension}";
        $remote_path = $remote_dir . $unique_name;
        
        // Subir archivo
        $upload_result = $sftp->put($remote_path, $file['tmp_name'], phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
        
        if (!$upload_result) {
            return ['success' => false, 'error' => 'Error al subir archivo al SFTP'];
        }
        
        // Registrar en base de datos (tabla Vistas)
        $db_result = registerSftpFileInDatabase($unique_name, $title, $description, $category, $type);
        
        if (!$db_result['success']) {
            // Intentar eliminar el archivo si falla la BD
            $sftp->delete($remote_path);
            return $db_result;
        }
        
        return [
            'success' => true,
            'filename' => $unique_name,
            'original_name' => $original_name,
            'size' => $file['size'],
            'type' => $file_type,
            'db_id' => $db_result['id'],
            'message' => 'Archivo subido y registrado exitosamente'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error SFTP: ' . $e->getMessage()];
    }
}

// MANEJAR LA PETICIÓN
header('Content-Type: application/json; charset=utf-8');

// Capturar cualquier salida de error
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    $result = handleFileUpload();
    
    // Limpiar buffer de salida
    ob_end_clean();
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Limpiar buffer
    ob_end_clean();
    
    echo json_encode([
        'success' => false,
        'error' => 'Error del sistema: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Asegurar que no haya salida adicional
exit;
?>