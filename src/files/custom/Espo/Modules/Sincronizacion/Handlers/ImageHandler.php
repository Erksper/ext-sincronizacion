<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;

/**
 * Manejador de imágenes de usuarios
 * 
 * NOTA: El campo personalizado se llama "cImagen" en EspoCRM,
 * por lo que el atributo de ID del attachment es "cImagenId".
 */
class ImageHandler
{
    private EntityManager $entityManager;
    
    // El userName del usuario que tiene la imagen por defecto
    private const DEFAULT_USER_USERNAME = '0';
    
    // Nombre del campo personalizado en EspoCRM (en camelCase para get/set)
    // Campo: c_imagen_id en BD → cImagenId en EspoCRM ORM
    private const IMAGE_FIELD = 'cImagenId';
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    /**
     * Obtiene el ID de la imagen por defecto del usuario con userName="0"
     * 
     * @return string|null ID del attachment de imagen por defecto
     */
    public function getDefaultImageId(): ?string
    {
        try {
            error_log('[ImageHandler] Buscando usuario por defecto con userName="' . self::DEFAULT_USER_USERNAME . '"...');
            
            $defaultUser = $this->entityManager->getRDBRepository('User')
                ->where(['userName' => self::DEFAULT_USER_USERNAME])
                ->findOne();
            
            if (!$defaultUser) {
                error_log('[ImageHandler] ERROR: Usuario por defecto "' . self::DEFAULT_USER_USERNAME . '" no encontrado');
                return null;
            }
            
            error_log('[ImageHandler] Usuario "' . self::DEFAULT_USER_USERNAME . '" encontrado (ID: ' . $defaultUser->getId() . ')');
            
            // Usar el nombre de campo correcto: cImagenId
            $imageId = $defaultUser->get(self::IMAGE_FIELD);
            
            error_log('[ImageHandler] Valor de ' . self::IMAGE_FIELD . ': ' . ($imageId ?? 'NULL/VACÍO'));
            
            if (empty($imageId)) {
                error_log('[ImageHandler] ERROR: Usuario "0" no tiene imagen asignada en campo ' . self::IMAGE_FIELD);
                
                // Intentar también con variantes del nombre por si acaso
                $alternativeFields = ['cImageId', 'cimageId', 'cImagen', 'avatarId'];
                foreach ($alternativeFields as $altField) {
                    $altValue = $defaultUser->get($altField);
                    error_log("[ImageHandler] Intentando campo alternativo '{$altField}': " . ($altValue ?? 'NULL'));
                    if (!empty($altValue)) {
                        error_log("[ImageHandler] ¡Imagen encontrada en campo alternativo '{$altField}'!");
                        return $altValue;
                    }
                }
                
                return null;
            }
            
            error_log('[ImageHandler] ✓ Imagen por defecto obtenida (ID: ' . $imageId . ')');
            return $imageId;
            
        } catch (\Exception $e) {
            error_log('[ImageHandler] ERROR obteniendo imagen por defecto: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Descarga una imagen desde la URL externa y la guarda en EspoCRM
     * 
     * @param string $fotoPath Ruta relativa en el servidor externo
     * @return string|null ID del attachment creado o null si falla
     */
    public function downloadAndSaveImage(string $fotoPath): ?string
    {
        try {
            $url = "https://venezuela.21online.lat/" . ltrim($fotoPath, '/');
            error_log("[ImageHandler] Descargando imagen desde: {$url}");
            
            $imageContent = @file_get_contents($url);
            
            if ($imageContent === false || strlen($imageContent) === 0) {
                error_log("[ImageHandler] ERROR: No se pudo descargar imagen desde: {$url}");
                return null;
            }
            
            error_log("[ImageHandler] Imagen descargada exitosamente (" . strlen($imageContent) . " bytes)");
            
            $fileInfo = pathinfo($fotoPath);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');
            $fileName = $fileInfo['basename'] ?? 'avatar.' . $extension;
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                error_log("[ImageHandler] ERROR: Extensión no permitida: {$extension}");
                return null;
            }
            
            // Crear el attachment en EspoCRM
            // El campo relacionado es "cImagen" (nombre del campo en EspoCRM)
            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name' => $fileName,
                'type' => $this->getMimeType($extension),
                'role' => 'Attachment',
                'size' => strlen($imageContent),
                'relatedType' => 'User',
                'field' => 'cImagen'  // Nombre del campo sin "Id" al final
            ]);
            
            $this->entityManager->saveEntity($attachment);
            error_log("[ImageHandler] Attachment creado con ID: {$attachment->getId()}");
            
            // Guardar el contenido del archivo
            $filePath = "data/upload/" . $attachment->getId();
            
            if (file_put_contents($filePath, $imageContent) === false) {
                error_log("[ImageHandler] ERROR: No se pudo guardar archivo en: {$filePath}");
                $this->entityManager->removeEntity($attachment);
                return null;
            }
            
            error_log("[ImageHandler] ✓ Imagen guardada en: {$filePath}");
            return $attachment->getId();
            
        } catch (\Exception $e) {
            error_log('[ImageHandler] ERROR descargando imagen: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtiene o descarga la imagen para un usuario
     * 
     * @param string|null $fotoPath Ruta de foto en BD externa (puede ser null)
     * @param string|null $currentImageId ID de imagen actual del usuario (campo cImagenId)
     * @return array ['imageId' => string|null, 'updated' => bool]
     */
    public function processUserImage(?string $fotoPath, ?string $currentImageId): array
    {
        error_log('[ImageHandler] --- Procesando imagen de usuario ---');
        error_log('[ImageHandler] fotoPath: ' . ($fotoPath ?? 'NULL'));
        error_log('[ImageHandler] currentImageId (campo ' . self::IMAGE_FIELD . '): ' . ($currentImageId ?? 'NULL'));
        
        $result = [
            'imageId' => $currentImageId,
            'updated' => false
        ];
        
        // Si hay fotoPath, intentar descargar la imagen externa
        if (!empty($fotoPath)) {
            error_log('[ImageHandler] Hay fotoPath, intentando descargar imagen externa...');
            $newImageId = $this->downloadAndSaveImage($fotoPath);
            
            if ($newImageId !== null && $newImageId !== $currentImageId) {
                error_log('[ImageHandler] ✓ Imagen descargada (ID: ' . $newImageId . ')');
                $result['imageId'] = $newImageId;
                $result['updated'] = true;
            } else if ($newImageId === null) {
                error_log('[ImageHandler] No se pudo descargar imagen, usando imagen actual o por defecto');
                // Si no se pudo descargar y no tiene imagen, usar por defecto
                if (empty($currentImageId)) {
                    $defaultId = $this->getDefaultImageId();
                    if ($defaultId) {
                        $result['imageId'] = $defaultId;
                        $result['updated'] = true;
                        error_log('[ImageHandler] Usando imagen por defecto como fallback');
                    }
                }
            }
            
            return $result;
        }
        
        // Si no hay fotoPath, usar imagen por defecto del usuario "0"
        error_log('[ImageHandler] No hay fotoPath, usando imagen por defecto...');
        $defaultImageId = $this->getDefaultImageId();
        
        if ($defaultImageId === null) {
            error_log('[ImageHandler] ERROR: No se pudo obtener imagen por defecto');
            return $result;
        }
        
        // Solo actualizar si la imagen actual es diferente a la por defecto
        if ($currentImageId !== $defaultImageId) {
            error_log('[ImageHandler] ✓ Asignando imagen por defecto (ID: ' . $defaultImageId . ')');
            $result['imageId'] = $defaultImageId;
            $result['updated'] = true;
        } else {
            error_log('[ImageHandler] Usuario ya tiene la imagen por defecto, sin cambios');
        }
        
        return $result;
    }
    
    /**
     * Nombre del campo de imagen en EspoCRM (para usar en get/set)
     */
    public function getImageFieldName(): string
    {
        return self::IMAGE_FIELD;
    }
    
    /**
     * Obtiene el MIME type según la extensión del archivo
     */
    private function getMimeType(string $extension): string
    {
        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        return $mimeTypes[$extension] ?? 'image/jpeg';
    }
}
