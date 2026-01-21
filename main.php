<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
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

// Conectar a SQL Server
function connectSQLServer() {
    global $host, $dbname, $username, $password;
    
    try {
        $connectionInfo = array(
            "Database" => $dbname,
            "UID" => $username,
            "PWD" => $password,
            "CharacterSet" => "UTF-8"
        );
        
        $conn = sqlsrv_connect($host, $connectionInfo);
        
        if ($conn === false) {
            $errors = sqlsrv_errors();
            return ['success' => false, 'error' => 'Error SQL Server: ' . $errors[0]['message']];
        }
        
        return ['success' => true, 'conn' => $conn];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Excepción SQL Server: ' . $e->getMessage()];
    }
}

// Conectar al SFTP
function connectSFTP() {
    global $sftp_host, $sftp_port, $sftp_user, $sftp_pass;
    
    try {
        // Incluir phpseclib si no está incluido
        if (!class_exists('phpseclib3\Net\SFTP')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
        
        $sftp = new phpseclib3\Net\SFTP($sftp_host, $sftp_port);
        if (!$sftp->login($sftp_user, $sftp_pass)) {
            return ['success' => false, 'error' => 'Error de autenticación SFTP'];
        }
        return ['success' => true, 'sftp' => $sftp];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Obtener todos los archivos (videos/audios SFTP + embeds Spotify)
function getAllMediaFiles() {
    $media_files = [];
    
    // 1. Obtener archivos del SFTP
    $sftp_files = getSFTPFiles();
    if ($sftp_files['success']) {
        foreach ($sftp_files['files'] as $file) {
            $file['source'] = 'sftp';
            $file['media_type'] = 'file';
            $media_files[] = $file;
        }
    }
    
    // 2. Obtener embeds de Spotify de la base de datos
    $spotify_embeds = getSpotifyEmbeds();
    if ($spotify_embeds['success']) {
        foreach ($spotify_embeds['embeds'] as $embed) {
            $embed['source'] = 'spotify';
            $embed['media_type'] = 'embed';
            $embed['type'] = 'audio';
            $media_files[] = $embed;
        }
    }
    
    // Ordenar por fecha (más reciente primero)
    usort($media_files, function($a, $b) {
        $dateA = isset($a['modified']) ? $a['modified'] : strtotime($a['FechaCreacion']);
        $dateB = isset($b['modified']) ? $b['modified'] : strtotime($b['FechaCreacion']);
        return $dateB <=> $dateA;
    });
    
    return $media_files;
}

// Obtener archivos del SFTP
function getSFTPFiles() {
    global $remote_dir;
    
    $connection = connectSFTP();
    if (!$connection['success']) {
        return ['success' => false, 'error' => $connection['error']];
    }
    
    $sftp = $connection['sftp'];
    
    try {
        // Verificar si el directorio existe
        if (!$sftp->file_exists($remote_dir)) {
            return ['success' => true, 'files' => [], 'message' => 'El directorio está vacío'];
        }
        
        // Listar archivos del directorio remoto
        $files = $sftp->nlist($remote_dir);
        
        if (!$files || empty($files)) {
            return ['success' => true, 'files' => []];
        }
        
        $media_files = [];
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $full_path = $remote_dir . $file;
            $stat = $sftp->stat($full_path);
            
            if (!$stat || !isset($stat['type']) || $stat['type'] != 1) {
                continue;
            }
            
            // Determinar tipo de archivo
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $file_type = '';
            
            $video_extensions = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'webm', 'flv', 'm4v'];
            $audio_extensions = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'wma'];
            
            if (in_array($extension, $video_extensions)) {
                $file_type = 'video';
            } elseif (in_array($extension, $audio_extensions)) {
                $file_type = 'audio';
            } else {
                continue;
            }
            
            // Obtener estadísticas de la base de datos usando el nombre del archivo
            $stats = getMediaStatsByFilename($file, 'sftp');
            
            // Usar el nombre de la tabla (columna 'name') si existe
            $title = $stats['name'] ?? ucfirst(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));
            
            $media_files[] = [
                'id' => $stats['id'] ?? md5($file),
                'db_id' => $stats['id'] ?? null,
                'filename' => $file,
                'title' => $title,
                'type' => $file_type,
                'extension' => strtoupper($extension),
                'size' => $stat['size'],
                'modified' => $stat['mtime'],
                'url' => "stream.php?file=" . urlencode($file),
                'thumbnail' => getThumbnailForFile($file_type, $extension, $title),
                'description' => $stats['descripcion'] ?? '',
                'views' => $stats['views'] ?? 0,
                'likes' => $stats['likes'] ?? 0,
                'date' => $stats['fecha'] ?? date('d/m/Y H:i', $stat['mtime']),
                'original_name' => $file
            ];
        }
        
        return ['success' => true, 'files' => $media_files];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error al leer archivos SFTP: ' . $e->getMessage()];
    }
}

// Obtener estadísticas de medios por nombre de archivo (NUEVA FUNCIÓN)
function getMediaStatsByFilename($filename, $source) {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return ['id' => null, 'views' => 0, 'likes' => 0];
    }
    
    $conn = $connection['conn'];
    
    try {
        // Buscar por nombre_archivo (la nueva columna que creaste)
        $sql = "SELECT id, name, descripcion, Vistas AS views, Likes AS likes, 
                       CONVERT(VARCHAR, Ultimavista, 103) + ' ' + CONVERT(VARCHAR, Ultimavista, 108) as fecha_formateada
                FROM [DPL].[externos].[Vistas] 
                WHERE nombre_archivo = ?";
        
        $params = array($filename);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            // Si falla, intentar buscar por nombre similar
            $search_name = '%' . $filename . '%';
            $sql = "SELECT id, name, descripcion, Vistas AS views, Likes AS likes,
                           CONVERT(VARCHAR, Ultimavista, 103) + ' ' + CONVERT(VARCHAR, Ultimavista, 108) as fecha_formateada
                    FROM [DPL].[externos].[Vistas] 
                    WHERE nombre_archivo LIKE ? OR descripcion LIKE ?";
            
            $params = array($search_name, $search_name);
            $stmt = sqlsrv_query($conn, $sql, $params);
        }
        
        if ($stmt === false) {
            return ['id' => null, 'views' => 0, 'likes' => 0];
        }
        
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $stats = [
                'id' => $row['id'],
                'name' => $row['name'],
                'descripcion' => $row['descripcion'],
                'views' => $row['views'],
                'likes' => $row['likes'],
                'fecha' => $row['fecha_formateada'] ?? date('d/m/Y H:i')
            ];
        } else {
            $stats = ['id' => null, 'views' => 0, 'likes' => 0];
        }
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return $stats;
        
    } catch (Exception $e) {
        return ['id' => null, 'views' => 0, 'likes' => 0];
    }
}

