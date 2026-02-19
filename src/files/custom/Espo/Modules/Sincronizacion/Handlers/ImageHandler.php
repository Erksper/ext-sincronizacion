<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;

class ImageHandler
{
    private EntityManager $entityManager;
    private const DEFAULT_USER_USERNAME = '0';
    private const IMAGE_FIELD = 'cImagenId';
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    public function getDefaultImageId(): ?string
    {
        try {
            $defaultUser = $this->entityManager->getRDBRepository('User')
                ->where(['userName' => self::DEFAULT_USER_USERNAME])
                ->findOne();
            
            if (!$defaultUser) {
                return null;
            }
            
            $imageId = $defaultUser->get(self::IMAGE_FIELD);
            
            if (empty($imageId)) {
                $alternativeFields = ['cImageId', 'cimageId', 'cImagen', 'avatarId'];
                foreach ($alternativeFields as $altField) {
                    $altValue = $defaultUser->get($altField);
                    if (!empty($altValue)) {
                        return $altValue;
                    }
                }
                return null;
            }
            
            return $imageId;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function downloadAndSaveImage(string $fotoPath): ?string
    {
        try {
            $url = "https://venezuela.21online.lat/" . ltrim($fotoPath, '/');
            $imageContent = @file_get_contents($url);
            
            if ($imageContent === false || strlen($imageContent) === 0) {
                return null;
            }
            
            $fileInfo = pathinfo($fotoPath);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');
            $fileName = $fileInfo['basename'] ?? 'avatar.' . $extension;
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                return null;
            }
            
            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name' => $fileName,
                'type' => $this->getMimeType($extension),
                'role' => 'Attachment',
                'size' => strlen($imageContent),
                'relatedType' => 'User',
                'field' => 'cImagen'
            ]);
            
            $this->entityManager->saveEntity($attachment);
            
            $filePath = "data/upload/" . $attachment->getId();
            
            if (file_put_contents($filePath, $imageContent) === false) {
                $this->entityManager->removeEntity($attachment);
                return null;
            }
            
            return $attachment->getId();
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function processUserImage(?string $fotoPath, ?string $currentImageId): array
    {
        $result = [
            'imageId' => $currentImageId,
            'updated' => false
        ];
        
        if (!empty($fotoPath)) {
            $newImageId = $this->downloadAndSaveImage($fotoPath);
            
            if ($newImageId !== null && $newImageId !== $currentImageId) {
                $result['imageId'] = $newImageId;
                $result['updated'] = true;
            } else if ($newImageId === null && empty($currentImageId)) {
                $defaultId = $this->getDefaultImageId();
                if ($defaultId) {
                    $result['imageId'] = $defaultId;
                    $result['updated'] = true;
                }
            }
            
            return $result;
        }
        
        $defaultImageId = $this->getDefaultImageId();
        
        if ($defaultImageId === null) {
            return $result;
        }
        
        if ($currentImageId !== $defaultImageId) {
            $result['imageId'] = $defaultImageId;
            $result['updated'] = true;
        }
        
        return $result;
    }
    
    public function getImageFieldName(): string
    {
        return self::IMAGE_FIELD;
    }
    
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