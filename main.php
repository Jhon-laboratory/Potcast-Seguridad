<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Incluir phpseclib para SFTP usando autoload de composer
require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SFTP;

// ===== CONFIGURACIÓN SFTP =====
$sftp_host = '192.168.1.5';
$sftp_port = 22;
$sftp_user = 'mediauser';
$sftp_pass = 'Mortadela1';
$remote_dir = '/videos/';
// ==============================

// Conectar al SFTP
function connectSFTP() {
    global $sftp_host, $sftp_port, $sftp_user, $sftp_pass;
    
    try {
        $sftp = new SFTP($sftp_host, $sftp_port);
        if (!$sftp->login($sftp_user, $sftp_pass)) {
            return ['success' => false, 'error' => 'Error de autenticación SFTP'];
        }
        return ['success' => true, 'sftp' => $sftp];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Obtener archivos multimedia del SFTP
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
            
            if (!$stat || !isset($stat['type']) || $stat['type'] != 1) { // 1 = archivo regular
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
                continue; // Saltar archivos no multimedia
            }
            
            // Obtener URL pública
            $public_url = "stream.php?file=" . urlencode($file);
            
            $media_files[] = [
                'id' => md5($file),
                'filename' => $file,
                'title' => ucfirst(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME))),
                'type' => $file_type,
                'extension' => strtoupper($extension),
                'size' => $stat['size'],
                'modified' => $stat['mtime'],
                'url' => $public_url,
                'thumbnail' => getThumbnailForFile($file_type, $extension),
                'description' => 'Archivo multimedia del servidor',
                'views' => 0,
                'likes' => 0,
                'date' => date('d/m/Y H:i', $stat['mtime'])
            ];
        }
        
        // Ordenar por fecha de modificación (más reciente primero)
        usort($media_files, function($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });
        
        return ['success' => true, 'files' => $media_files];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error al leer archivos: ' . $e->getMessage()];
    }
}

// Obtener thumbnail según tipo de archivo
function getThumbnailForFile($type, $extension) {
    if ($type === 'video') {
        if (file_exists('img/video-thumb.jpg')) {
            return 'img/video-thumb.jpg';
        }
        return 'img/default-video.jpg';
    } elseif ($type === 'audio') {
        if (file_exists('img/audio-thumb.jpg')) {
            return 'img/audio-thumb.jpg';
        }
        return 'img/default-audio.jpg';
    }
    
    if (file_exists('img/default-thumb.jpg')) {
        return 'img/default-thumb.jpg';
    }
    return 'img/default-media.jpg';
}

// Obtener archivos del SFTP
$media_result = getSFTPFiles();
if ($media_result['success']) {
    $media_files = $media_result['files'];
    $sftp_message = isset($media_result['message']) ? $media_result['message'] : '';
} else {
    $media_files = [];
    $sftp_error = $media_result['error'];
}