// Obtener embeds de Spotify de la base de datos
function getSpotifyEmbeds() {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return ['success' => false, 'error' => $connection['error']];
    }
    
    $conn = $connection['conn'];
    
    try {
        $sql = "SELECT 
                    v.id,
                    v.name AS title,
                    v.descripcion,
                    v.embeded AS embed_code,
                    v.Vistas AS views,
                    v.Likes AS likes,
                    CONVERT(VARCHAR, v.Ultimavista, 103) + ' ' + CONVERT(VARCHAR, v.Ultimavista, 108) as fecha_formateada
                FROM [DPL].[externos].[Vistas] v
                WHERE v.embeded LIKE '%spotify.com/embed%'
                ORDER BY v.Ultimavista DESC";
        
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            return ['success' => false, 'error' => 'Error al ejecutar consulta'];
        }
        
        $embeds = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $embeds[] = [
                'id' => 'spotify_' . $row['id'],
                'db_id' => $row['id'],
                'title' => $row['title'],
                'embed_code' => $row['embed_code'],
                'FechaCreacion' => $row['fecha_formateada'] ?? date('d/m/Y H:i:s'),
                'views' => $row['views'],
                'likes' => $row['likes'],
                'date' => $row['fecha_formateada'] ?? date('d/m/Y H:i'),
                'description' => $row['descripcion'] ?? 'Embed de Spotify',
                'type' => 'audio',
                'extension' => 'SPOTIFY',
                'url' => 'javascript:void(0)',
                'thumbnail' => 'https://images.unsplash.com/photo-1611339555312-e607c8352fd7?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
            ];
        }
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return ['success' => true, 'embeds' => $embeds];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error al obtener embeds: ' . $e->getMessage()];
    }
}

// Extraer ID de Spotify del embed code
function extractSpotifyId($embedCode) {
    $pattern = '/spotify\.com\/embed\/(?:episode|track|album|playlist|show)\/([a-zA-Z0-9]+)/';
    preg_match($pattern, $embedCode, $matches);
    return isset($matches[1]) ? $matches[1] : '';
}

// Incrementar vistas usando db_id
function incrementViews($db_id) {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return false;
    }
    
    $conn = $connection['conn'];
    
    try {
        // Actualizar vistas y fecha
        $sql = "UPDATE [DPL].[externos].[Vistas] 
                SET Vistas = Vistas + 1,
                    Ultimavista = GETDATE()
                WHERE id = ?";
        
        $params = array($db_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            return false;
        }
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

// Incrementar likes usando db_id
function incrementLikes($db_id) {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return false;
    }
    
    $conn = $connection['conn'];
    
    try {
        // Actualizar likes
        $sql = "UPDATE [DPL].[externos].[Vistas] 
                SET Likes = Likes + 1,
                    Ultimavista = GETDATE()
                WHERE id = ?";
        
        $params = array($db_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            return false;
        }
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

// Guardar embed de Spotify directamente en tabla Vistas
function saveSpotifyEmbed($title, $embedCode, $description = '') {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return ['success' => false, 'error' => $connection['error']];
    }
    
    $conn = $connection['conn'];
    
    try {
        // Insertar directamente en Vistas - nombre_archivo será NULL para Spotify
        $sql = "INSERT INTO [DPL].[externos].[Vistas] (name, descripcion, embeded, Vistas, Likes, Ultimavista, nombre_archivo) 
                VALUES (?, ?, ?, 0, 0, GETDATE(), NULL)";
        
        $params = array($title, $description, $embedCode);
        $stmt = sqlsrv_prepare($conn, $sql, $params);
        
        if ($stmt === false || !sqlsrv_execute($stmt)) {
            $errors = sqlsrv_errors();
            return ['success' => false, 'error' => 'Error al insertar embed: ' . $errors[0]['message']];
        }
        
        // Obtener el ID insertado
        $sql = "SELECT SCOPE_IDENTITY() AS new_id";
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            return ['success' => false, 'error' => 'Error al obtener ID'];
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $new_id = $row['new_id'];
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return ['success' => true, 'id' => $new_id];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Excepción: ' . $e->getMessage()];
    }
}

// Obtener thumbnail según tipo de archivo
function getThumbnailForFile($type, $extension, $title = '') {
    // Imágenes específicas para diferentes tipos - TEMAS INDUSTRIALES/CAPACITACIÓN
    $thumbnails = [
        'video' => [
            'default' => 'https://img.freepik.com/fotos-premium/conceito-de-sistema-de-gestao-de-inventario-de-armazem-inteligente_46383-19082.jpg',
            'mp4' => 'https://img.freepik.com/fotos-premium/conceito-de-sistema-de-gestao-de-inventario-de-armazem-inteligente_46383-19082.jpg',
            'avi' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'mov' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'mkv' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'capacitacion' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'seguridad' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'procedimientos' => 'https://images.unsplash.com/photo-1581091226033-d5c48150dbaa?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
        ],
        'audio' => [
            'default' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'mp3' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'wav' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'ogg' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'm4a' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80',
            'spotify' => 'https://images.unsplash.com/photo-1611339555312-e607c8352fd7?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80'
        ]
    ];
    
    if ($type === 'video') {
        // Verificar si el título contiene palabras clave
        $title_lower = strtolower($title);
        if (strpos($title_lower, 'capacitación') !== false || strpos($title_lower, 'capacitacion') !== false) {
            return $thumbnails['video']['capacitacion'];
        } elseif (strpos($title_lower, 'seguridad') !== false) {
            return $thumbnails['video']['seguridad'];
        } elseif (strpos($title_lower, 'procedimiento') !== false) {
            return $thumbnails['video']['procedimientos'];
        }
        return $thumbnails['video'][$extension] ?? $thumbnails['video']['default'];
    } elseif ($type === 'audio') {
        if ($extension === 'spotify') {
            return $thumbnails['audio']['spotify'];
        }
        return $thumbnails['audio'][$extension] ?? $thumbnails['audio']['default'];
    }
    
    return $thumbnails['audio']['default'];
}

// ===== MANEJO DE PETICIONES AJAX =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'increment_views') {
        $db_id = $_POST['db_id'] ?? '';
        
        if (empty($db_id)) {
            echo json_encode(['success' => false, 'error' => 'ID no válido']);
            exit;
        }
        
        $result = incrementViews($db_id);
        echo json_encode(['success' => $result]);
        exit;
        
    } elseif ($_POST['action'] === 'increment_likes') {
        $db_id = $_POST['db_id'] ?? '';
        
        if (empty($db_id)) {
            echo json_encode(['success' => false, 'error' => 'ID no válido']);
            exit;
        }
        
        $result = incrementLikes($db_id);
        echo json_encode(['success' => $result]);
        exit;
        
    } elseif ($_POST['action'] === 'save_spotify_embed') {
        $title = $_POST['title'] ?? '';
        $embedCode = $_POST['embedCode'] ?? '';
        $description = $_POST['description'] ?? '';
        
        if (empty($title) || empty($embedCode)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        
        $result = saveSpotifyEmbed($title, $embedCode, $description);
        echo json_encode($result);
        exit;
    }
}

