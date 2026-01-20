<?php
// Configuración del servidor de medios
class MediaServer {
    // Configuración SFTP
    const SFTP_HOST = '192.168.1.5';
    const SFTP_PORT = 22;
    const SFTP_USER = 'mediauser';
    const SFTP_PASS = 'Mortadela1';
    const SFTP_ROOT = 'D:\MediaServer';
    
    // Configuración HTTP para consumo
    const HTTP_BASE_URL = 'http://192.168.1.5/media/';
    const HTTP_VIDEOS = 'http://192.168.1.5/media/videos/';
    const HTTP_AUDIOS = 'http://192.168.1.5/media/audios/';
    const HTTP_DOCS = 'http://192.168.1.5/media/documents/';
    
    // Tipos de contenido permitidos
    const ALLOWED_TYPES = [
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'mkv'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'document' => ['pdf', 'doc', 'docx', 'txt', 'xlsx', 'pptx']
    ];
    
    // Tamaño máximo de archivo (500MB)
    const MAX_FILE_SIZE = 500 * 1024 * 1024;
    
    /**
     * Conectar al servidor SFTP
     */
    public static function connectSFTP() {
        try {
            $connection = ssh2_connect(self::SFTP_HOST, self::SFTP_PORT);
            if (!$connection) {
                throw new Exception("No se pudo conectar al servidor SFTP");
            }
            
            if (!ssh2_auth_password($connection, self::SFTP_USER, self::SFTP_PASS)) {
                throw new Exception("Autenticación SFTP fallida");
            }
            
            $sftp = ssh2_sftp($connection);
            if (!$sftp) {
                throw new Exception("No se pudo inicializar SFTP");
            }
            
            return [
                'connection' => $connection,
                'sftp' => $sftp,
                'resource' => $sftp
            ];
        } catch (Exception $e) {
            error_log("Error conexión SFTP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Subir archivo al servidor de medios
     */
    public static function uploadFile($localFile, $type, $fileName = null) {
        if (!file_exists($localFile)) {
            throw new Exception("Archivo local no existe");
        }
        
        // Verificar tamaño
        if (filesize($localFile) > self::MAX_FILE_SIZE) {
            throw new Exception("Archivo excede el tamaño máximo de 500MB");
        }
        
        // Obtener extensión
        $extension = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
        
        // Verificar tipo permitido
        if (!isset(self::ALLOWED_TYPES[$type]) || !in_array($extension, self::ALLOWED_TYPES[$type])) {
            throw new Exception("Tipo de archivo no permitido para $type");
        }
        
        // Generar nombre único si no se proporciona
        if (!$fileName) {
            $fileName = uniqid() . '_' . time() . '.' . $extension;
        }
        
        // Conectar SFTP
        $sftpData = self::connectSFTP();
        if (!$sftpData) {
            throw new Exception("Error al conectar con servidor SFTP");
        }
        
        // Definir ruta remota según tipo
        $remoteDir = '';
        switch($type) {
            case 'video':
                $remoteDir = 'videos/';
                break;
            case 'audio':
                $remoteDir = 'audios/';
                break;
            case 'document':
                $remoteDir = 'documents/';
                break;
            default:
                throw new Exception("Tipo de contenido no válido");
        }
        
        $remotePath = self::SFTP_ROOT . '/' . $remoteDir . $fileName;
        $remoteUrl = '';
        
        switch($type) {
            case 'video':
                $remoteUrl = self::HTTP_VIDEOS . $fileName;
                break;
            case 'audio':
                $remoteUrl = self::HTTP_AUDIOS . $fileName;
                break;
            case 'document':
                $remoteUrl = self::HTTP_DOCS . $fileName;
                break;
        }
        
        // Subir archivo
        $stream = fopen("ssh2.sftp://{$sftpData['resource']}/{$remotePath}", 'w');
        if (!$stream) {
            throw new Exception("No se pudo crear archivo remoto");
        }
        
        $localStream = fopen($localFile, 'r');
        if (!$localStream) {
            fclose($stream);
            throw new Exception("No se pudo leer archivo local");
        }
        
        $bytes = stream_copy_to_stream($localStream, $stream);
        fclose($stream);
        fclose($localStream);
        
        ssh2_disconnect($sftpData['connection']);
        
        if ($bytes === false) {
            throw new Exception("Error al copiar archivo");
        }
        
        return [
            'success' => true,
            'url' => $remoteUrl,
            'filename' => $fileName,
            'size' => $bytes,
            'type' => $type
        ];
    }
    
    /**
     * Obtener URL pública de un archivo
     */
    public static function getPublicUrl($filename, $type) {
        switch($type) {
            case 'video':
                return self::HTTP_VIDEOS . $filename;
            case 'audio':
                return self::HTTP_AUDIOS . $filename;
            case 'document':
                return self::HTTP_DOCS . $filename;
            default:
                return self::HTTP_BASE_URL . $filename;
        }
    }
    
    /**
     * Validar código embed (Spotify, YouTube, etc.)
     */
    public static function validateEmbedCode($code) {
        // Limpiar y validar código embed
        $code = trim($code);
        
        // Verificar si es código de Spotify
        if (strpos($code, 'spotify.com/embed') !== false) {
            return [
                'type' => 'spotify',
                'valid' => true,
                'code' => $code
            ];
        }
        
        // Verificar si es código de YouTube
        if (strpos($code, 'youtube.com/embed') !== false || strpos($code, 'youtu.be') !== false) {
            return [
                'type' => 'youtube',
                'valid' => true,
                'code' => $code
            ];
        }
        
        // Verificar si es iframe genérico
        if (strpos($code, '<iframe') !== false) {
            return [
                'type' => 'generic',
                'valid' => true,
                'code' => $code
            ];
        }
        
        return [
            'type' => 'unknown',
            'valid' => false,
            'code' => $code
        ];
    }
    
    /**
     * Generar thumbnail para video/audio
     */
    public static function generateThumbnail($videoUrl, $type) {
        // Thumbnails por defecto según tipo
        $defaultThumbnails = [
            'video' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80',
            'audio' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80',
            'document' => 'https://images.unsplash.com/photo-1545235617-9465d2a55698?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80',
            'spotify' => 'https://images.unsplash.com/photo-1586671267731-da2cf3ceeb80?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80',
            'youtube' => 'https://images.unsplash.com/photo-1493711662062-fa541adb3fc8?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80'
        ];
        
        return $defaultThumbnails[$type] ?? $defaultThumbnails['video'];
    }
}