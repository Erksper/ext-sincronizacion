<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;

/**
 * Manejador de imágenes de usuarios
 */
class ImageHandler
{
    private EntityManager $entityManager;
    private const DEFAULT_USERNAME = '0';
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    /**
     * Obtiene el ID de la imagen por defecto del usuario "0"
     * 
     * @return string|null ID de la imagen del usuario por defecto
     */
    public function getDefaultImageId(): ?string
    {
        try {
            error_log('[ImageHandler] Buscando usuario por defecto con userName="0"...');
            
            $defaultUser = $this->entityManager->getRDBRepository('User')
                ->where(['userName' => self::DEFAULT_USERNAME])
                ->findOne();
            
            if (!$defaultUser) {
                error_log('[ImageHandler] ERROR: Usuario por defecto "0" no encontrado');
                return null;
            }
            
            error_log('[ImageHandler] Usuario "0" encontrado (ID: ' . $defaultUser->getId() . ')');
            
            $imageId = $defaultUser->get('cImageId');
            
            if (empty($imageId)) {
                error_log('[ImageHandler] ERROR: Usuario "0" no tiene imagen asignada (cImageId está vacío)');
                return null;
            }
            
            error_log('[ImageHandler] Imagen por defecto obtenida: ' . $imageId);
            return $imageId;
            
        } catch (\Exception $e) {
            error_log('[ImageHandler] ERROR obteniendo imagen por defecto: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Descarga una imagen desde una URL externa y la guarda en EspoCRM
     * 
     * @param string $fotoPath Ruta de la foto en el servidor externo
     * @return string|null ID del attachment creado o null si falla
     */
    public function downloadAndSaveImage(string $fotoPath): ?string
    {
        try {
            $url = "https://venezuela.21online.lat/" . $fotoPath;
            error_log("[ImageHandler] Descargando imagen desde: {$url}");
            
            // Descargar la imagen
            $imageContent = @file_get_contents($url);
            
            if ($imageContent === false) {
                error_log("[ImageHandler] ERROR: No se pudo descargar imagen desde: {$url}");
                return null;
            }
            
            error_log("[ImageHandler] Imagen descargada exitosamente (" . strlen($imageContent) . " bytes)");
            
            // Obtener información del archivo
            $fileInfo = pathinfo($fotoPath);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');
            $fileName = $fileInfo['basename'] ?? 'avatar.' . $extension;
            
            error_log("[ImageHandler] Nombre archivo: {$fileName}, Extensión: {$extension}");
            
            // Validar que sea una imagen
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                error_log("[ImageHandler] ERROR: Extensión no permitida: {$extension}");
                return null;
            }
            
            // Crear el attachment
            error_log("[ImageHandler] Creando attachment en EspoCRM...");
            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name' => $fileName,
                'type' => $this->getMimeType($extension),
                'role' => 'Attachment',
                'size' => strlen($imageContent),
                'relatedType' => 'User',
                'field' => 'cImage'
            ]);
            
            $this->entityManager->saveEntity($attachment);
            error_log("[ImageHandler] Attachment creado con ID: {$attachment->getId()}");
            
            // Guardar el contenido del archivo
            $filePath = "data/upload/" . $attachment->getId();
            $uploadDir = dirname($filePath);
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
                error_log("[ImageHandler] Directorio creado: {$uploadDir}");
            }
            
            if (file_put_contents($filePath, $imageContent) === false) {
                error_log("[ImageHandler] ERROR: Error guardando archivo en: {$filePath}");
                $this->entityManager->removeEntity($attachment);
                return null;
            }
            
            error_log("[ImageHandler] ✓ Archivo guardado exitosamente en: {$filePath}");
            return $attachment->getId();
            
        } catch (\Exception $e) {
            error_log('[ImageHandler] ERROR descargando imagen: ' . $e->getMessage());
            error_log('[ImageHandler] Stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * Obtiene o descarga la imagen para un usuario
     * 
     * @param string|null $fotoPath Ruta de la foto en BD externa (puede ser null)
     * @param string|null $currentImageId ID de imagen actual del usuario
     * @return array ['imageId' => string|null, 'updated' => bool]
     */
    public function processUserImage(?string $fotoPath, ?string $currentImageId): array
    {
        error_log('[ImageHandler] --- Procesando imagen de usuario ---');
        error_log('[ImageHandler] fotoPath: ' . ($fotoPath ?? 'NULL'));
        error_log('[ImageHandler] currentImageId: ' . ($currentImageId ?? 'NULL'));
        
        $result = [
            'imageId' => $currentImageId,
            'updated' => false
        ];
        
        // Si hay fotoPath, intentar descargar la imagen
        if (!empty($fotoPath)) {
            error_log('[ImageHandler] fotoPath NO es null, descargando imagen...');
            $newImageId = $this->downloadAndSaveImage($fotoPath);
            
            if ($newImageId !== null && $newImageId !== $currentImageId) {
                error_log('[ImageHandler] ✓ Imagen descargada exitosamente (ID: ' . $newImageId . ')');
                $result['imageId'] = $newImageId;
                $result['updated'] = true;
            } else if ($newImageId === $currentImageId) {
                error_log('[ImageHandler] Imagen descargada es la misma que la actual');
            } else {
                error_log('[ImageHandler] No se pudo descargar la imagen');
            }
            
            return $result;
        }
        
        // Si no hay fotoPath, usar imagen por defecto del usuario "0"
        error_log('[ImageHandler] fotoPath es null, usando imagen por defecto...');
        $defaultImageId = $this->getDefaultImageId();
        
        if ($defaultImageId === null) {
            error_log('[ImageHandler] ERROR: No se pudo obtener imagen por defecto');
            return $result;
        }
        
        // Solo actualizar si la imagen actual es diferente a la por defecto
        if ($currentImageId !== $defaultImageId) {
            error_log('[ImageHandler] ✓ Actualizando a imagen por defecto (ID: ' . $defaultImageId . ')');
            $result['imageId'] = $defaultImageId;
            $result['updated'] = true;
        } else {
            error_log('[ImageHandler] Usuario ya tiene la imagen por defecto');
        }
        
        return $result;
    }
    
    /**
     * Obtiene el MIME type según la extensión
     */
    private function getMimeType(string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        return $mimeTypes[$extension] ?? 'image/jpeg';
    }
}