// Obtener todos los archivos multimedia
$all_media = getAllMediaFiles();
$total_files = count($all_media);

$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';
$usuario = $_SESSION['usuario'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal de Podcast - RANSA</title>

    <!-- CSS del template -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">
    
    <!-- VIDEO.JS LOCAL -->
    <link href="vendors/video.js/dist/video-js.min.css" rel="stylesheet">
    
    <!-- CSS ESPECÍFICO SOLO PARA DASHBOARD -->
    <style>
        /* Fondo sólido para el body */
        body.nav-md {
            background: #f5f7fa !important;
            min-height: 100vh;
        }
        
        /* Fondo con imagen SOLO para el área de contenido principal */
        .right_col {
            background: linear-gradient(rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.98)), 
                        url('img/fondo.png') center/cover no-repeat;
            border-radius: 10px;
            margin: 15px;
            padding: 25px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.1);
            min-height: calc(100vh - 100px);
        }
        
        /* Panel interno transparente para mostrar el fondo */
        .x_panel {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        
        /* BARRA DE BÚSQUEDA CON FONDO BLANCO */
        .search-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            border: 1px solid #e8f5e9;
            position: relative;
            overflow: hidden;
        }

        .search-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #009A3F, #00c853, #009A3F);
        }

        .search-box {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            position: relative;
        }

        .search-input .form-control {
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8f9fa;
            height: 40px;
        }

        .search-input .form-control:focus {
            border-color: #009A3F;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
            background: white;
        }

        .search-input i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
            font-size: 14px;
        }

        /* BOTÓN SUBIR */
        .btn-upload {
            background: linear-gradient(135deg, #009A3F, #00c853);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(0, 154, 63, 0.25);
            height: 40px;
            white-space: nowrap;
        }

        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 154, 63, 0.35);
            background: linear-gradient(135deg, #008a35, #00b848);
            color: white;
            text-decoration: none;
        }

        /* GRID DE MULTIMEDIA - TRANSPARENTE PARA VER EL FONDO */
        .media-container {
            padding: 0;
            background: transparent;
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 15px;
            margin: 0;
        }

        /* TARJETAS MÁS PEQUEÑAS CON FONDO BLANCO */
        .media-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid #e8f5e9;
            height: 280px;
            display: flex;
            flex-direction: column;
        }

        .media-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 154, 63, 0.15);
            border-color: #009A3F;
        }

        .media-type {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0, 154, 63, 0.9);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 600;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .media-thumbnail {
            position: relative;
            width: 100%;
            height: 140px;
            overflow: hidden;
            background: #f5f5f5;
            flex-shrink: 0;
        }

        .media-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .media-card:hover .media-thumbnail img {
            transform: scale(1.05);
        }

        .media-play-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 154, 63, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .media-card:hover .media-play-overlay {
            opacity: 1;
        }

        .media-play-btn {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #009A3F;
            font-size: 14px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .media-card:hover .media-play-btn {
            transform: scale(1);
        }

        .media-info {
            padding: 12px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .media-title {
            font-weight: 600;
            font-size: 13px;
            color: #333;
            margin-bottom: 5px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 36px;
        }

        .media-description {
            color: #666;
            font-size: 11px;
            margin-bottom: 8px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            flex-grow: 1;
            height: 30px;
        }

        .media-meta {
            display: flex;
            justify-content: space-between;
            color: #888;
            font-size: 10px;
            margin-top: auto;
            padding-top: 8px;
            border-top: 1px solid #f0f0f0;
        }

        .media-stats {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .media-date {
            color: #999;
            font-size: 9px;
            text-align: right;
        }

        /* BADGES PARA TIPOS DE ARCHIVO */
        .video-badge {
            background: #FF5722;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .audio-badge {
            background: #2196F3;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .spotify-badge {
            background: #1DB954;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        /* ESTADOS */
        .no-media {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            margin: 20px 0;
            grid-column: 1 / -1;
        }

        .no-media i {
            font-size: 36px;
            margin-bottom: 12px;
            color: #ddd;
        }

        .no-media h3 {
            font-size: 15px;
            margin-bottom: 8px;
        }

        .no-media p {
            font-size: 12px;
            margin-bottom: 15px;
        }

        /* MODALES */
        .modal-header {
            background: linear-gradient(135deg, #009A3F, #00c853);
            color: white;
            border: none;
            border-radius: 8px 8px 0 0;
            padding: 15px 20px;
        }

        .modal-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .upload-area {
            border: 2px dashed #009A3F;
            border-radius: 10px;
            padding: 25px 15px;
            text-align: center;
            background: rgba(0, 154, 63, 0.05);
            margin: 15px 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .upload-area:hover {
            background: rgba(0, 154, 63, 0.1);
            border-color: #008a35;
            transform: translateY(-2px);
        }

        .upload-area i {
            font-size: 32px;
            color: #009A3F;
            margin-bottom: 8px;
        }

        .tab-content {
            padding: 15px 0;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 500;
            font-size: 13px;
            padding: 8px 15px;
        }

        .nav-tabs .nav-link.active {
            border-bottom: 3px solid #009A3F;
            color: #009A3F;
            background: transparent;
        }

        .embed-code-input {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            height: 120px;
            resize: vertical;
        }

        .upload-tabs {
            margin-bottom: 15px;
        }

        /* VIDEO PLAYER */
        .video-js {
            width: 100%;
            height: 400px;
            border-radius: 8px;
        }

        /* FOOTER específico */
        .footer-dashboard {
            margin-top: 15px;
            padding: 10px 15px;
            background: rgba(0, 154, 63, 0.05);
            border-radius: 8px;
            font-size: 10px;
            border-top: 1px solid #e0e0e0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .search-box {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn-upload {
                width: 100%;
                justify-content: center;
            }
            
            .media-grid {
                grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
                gap: 12px;
            }
            
            .media-card {
                height: 260px;
            }
            
            .media-thumbnail {
                height: 120px;
            }
            
            .right_col {
                margin: 10px;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .media-grid {
                grid-template-columns: 1fr;
            }
            
            .media-card {
                max-width: 100%;
                height: 250px;
            }
            
            .media-thumbnail {
                height: 110px;
            }
        }
    </style>
</head>

<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- SIDEBAR -->
            <div class="col-md-3 left_col">
                <div class="left_col scroll-view">
                    <div class="navbar nav_title" style="border: 0;">
                        <a href="main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Información del usuario -->
                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo htmlspecialchars($usuario); ?></h2>
                            <span><?php echo htmlspecialchars($_SESSION['correo'] ?? ''); ?></span>
                        </div>
                    </div>

                    <br />

                    <!-- MENU -->
                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li class="active">
                                    <a href="main.php"><i class="fa fa-video-camera"></i> Videos-Potcast</a>
                                </li>
                                <li>
                                    <a href="dashboard.php"><i class="fa fa-dashboard"></i> Dashboard</a>
                                </li>

                            </ul>
                        </div>
                    </div>
                    
                    <!-- FOOTER -->
                    <div class="sidebar-footer hidden-small">
                        <a title="Actualizar" data-toggle="tooltip" data-placement="top" onclick="location.reload()">
                            <span class="glyphicon glyphicon-refresh"></span>
                        </a>
                        <a title="Salir" data-toggle="tooltip" data-placement="top" onclick="cerrarSesion()">
                            <span class="glyphicon glyphicon-off"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- NAVBAR SUPERIOR -->
            <div class="top_nav">
                <div class="nav_menu">
                    <div class="nav toggle">
                        <a id="menu_toggle"><i class="fa fa-bars"></i></a>
                    </div>
                    <div class="nav navbar-nav navbar-right">
                        <span style="color: white; padding: 15px; font-weight: 600;">
                            <i class="fa fa-user-circle"></i> 
                            <?php echo htmlspecialchars($usuario); ?>
                            <small style="opacity: 0.8; margin-left: 10px;">
                                <i class="fa fa-map-marker"></i> 
                                <?php echo htmlspecialchars($sede_usuario); ?>
                            </small>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL -->
            <div class="right_col" role="main">
                <div class="page-title"></div>
                
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel" style="background: transparent; border: none; box-shadow: none;">
                            <div class="x_content">
                                <!-- Barra de búsqueda y subida -->
                                <div class="search-container">
                                    <div class="search-box">
                                        <div class="search-input">
                                            <i class="fa fa-search"></i>
                                            <input type="text" id="searchInput" class="form-control" 
                                                   placeholder="Buscar videos, audios o Spotify...">
                                        </div>
                                        <button class="btn-upload" data-toggle="modal" data-target="#uploadModal">
                                            <i class="fa fa-plus"></i> Agregar Contenido
                                        </button>
                                    </div>
                                    
                                </div>

                                <!-- Grid de contenido multimedia -->
                                <div class="media-container">
                                    <?php if (empty($all_media)): ?>
                                        <div class="no-media">
                                            <i class="fa fa-film"></i>
                                            <h3>No hay contenido multimedia</h3>
                                            <p>Sube archivos o agrega embeds de Spotify para comenzar</p>
                                            <button class="btn-upload mt-3" data-toggle="modal" data-target="#uploadModal">
                                                <i class="fa fa-plus"></i> Agregar Contenido
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="media-grid" id="mediaGrid">
                                            <?php foreach ($all_media as $media): ?>
                                                <div class="media-card" onclick="playMedia('<?php echo $media['id']; ?>', '<?php echo $media['source']; ?>', '<?php echo $media['db_id']; ?>')" 
                                                     data-type="<?php echo $media['type']; ?>" data-source="<?php echo $media['source']; ?>">
                                                    <div class="media-thumbnail">
                                                        <img src="<?php echo htmlspecialchars($media['thumbnail']); ?>" 
                                                             alt="<?php echo htmlspecialchars($media['title']); ?>">
                                                        <span class="media-type">
                                                            <?php if ($media['source'] === 'spotify'): ?>
                                                                <i class="fa fa-spotify"></i> POTCAST
                                                            <?php elseif ($media['type'] === 'video'): ?>
                                                                <i class="fa fa-video-camera"></i> VIDEO
                                                            <?php else: ?>
                                                                <i class="fa fa-music"></i> AUDIO
                                                            <?php endif; ?>
                                                        </span>
                                                        <div class="media-play-overlay">
                                                            <div class="media-play-btn">
                                                                <i class="fa fa-play"></i>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="media-info">
                                                        <h5 class="media-title" title="<?php echo htmlspecialchars($media['title']); ?>">
                                                            <?php echo htmlspecialchars($media['title']); ?>
                                                        </h5>
                                                        <div class="media-description" title="<?php echo htmlspecialchars($media['description']); ?>">
                                                            <?php echo htmlspecialchars($media['description']); ?>
                                                        </div>
                                                        <div class="media-meta">
                                                            <div class="media-stats">
                                                                <?php if ($media['source'] === 'spotify'): ?>
                                                                    <span class="spotify-badge">
                                                                        <i class="fa fa-spotify"></i> POTCAST
                                                                    </span>
                                                                <?php elseif ($media['type'] === 'video'): ?>
                                                                    <span class="video-badge">
                                                                        <i class="fa fa-file-video-o"></i> <?php echo $media['extension']; ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="audio-badge">
                                                                        <i class="fa fa-file-audio-o"></i> <?php echo $media['extension']; ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <span class="mx-2">•</span>
                                                                <span>
                                                                    <i class="fa fa-eye"></i> <?php echo $media['views']; ?>
                                                                </span>
                                                                <span class="mx-2">•</span>
                                                                <span>
                                                                    <i class="fa fa-thumbs-up"></i> <?php echo $media['likes']; ?>
                                                                </span>
                                                            </div>
                                                            <div class="media-date">
                                                                <?php echo $media['date']; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER -->
            <footer class="footer-dashboard">
                <div class="pull-right">
                    <i class="fa fa-copyright"></i>
                    Portal Multimedia RANSA <?php echo date('Y'); ?>
                </div>
                <div class="clearfix"></div>
            </footer>
        </div>
    </div>

    <!-- MODAL SUBIR ARCHIVO -->
    <div class="modal fade" id="uploadModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa fa-plus"></i> Agregar Contenido
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Tabs para diferentes tipos de contenido -->
                    <ul class="nav nav-tabs upload-tabs" id="uploadTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="file-tab" data-toggle="tab" href="#file" role="tab">
                                <i class="fa fa-file-upload"></i> Subir Archivo
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="spotify-tab" data-toggle="tab" href="#spotify" role="tab">
                                <i class="fa fa-spotify"></i> Spotify Embed
                            </a>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="uploadTabsContent">
                        <!-- TAB 1: Subir archivo -->
                        <div class="tab-pane fade show active" id="file" role="tabpanel">
                            <form id="uploadForm" enctype="multipart/form-data">
                                <!-- Área de arrastrar y soltar -->
                                <div class="upload-area" id="uploadArea">
                                    <i class="fa fa-cloud-upload-alt"></i>
                                    <h4>Arrastra tu archivo aquí</h4>
                                    <p>o haz clic para seleccionar archivo</p>
                                    <div class="mt-2">
                                        <span class="badge badge-success mr-1">VIDEO</span>
                                        <span class="badge badge-info mr-1">AUDIO</span>
                                    </div>
                                    <p class="text-muted mt-2" style="font-size: 11px;">
                                        Formatos: MP4, AVI, MOV, MKV, MP3, WAV, OGG<br>
                                        Tamaño máximo: 500MB
                                    </p>
                                    <input type="file" id="mediaFile" name="mediaFile" 
                                           accept="video/*,audio/*" style="display: none;">
                                </div>

                                <!-- Previsualización -->
                                <div id="mediaPreviewContainer" style="display: none; text-align: center; margin: 15px 0;">
                                    <div id="videoPreview" style="display: none;">
                                        <video controls style="max-width: 100%; max-height: 200px; border-radius: 6px;"></video>
                                    </div>
                                    <div id="audioPreview" style="display: none;">
                                        <audio controls style="width: 100%; max-width: 300px;"></audio>
                                    </div>
                                    <div id="fileInfo" class="mt-3">
                                        <p class="mb-1" id="fileName"></p>
                                        <p class="text-muted mb-0" style="font-size: 11px;" id="fileSize"></p>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- TAB 2: Spotify Embed -->
                        <div class="tab-pane fade" id="spotify" role="tabpanel">
                            <form id="spotifyForm">
                                <div class="form-group mb-3">
                                    <label class="form-label">Título del contenido *</label>
                                    <input type="text" id="spotifyTitle" class="form-control" 
                                           placeholder="Ej: Podcast Motivacional Semanal" required>
                                </div>
                                
                                <div class="form-group mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea id="spotifyDescription" class="form-control" rows="2"
                                              placeholder="Breve descripción del contenido..."></textarea>
                                </div>
                                
                                <div class="form-group mb-3">
                                    <label class="form-label">Código Embed de Spotify *</label>
                                    <textarea id="spotifyEmbedCode" class="form-control embed-code-input" 
                                              placeholder='Pega aquí el código embed de Spotify. Ejemplo:
<iframe style="border-radius:12px" src="https://open.spotify.com/embed/episode/1ABCDEFGHIJK?utm_source=generator" width="100%" height="352" frameBorder="0" allowfullscreen="" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" loading="lazy"></iframe>'
                                              required rows="5"></textarea>
                                    <small class="form-text text-muted">
                                        Ve a Spotify, haz clic en "Compartir" → "Insertar" y copia el código iframe
                                    </small>
                                </div>
                                
                                <!-- Vista previa del embed -->
                                <div class="form-group mb-3">
                                    <label class="form-label">Vista Previa</label>
                                    <div class="embed-preview" id="spotifyPreview">
                                        <p class="text-muted mb-0">El contenido de Spotify aparecerá aquí</p>
                                    </div>
                                </div>
                                
                                <div class="text-center">
                                    <button type="button" class="btn btn-info btn-sm" onclick="validateSpotifyEmbed()">
                                        <i class="fa fa-check"></i> Validar y Previsualizar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Campos comunes para archivos -->
                    <div id="commonFields" class="d-none">
                        <hr class="my-3">
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Título *</label>
                            <input type="text" id="contentTitle" class="form-control" 
                                   placeholder="Ej: Capacitación de seguridad 2024" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea id="contentDescription" class="form-control" rows="2"
                                      placeholder="Breve descripción del contenido..."></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="form-label">Categoría</label>
                                    <select id="contentCategory" class="form-control">
                                        <option value="capacitacion">Capacitación</option>
                                        <option value="seguridad">Seguridad</option>
                                        <option value="procedimientos">Procedimientos</option>
                                        <option value="comunicados">Comunicados</option>
                                        <option value="eventos">Eventos</option>
                                        <option value="musica">Música</option>
                                        <option value="general">General</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="form-label">Tipo de contenido</label>
                                    <select id="contentType" class="form-control">
                                        <option value="video">Video</option>
                                        <option value="audio">Audio</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fa fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-success" id="saveBtn" disabled>
                        <i class="fa fa-save"></i> Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL REPRODUCTOR -->
    <div class="modal fade" id="playerModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body p-0">
                    <div id="playerContainer">
                        <!-- Contenido dinámico -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fa fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/fastclick/lib/fastclick.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="vendors/video.js/dist/video.min.js"></script>
    <script src="build/js/custom.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    // =============================================
    // VARIABLES GLOBALES
    // =============================================
    let player = null;
    let selectedFile = null;
    let currentEmbedData = null;
    let currentTab = 'file';
    let mediaFiles = <?php echo json_encode($all_media); ?>;
    let currentMediaId = null;
    let currentMediaSource = null;
    let currentDbId = null;

    // =============================================
    // FUNCIONES PRINCIPALES
    // =============================================
    $(document).ready(function() {
        setupEventListeners();
        initializeFileUpload();
    });

    function setupEventListeners() {
        // Cambio entre tabs
        $('#uploadTabs a').on('shown.bs.tab', function(e) {
            currentTab = $(e.target).attr("href").replace('#', '');
            
            if (currentTab === 'file') {
                $('#commonFields').removeClass('d-none');
                $('#saveBtn').html('<i class="fa fa-upload"></i> Subir Archivo');
            } else {
                $('#commonFields').addClass('d-none');
                $('#saveBtn').html('<i class="fa fa-save"></i> Guardar Embed');
            }
            
            resetSaveButton();
        });

        // Búsqueda en tiempo real
        let searchTimeout;
        $('#searchInput').on('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const term = $(this).val().toLowerCase().trim();
                if (term) {
                    filterMedia(term);
                } else {
                    showAllMedia();
                }
            }, 300);
        });

        // Botón guardar
        $('#saveBtn').click(function() {
            if (currentTab === 'file') {
                uploadToSFTP();
            } else {
                saveSpotifyEmbed();
            }
        });

        // Cerrar modales
        $('#uploadModal').on('hidden.bs.modal', function() {
            resetUploadForm();
        });

        $('#playerModal').on('hidden.bs.modal', function() {
            if (player) {
                player.dispose();
                player = null;
            }
        });
    }

    function initializeFileUpload() {
        // Click en área de upload para abrir explorador
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('mediaFile');
        
        if (uploadArea && fileInput) {
            // Click en área
            uploadArea.addEventListener('click', function(e) {
                e.preventDefault();
                fileInput.click();
            });
            
            // Cambio en input de archivo
            fileInput.addEventListener('change', handleFileSelect);
            
            // Drag and drop
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.background = 'rgba(0, 154, 63, 0.1)';
                this.style.borderColor = '#008a35';
            });
            
            uploadArea.addEventListener('dragleave', function() {
                this.style.background = 'rgba(0, 154, 63, 0.05)';
                this.style.borderColor = '#009A3F';
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.background = 'rgba(0, 154, 63, 0.05)';
                this.style.borderColor = '#009A3F';
                
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect({ target: fileInput });
                }
            });
        }
    }

    function handleFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const type = $('#contentType').val();
        
        // Validar tipo de archivo
        const validTypes = {
            'video': ['video/mp4', 'video/avi', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/x-ms-wmv', 'video/webm'],
            'audio': ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/flac']
        };
        
        const isValidType = validTypes[type]?.includes(file.type) || false;
        
        if (!isValidType) {
            Swal.fire({
                title: 'Tipo de archivo no válido',
                text: `El tipo de archivo no es compatible con ${type}. Formatos permitidos: ${type === 'video' ? 'MP4, AVI, MOV, MKV, WMV, WEBM' : 'MP3, WAV, OGG, M4A, FLAC'}`,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        if (file.size > 500 * 1024 * 1024) {
            Swal.fire({
                title: 'Archivo muy grande',
                text: 'El archivo no debe exceder 500MB',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        selectedFile = file;
        
        // Mostrar previsualización
        $('#mediaPreviewContainer').show();
        resetPreview();
        
        if (type === 'video') {
            const url = URL.createObjectURL(file);
            const videoPreview = $('#videoPreview video');
            videoPreview.attr('src', url);
            videoPreview[0].load();
            $('#videoPreview').show();
        } else {
            const url = URL.createObjectURL(file);
            const audioPreview = $('#audioPreview audio');
            audioPreview.attr('src', url);
            audioPreview[0].load();
            $('#audioPreview').show();
        }
        
        // Mostrar información del archivo
        $('#fileName').text(file.name);
        $('#fileSize').text(`Tamaño: ${formatFileSize(file.size)} | Tipo: ${file.type}`);
        
        if (!$('#contentTitle').val()) {
            $('#contentTitle').val(file.name.replace(/\.[^/.]+$/, ""));
        }
        
        $('#saveBtn').prop('disabled', false);
        
        Swal.fire({
            title: 'Archivo seleccionado',
            text: `${file.name} (${formatFileSize(file.size)})`,
            icon: 'success',
            timer: 1500,
            showConfirmButton: false
        });
    }

    function validateSpotifyEmbed() {
        const embedCode = $('#spotifyEmbedCode').val().trim();
        
        if (!embedCode) {
            Swal.fire({
                title: 'Código vacío',
                text: 'Por favor ingresa un código embed de Spotify',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // Validar que sea un embed de Spotify
        if (!embedCode.includes('spotify.com/embed')) {
            Swal.fire({
                title: 'Código no válido',
                text: 'El código debe ser un embed de Spotify válido',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        currentEmbedData = embedCode;
        
        // Mostrar preview
        $('#spotifyPreview').html(`
            <div class="text-center">
                <span class="spotify-badge" style="font-size: 14px;">
                    <i class="fa fa-spotify"></i> SPOTIFY EMBED VÁLIDO
                </span>
                <div class="mt-2">
                    <i class="fa fa-check-circle text-success"></i>
                    <small class="text-success">Código Spotify detectado correctamente</small>
                </div>
            </div>
        `);
        
        $('#saveBtn').prop('disabled', false);
        
        Swal.fire({
            title: '¡Válido!',
            text: 'Código Spotify detectado correctamente',
            icon: 'success',
            timer: 1500,
            showConfirmButton: false
        });
    }

    function uploadToSFTP() {
        if (!selectedFile) {
            Swal.fire('Error', 'Por favor selecciona un archivo', 'error');
            return;
        }
        
        const title = $('#contentTitle').val();
        if (!title.trim()) {
            Swal.fire('Error', 'Por favor ingresa un título', 'error');
            return;
        }
        
        const uploadBtn = $('#saveBtn');
        const originalText = uploadBtn.html();
        
        uploadBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Subiendo...');
        
        const formData = new FormData();
        formData.append('mediaFile', selectedFile);
        formData.append('title', title);
        formData.append('description', $('#contentDescription').val());
        formData.append('category', $('#contentCategory').val());
        formData.append('type', $('#contentType').val());
        
        fetch('upload_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: '¡Éxito!',
                    html: `Archivo <strong>${data.original_name}</strong> subido correctamente<br>
                          <small>Tamaño: ${formatFileSize(data.size)}</small>`,
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    $('#uploadModal').modal('hide');
                    setTimeout(() => location.reload(), 500);
                });
            } else {
                throw new Error(data.error || 'Error desconocido');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error de subida',
                text: error.message,
                icon: 'error',
                confirmButtonText: 'Entendido'
            });
            uploadBtn.prop('disabled', false).html(originalText);
        });
    }

    function saveSpotifyEmbed() {
        const title = $('#spotifyTitle').val();
        const embedCode = $('#spotifyEmbedCode').val();
        const description = $('#spotifyDescription').val();
        
        if (!title.trim()) {
            Swal.fire('Error', 'Por favor ingresa un título', 'error');
            return;
        }
        
        if (!embedCode.trim()) {
            Swal.fire('Error', 'Por favor ingresa un código embed', 'error');
            return;
        }
        
        const saveBtn = $('#saveBtn');
        saveBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Guardando...');
        
        fetch('main.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=save_spotify_embed&title=${encodeURIComponent(title)}&embedCode=${encodeURIComponent(embedCode)}&description=${encodeURIComponent(description)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: '¡Éxito!',
                    text: 'Embed de Spotify guardado correctamente',
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    $('#uploadModal').modal('hide');
                    setTimeout(() => location.reload(), 500);
                });
            } else {
                throw new Error(data.error || 'Error al guardar');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: 'No se pudo guardar el embed. Error: ' + error.message,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            saveBtn.prop('disabled', false).html('<i class="fa fa-save"></i> Guardar Embed');
        });
    }

    function playMedia(mediaId, source, dbId) {
        const media = mediaFiles.find(m => m.id === mediaId);
        
        if (!media) {
            Swal.fire('Error', 'Contenido no encontrado', 'error');
            return;
        }
        
        currentMediaId = mediaId;
        currentMediaSource = source;
        currentDbId = dbId;
        
        // Incrementar vistas si hay dbId
        if (dbId) {
            incrementViews(dbId);
        }
        
        if (source === 'spotify') {
            showSpotifyPlayer(media);
        } else if (media.type === 'video') {
            showVideoPlayer(media);
        } else {
            showAudioPlayer(media);
        }
    }

    function incrementViews(dbId) {
        if (!dbId) return;
        
        fetch('main.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=increment_views&db_id=${dbId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Actualizar contador en la interfaz
                const card = document.querySelector(`.media-card[onclick*="${currentMediaId}"]`);
                if (card) {
                    const viewsSpan = card.querySelector('.fa-eye').parentElement;
                    const currentViews = parseInt(viewsSpan.textContent.trim());
                    viewsSpan.innerHTML = `<i class="fa fa-eye"></i> ${currentViews + 1}`;
                }
            }
        })
        .catch(console.error);
    }

    function incrementLikes() {
        if (!currentDbId) return;
        
        fetch('main.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=increment_likes&db_id=${currentDbId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: '¡Gracias!',
                    text: 'Tu me gusta ha sido registrado',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
                
                // Actualizar contador en la interfaz
                const card = document.querySelector(`.media-card[onclick*="${currentMediaId}"]`);
                if (card) {
                    const likesSpan = card.querySelector('.fa-thumbs-up').parentElement;
                    const currentLikes = parseInt(likesSpan.textContent.trim());
                    likesSpan.innerHTML = `<i class="fa fa-thumbs-up"></i> ${currentLikes + 1}`;
                }
            }
        })
        .catch(console.error);
    }

    function showSpotifyPlayer(media) {
        $('#playerContainer').html(`
            <div class="p-4">
                <h4>${media.title}</h4>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted">
                        <span><i class="fa fa-spotify"></i> Spotify Embed</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-eye"></i> ${media.views} vistas</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-calendar"></i> ${media.date}</span>
                    </div>
                    <button class="btn btn-sm btn-outline-success" onclick="incrementLikes()">
                        <i class="fa fa-thumbs-up"></i> Me gusta (${media.likes})
                    </button>
                </div>
                
                <div class="embed-container mb-3">
                    ${media.embed_code}
                </div>
                
                
            </div>
        `);
        
        $('#playerModal').modal('show');
    }

    function showVideoPlayer(media) {
        $('#playerContainer').html(`
            <div class="p-4">
                <h4>${media.title}</h4>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted">
                        <span><i class="fa fa-file-video-o"></i> Video ${media.extension}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-eye"></i> ${media.views} vistas</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-calendar"></i> ${media.date}</span>
                    </div>
                    <button class="btn btn-sm btn-outline-success" onclick="incrementLikes()">
                        <i class="fa fa-thumbs-up"></i> Me gusta (${media.likes})
                    </button>
                </div>
                
                <div class="mb-3">
                    <video id="videoPlayer" class="video-js vjs-default-skin vjs-big-play-centered" controls preload="auto">
                        <source src="${media.url}" type="video/${getVideoMimeType(media.extension.toLowerCase())}">
                        <p class="vjs-no-js">
                            Tu navegador no soporta video HTML5. 
                            <a href="${media.url}" target="_blank">Descargar video</a>
                        </p>
                    </video>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Información</h6>
                        <p class="card-text">${media.description}</p>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fa fa-download"></i> 
                                <a href="${media.url}" target="_blank" download="${media.filename}">Descargar archivo</a>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $('#playerModal').modal('show');
        
        setTimeout(() => {
            player = videojs('videoPlayer', {
                controls: true,
                autoplay: false,
                preload: 'auto',
                fluid: true,
                playbackRates: [0.5, 1, 1.5, 2]
            });
        }, 100);
    }

    function showAudioPlayer(media) {
        $('#playerContainer').html(`
            <div class="p-4">
                <h4>${media.title}</h4>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted">
                        <span><i class="fa fa-file-audio-o"></i> Audio ${media.extension}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-eye"></i> ${media.views} vistas</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-calendar"></i> ${media.date}</span>
                    </div>
                    <button class="btn btn-sm btn-outline-success" onclick="incrementLikes()">
                        <i class="fa fa-thumbs-up"></i> Me gusta (${media.likes})
                    </button>
                </div>
                
                <div class="mb-3 text-center">
                    <div style="max-width: 500px; margin: 0 auto;">
                        <audio controls style="width: 100%;">
                            <source src="${media.url}" type="audio/${getAudioMimeType(media.extension.toLowerCase())}">
                            Tu navegador no soporta audio HTML5.
                        </audio>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Información</h6>
                        <p class="card-text">${media.description}</p>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fa fa-download"></i> 
                                <a href="${media.url}" target="_blank" download="${media.filename}">Descargar archivo</a>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $('#playerModal').modal('show');
    }

    function getVideoMimeType(extension) {
        const mimeTypes = {
            'mp4': 'mp4',
            'avi': 'x-msvideo',
            'mov': 'quicktime',
            'mkv': 'x-matroska',
            'wmv': 'x-ms-wmv',
            'webm': 'webm'
        };
        return mimeTypes[extension] || 'mp4';
    }

    function getAudioMimeType(extension) {
        const mimeTypes = {
            'mp3': 'mpeg',
            'wav': 'wav',
            'ogg': 'ogg',
            'm4a': 'mp4',
            'flac': 'flac'
        };
        return mimeTypes[extension] || 'mpeg';
    }

    function filterMedia(term) {
        const cards = document.querySelectorAll('.media-card');
        let visibleCount = 0;
        
        cards.forEach(card => {
            const title = card.querySelector('.media-title').textContent.toLowerCase();
            const type = card.getAttribute('data-type') || '';
            const source = card.getAttribute('data-source') || '';
            
            if (title.includes(term) || type.includes(term) || source.includes(term)) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        const noResults = document.getElementById('noResults');
        if (visibleCount === 0 && cards.length > 0) {
            if (!noResults) {
                const grid = document.getElementById('mediaGrid');
                const message = document.createElement('div');
                message.id = 'noResults';
                message.className = 'no-media';
                message.innerHTML = `
                    <i class="fa fa-search"></i>
                    <h3>No se encontraron resultados</h3>
                    <p>No hay contenido que coincida con "${term}"</p>
                `;
                grid.appendChild(message);
            }
        } else if (noResults) {
            noResults.remove();
        }
    }

    function showAllMedia() {
        const cards = document.querySelectorAll('.media-card');
        cards.forEach(card => {
            card.style.display = 'flex';
        });
        
        const noResults = document.getElementById('noResults');
        if (noResults) {
            noResults.remove();
        }
    }

    function resetUploadForm() {
        $('#uploadForm, #spotifyForm')[0].reset();
        $('#mediaPreviewContainer').hide();
        $('#spotifyPreview').html('<p class="text-muted mb-0">El contenido de Spotify aparecerá aquí</p>');
        resetSaveButton();
        selectedFile = null;
        currentEmbedData = null;
        
        const fileInput = document.getElementById('mediaFile');
        if (fileInput) {
            fileInput.value = '';
        }
        
        $('#file-tab').tab('show');
    }

    function resetSaveButton() {
        $('#saveBtn').prop('disabled', true);
        if (currentTab === 'file') {
            $('#saveBtn').html('<i class="fa fa-upload"></i> Subir Archivo');
        } else {
            $('#saveBtn').html('<i class="fa fa-save"></i> Guardar Embed');
        }
    }

    function resetPreview() {
        $('#videoPreview, #audioPreview').hide();
        const type = $('#contentType').val();
        
        if (type === 'video') {
            const videoPreview = $('#videoPreview video');
            videoPreview.attr('src', '');
            videoPreview[0].load();
        } else {
            const audioPreview = $('#audioPreview audio');
            audioPreview.attr('src', '');
            audioPreview[0].load();
        }
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // =============================================
    // FUNCIONES DEL SISTEMA BASE
    // =============================================
    function cerrarSesion() {
        if (confirm('¿Está seguro de que desea cerrar sesión?')) {
            window.location.href = 'logout.php';
        }
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'F1') {
            event.preventDefault();
            window.location.href = 'dashboard.php';
        }
        if (event.key === 'F2' && event.ctrlKey) {
            event.preventDefault();
            $('#uploadModal').modal('show');
        }
        if (event.key === 'Escape') {
            $('#uploadModal').modal('hide');
            $('#playerModal').modal('hide');
        }
    });
    </script>
</body>
</html>
<?php
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>