$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';
$usuario = $_SESSION['usuario'] ?? 'Usuario';
$total_files = count($media_files);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal Multimedia - RANSA</title>

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
        /* Fondo específico para esta página */
        body.nav-md {
            background: linear-gradient(rgba(245, 247, 250, 0.97), rgba(245, 247, 250, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
        /* BARRA DE BÚSQUEDA - FIXED */
        .search-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
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

        /* BOTÓN SUBIR - FIXED */
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

        /* GRID DE MULTIMEDIA - FIXED TAMAÑO */
        .media-container {
            padding: 0;
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin: 0;
        }

        .media-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid #e8f5e9;
            height: 320px; /* ALTURA FIJA */
            display: flex;
            flex-direction: column;
        }

        .media-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 154, 63, 0.15);
            border-color: #009A3F;
        }

        .media-type {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0, 154, 63, 0.9);
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .media-thumbnail {
            position: relative;
            width: 100%;
            height: 160px; /* ALTURA FIJA PARA THUMBNAIL */
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
            width: 45px;
            height: 45px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #009A3F;
            font-size: 16px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .media-card:hover .media-play-btn {
            transform: scale(1);
        }

        .media-info {
            padding: 15px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .media-title {
            font-weight: 600;
            font-size: 14px;
            color: #333;
            margin-bottom: 6px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 40px;
        }

        .media-description {
            color: #666;
            font-size: 12px;
            margin-bottom: 10px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            flex-grow: 1;
            height: 34px;
        }

        .media-meta {
            display: flex;
            justify-content: space-between;
            color: #888;
            font-size: 11px;
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0;
        }

        .media-stats {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .media-date {
            color: #999;
            font-size: 10px;
            text-align: right;
        }

        /* BADGES PARA TIPOS DE ARCHIVO */
        .video-badge {
            background: #FF5722;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .audio-badge {
            background: #2196F3;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ESTADOS */
        .no-media {
            text-align: center;
            padding: 50px 20px;
            color: #666;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            margin: 20px 0;
            grid-column: 1 / -1;
        }

        .no-media i {
            font-size: 40px;
            margin-bottom: 15px;
            color: #ddd;
        }

        .no-media h3 {
            font-size: 16px;
            margin-bottom: 10px;
        }

        .no-media p {
            font-size: 13px;
            margin-bottom: 20px;
        }

        .loading {
            text-align: center;
            padding: 50px 20px;
            color: #009A3F;
            grid-column: 1 / -1;
        }

        .loading i {
            font-size: 36px;
            margin-bottom: 15px;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
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

        .modal-header .close {
            color: white;
            opacity: 0.8;
            text-shadow: none;
            font-size: 20px;
        }

        .upload-area {
            border: 2px dashed #009A3F;
            border-radius: 10px;
            padding: 30px 15px;
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
            font-size: 36px;
            color: #009A3F;
            margin-bottom: 10px;
        }

        .upload-area h4 {
            font-size: 16px;
            margin-bottom: 5px;
        }

        .upload-area p {
            font-size: 13px;
            color: #666;
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
            margin-top: 20px;
            padding: 12px 15px;
            background: rgba(0, 154, 63, 0.05);
            border-radius: 8px;
            font-size: 11px;
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
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
            }
            
            .media-card {
                height: 300px;
            }
            
            .media-thumbnail {
                height: 140px;
            }
        }

        @media (max-width: 480px) {
            .media-grid {
                grid-template-columns: 1fr;
            }
            
            .media-card {
                max-width: 100%;
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
                            <span style="font-size: 12px; margin-left: 4px;">Multimedia</span>
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
                                    <a href="main.php"><i class="fa fa-video-camera"></i> Multimedia</a>
                                </li>
                                <li>
                                    <a href="ransa_main.php"><i class="fa fa-dashboard"></i> Dashboard</a>
                                </li>
                                <li>
                                    <a href="ingreso.php"><i class="fa fa-sign-in"></i> Ingreso</a>
                                </li>
                                <li>
                                    <a href="translado.php"><i class="fa fa-exchange"></i> Traslado</a>
                                </li>
                                <li>
                                    <a href="reportes.php"><i class="fa fa-file-text"></i> Reportes</a>
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
                        <div class="x_panel">
                            <div class="x_content">
                                <!-- Barra de búsqueda y subida -->
                                <div class="search-container">
                                    <div class="search-box">
                                        <div class="search-input">
                                            <i class="fa fa-search"></i>
                                            <input type="text" id="searchInput" class="form-control" 
                                                   placeholder="Buscar videos o audios...">
                                        </div>
                                        <button class="btn-upload" data-toggle="modal" data-target="#uploadModal">
                                            <i class="fa fa-upload"></i> Subir Archivo
                                        </button>
                                    </div>
                                    <div class="mt-3 text-muted" style="font-size: 12px;">
                                        <i class="fa fa-server"></i>
                                        <span>SFTP: <?php echo $sftp_host; ?> | </span>
                                        <span><?php echo $total_files; ?> archivo(s)</span>
                                        <?php if (isset($sftp_error)): ?>
                                            <span class="text-danger ml-2">
                                                <i class="fa fa-exclamation-triangle"></i> Error: <?php echo $sftp_error; ?>
                                            </span>
                                        <?php elseif (isset($sftp_message)): ?>
                                            <span class="text-info ml-2">
                                                <i class="fa fa-info-circle"></i> <?php echo $sftp_message; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Grid de contenido multimedia -->
                                <div class="media-container">
                                    <?php if (empty($media_files)): ?>
                                        <div class="no-media">
                                            <i class="fa fa-film"></i>
                                            <h3>No hay archivos multimedia</h3>
                                            <p><?php echo isset($sftp_error) ? $sftp_error : 'El servidor SFTP está vacío'; ?></p>
                                            <button class="btn-upload mt-3" data-toggle="modal" data-target="#uploadModal">
                                                <i class="fa fa-upload"></i> Subir Primer Archivo
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="media-grid" id="mediaGrid">
                                            <?php foreach ($media_files as $file): ?>
                                                <div class="media-card" onclick="playMedia('<?php echo $file['id']; ?>')" data-type="<?php echo $file['type']; ?>">
                                                    <div class="media-thumbnail">
                                                        <img src="<?php echo htmlspecialchars($file['thumbnail']); ?>" 
                                                             alt="<?php echo htmlspecialchars($file['title']); ?>">
                                                        <span class="media-type">
                                                            <?php if ($file['type'] === 'video'): ?>
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
                                                        <h5 class="media-title" title="<?php echo htmlspecialchars($file['title']); ?>">
                                                            <?php echo htmlspecialchars($file['title']); ?>
                                                        </h5>
                                                        <div class="media-description" title="<?php echo htmlspecialchars($file['description']); ?>">
                                                            <?php echo htmlspecialchars($file['description']); ?>
                                                        </div>
                                                        <div class="media-meta">
                                                            <div class="media-stats">
                                                                <?php if ($file['type'] === 'video'): ?>
                                                                    <span class="video-badge">
                                                                        <i class="fa fa-file-video-o"></i> <?php echo $file['extension']; ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="audio-badge">
                                                                        <i class="fa fa-file-audio-o"></i> <?php echo $file['extension']; ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <span class="mx-2">•</span>
                                                                <span title="Tamaño del archivo">
                                                                    <i class="fa fa-hdd-o"></i> <?php echo formatFileSize($file['size']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="media-date">
                                                                <?php echo $file['date']; ?>
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
                        <i class="fa fa-upload"></i> Subir Archivo Multimedia
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Formulario de subida -->
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
                    
                    <!-- Campos adicionales -->
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

                    <div class="form-group mb-3">
                        <label class="form-label">Etiquetas (opcional)</label>
                        <input type="text" id="contentTags" class="form-control" 
                               placeholder="seguridad, capacitacion, entrenamiento">
                        <small class="form-text text-muted">Separadas por comas</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fa fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-success" id="uploadBtn" disabled>
                        <i class="fa fa-upload"></i> Subir al SFTP
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
    let mediaFiles = <?php echo json_encode($media_files); ?>;
    let currentMediaId = null;

    // =============================================
    // FUNCIONES PRINCIPALES
    // =============================================
    $(document).ready(function() {
        setupEventListeners();
        initializeFileUpload();
    });

    function setupEventListeners() {
        // Cambiar tipo de contenido
        $('#contentType').change(function() {
            resetPreview();
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

        // Botón subir
        $('#uploadBtn').click(uploadToSFTP);

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
        
        $('#uploadBtn').prop('disabled', false);
        
        Swal.fire({
            title: 'Archivo seleccionado',
            text: `${file.name} (${formatFileSize(file.size)})`,
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
        
        if (!$('#contentTitle').val()) {
            Swal.fire('Error', 'Por favor ingresa un título', 'error');
            return;
        }
        
        // Preparar FormData
        const formData = new FormData();
        formData.append('mediaFile', selectedFile);
        formData.append('title', $('#contentTitle').val());
        formData.append('description', $('#contentDescription').val());
        formData.append('category', $('#contentCategory').val());
        formData.append('type', $('#contentType').val());
        
        // Mostrar carga
        const uploadBtn = $('#uploadBtn');
        uploadBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Subiendo...');
        
        // Enviar al handler
        fetch('upload_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Respuesta recibida:', response);
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Datos recibidos:', data);
            if (data.success) {
                Swal.fire({
                    title: '¡Éxito!',
                    html: `Archivo <strong>${data.original_name}</strong> subido correctamente<br>
                          <small>Tamaño: ${formatFileSize(data.size)}</small>`,
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                throw new Error(data.error || 'Error desconocido');
            }
        })
        .catch(error => {
            console.error('Error completo:', error);
            Swal.fire({
                title: 'Error de subida',
                text: 'No se pudo subir el archivo. Detalles: ' + error.message,
                icon: 'error',
                confirmButtonText: 'OK'
            });
            uploadBtn.prop('disabled', false).html('<i class="fa fa-upload"></i> Subir al SFTP');
        });
    }

    function playMedia(mediaId) {
        const media = mediaFiles.find(m => m.id === mediaId);
        
        if (!media) {
            Swal.fire('Error', 'Archivo no encontrado', 'error');
            return;
        }
        
        currentMediaId = mediaId;
        
        if (media.type === 'video') {
            showVideoPlayer(media);
        } else {
            showAudioPlayer(media);
        }
    }

    function showVideoPlayer(media) {
        $('#playerContainer').html(`
            <div class="p-4">
                <h4>${media.title}</h4>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted">
                        <span><i class="fa fa-file-video-o"></i> Video ${media.extension}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-hdd-o"></i> ${formatFileSize(media.size)}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-calendar"></i> ${media.date}</span>
                    </div>
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
                        <h6 class="card-subtitle mb-2 text-muted">Información del archivo</h6>
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
        
        // Inicializar reproductor de video
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

    function showAudioPlayer(media) {
        $('#playerContainer').html(`
            <div class="p-4">
                <h4>${media.title}</h4>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted">
                        <span><i class="fa fa-file-audio-o"></i> Audio ${media.extension}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-hdd-o"></i> ${formatFileSize(media.size)}</span>
                        <span class="mx-2">•</span>
                        <span><i class="fa fa-calendar"></i> ${media.date}</span>
                    </div>
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
                        <h6 class="card-subtitle mb-2 text-muted">Información del archivo</h6>
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
            
            if (title.includes(term) || type.includes(term)) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Mostrar mensaje si no hay resultados
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
        $('#uploadForm')[0].reset();
        $('#mediaPreviewContainer').hide();
        resetPreview();
        $('#uploadBtn').prop('disabled', true).html('<i class="fa fa-upload"></i> Subir al SFTP');
        selectedFile = null;
        
        // Reset file input
        const fileInput = document.getElementById('mediaFile');
        if (fileInput) {
            fileInput.value = '';
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

    // Atajos de teclado
    document.addEventListener('keydown', function(event) {
        if (event.key === 'F1') {
            event.preventDefault();
            window.location.href = 'ransa_main.php';
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
// Función para formatear tamaño de archivo
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>