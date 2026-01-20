<?php
// stream.php - Servidor de streaming optimizado
session_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/vendor/autoload.php';
use phpseclib3\Net\SFTP;

// Configuración SFTP
$sftp_host = '192.168.1.5';
$sftp_port = 22;
$sftp_user = 'mediauser';
$sftp_pass = 'Mortadela1';
$remote_dir = '/videos/';

// Obtener nombre de archivo
$filename = isset($_GET['file']) ? basename($_GET['file']) : '';
if (empty($filename)) {
    http_response_code(400);
    echo 'Nombre de archivo no especificado';
    exit;
}

// Validar seguridad del nombre de archivo
if (preg_match('/\.\./', $filename) || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(403);
    echo 'Nombre de archivo no válido';
    exit;
}

// Validar extensión
$allowed_extensions = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'webm', 'mp3', 'wav', 'ogg', 'm4a', 'flac'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!in_array($extension, $allowed_extensions)) {
    http_response_code(403);
    echo 'Tipo de archivo no permitido';
    exit;
}

try {
    // Conectar al SFTP
    $sftp = new SFTP($sftp_host, $sftp_port);
    if (!$sftp->login($sftp_user, $sftp_pass)) {
        http_response_code(500);
        echo 'Error de conexión';
        exit;
    }
    
    $remote_path = $remote_dir . $filename;
    
    // Verificar si el archivo existe
    if (!$sftp->file_exists($remote_path)) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    
    // Obtener información del archivo
    $stat = $sftp->stat($remote_path);
    if (!$stat) {
        http_response_code(500);
        echo 'Error al obtener información';
        exit;
    }
    
    $file_size = $stat['size'];
    $last_modified = $stat['mtime'];
    
    // Determinar tipo MIME
    $mime_types = [
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'wmv' => 'video/x-ms-wmv',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'flac' => 'audio/flac'
    ];
    
    $content_type = $mime_types[$extension] ?? 'application/octet-stream';
    
    // Configurar headers
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . $file_size);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    
    // Manejar range requests para streaming
    $range = '';
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
    }
    
    if ($range == '') {
        // Enviar archivo completo
        header('HTTP/1.1 200 OK');
        
        // Leer en chunks para no sobrecargar memoria
        $chunk_size = 8192; // 8KB chunks
        $offset = 0;
        
        while ($offset < $file_size) {
            $chunk = $sftp->get($remote_path, false, $offset, $chunk_size);
            if ($chunk === false) break;
            
            echo $chunk;
            flush();
            $offset += strlen($chunk);
        }
    } else {
        // Manejar partial content
        list($size_unit, $range_orig) = explode('=', $range, 2);
        
        if ($size_unit != 'bytes') {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            exit;
        }
        
        list($range_start, $range_end) = explode('-', $range_orig, 2);
        
        $range_start = intval($range_start);
        $range_end = $range_end === '' ? $file_size - 1 : intval($range_end);
        
        if ($range_start > $range_end || $range_start >= $file_size || $range_end >= $file_size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes */$file_size");
            exit;
        }
        
        $length = $range_end - $range_start + 1;
        
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $range_start-$range_end/$file_size");
        header('Content-Length: ' . $length);
        
        // Enviar solo el rango solicitado
        $chunk_size = 8192;
        $offset = $range_start;
        $remaining = $length;
        
        while ($remaining > 0) {
            $read_size = min($chunk_size, $remaining);
            $chunk = $sftp->get($remote_path, false, $offset, $read_size);
            
            if ($chunk === false) break;
            
            echo $chunk;
            flush();
            $chunk_len = strlen($chunk);
            $offset += $chunk_len;
            $remaining -= $chunk_len;
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error interno del servidor';
}
?>