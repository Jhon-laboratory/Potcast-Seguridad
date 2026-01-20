<?php
// Sistema para almacenar y recuperar embeds
class EmbedStorage {
    const EMBED_FILE = 'data/embeds.json';
    const EMBED_DIR = 'data/embeds/';
    
    /**
     * Inicializar sistema de almacenamiento
     */
    public static function init() {
        if (!is_dir('data')) {
            mkdir('data', 0755, true);
        }
        if (!is_dir(self::EMBED_DIR)) {
            mkdir(self::EMBED_DIR, 0755, true);
        }
        if (!file_exists(self::EMBED_FILE)) {
            file_put_contents(self::EMBED_FILE, json_encode([]));
        }
    }
    
    /**
     * Guardar un embed
     */
    public static function saveEmbed($data) {
        self::init();
        
        // Generar ID único
        $id = uniqid('embed_', true);
        $filename = $id . '.json';
        
        // Crear datos completos
        $embedData = [
            'id' => $id,
            'title' => $data['title'],
            'description' => $data['description'],
            'embedCode' => $data['embedCode'],
            'embedType' => $data['embedType'],
            'category' => $data['category'],
            'tags' => $data['tags'],
            'author' => $data['author'],
            'date' => date('Y-m-d H:i:s'),
            'views' => 0,
            'likes' => 0,
            'thumbnail' => self::getEmbedThumbnail($data['embedType'])
        ];
        
        // Guardar en archivo individual
        file_put_contents(self::EMBED_DIR . $filename, json_encode($embedData, JSON_PRETTY_PRINT));
        
        // Actualizar índice principal
        $index = json_decode(file_get_contents(self::EMBED_FILE), true);
        $index[$id] = [
            'filename' => $filename,
            'title' => $data['title'],
            'type' => 'embed',
            'embedType' => $data['embedType'],
            'date' => $embedData['date'],
            'category' => $data['category']
        ];
        
        file_put_contents(self::EMBED_FILE, json_encode($index, JSON_PRETTY_PRINT));
        
        return $embedData;
    }
    
    /**
     * Obtener todos los embeds
     */
    public static function getAllEmbeds() {
        self::init();
        
        if (!file_exists(self::EMBED_FILE)) {
            return [];
        }
        
        $index = json_decode(file_get_contents(self::EMBED_FILE), true);
        $embeds = [];
        
        foreach ($index as $id => $info) {
            $filename = $info['filename'];
            $filepath = self::EMBED_DIR . $filename;
            
            if (file_exists($filepath)) {
                $embedData = json_decode(file_get_contents($filepath), true);
                $embeds[] = $embedData;
            }
        }
        
        // Ordenar por fecha (más reciente primero)
        usort($embeds, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $embeds;
    }
    
    /**
     * Incrementar vistas de un embed
     */
    public static function incrementViews($embedId) {
        $filename = $embedId . '.json';
        $filepath = self::EMBED_DIR . $filename;
        
        if (file_exists($filepath)) {
            $embedData = json_decode(file_get_contents($filepath), true);
            $embedData['views'] = ($embedData['views'] ?? 0) + 1;
            file_put_contents($filepath, json_encode($embedData, JSON_PRETTY_PRINT));
            return $embedData['views'];
        }
        
        return false;
    }
    
    /**
     * Obtener thumbnail según tipo de embed
     */
    private static function getEmbedThumbnail($embedType) {
        $thumbnails = [
            'spotify' => 'https://images.unsplash.com/photo-1586671267731-da2cf3ceeb80?ixlib=rb-1.2.1&auto=format&fit=crop&w=400&h=225&q=80',
            'youtube' => 'https://images.unsplash.com/photo-1493711662062-fa541adb3fc8?ixlib=rb-1.2.1&auto=format&fit=crop&w=400&h=225&q=80',
            'vimeo' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?ixlib=rb-1.2.1&auto=format&fit=crop&w=400&h=225&q=80',
            'generic' => 'https://images.unsplash.com/photo-1611224923853-80b023f02d71?ixlib=rb-1.2.1&auto=format&fit=crop&w=400&h=225&q=80'
        ];
        
        return $thumbnails[$embedType] ?? $thumbnails['generic'];
    }
}