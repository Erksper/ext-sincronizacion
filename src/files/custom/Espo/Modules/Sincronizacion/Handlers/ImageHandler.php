<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;

class ImageHandler
{
    private EntityManager $entityManager;
    private const DEFAULT_USER_USERNAME = '0';
    private const IMAGE_FIELD = 'cImagenId';
    private const CHECKSUM_ALGO = 'md5';

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

    /**
     * Descarga la imagen a memoria (sin crear Attachment ni escribir a disco todavia)
     * y calcula su checksum. Devuelve null si no se pudo descargar o la extension
     * no esta permitida.
     *
     * @return array{content:string,fileName:string,extension:string,checksum:string}|null
     */
    private function downloadImageToMemory(string $fotoPath): ?array
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

            return [
                'content' => $imageContent,
                'fileName' => $fileName,
                'extension' => $extension,
                'checksum' => hash(self::CHECKSUM_ALGO, $imageContent)
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Crea el Attachment y escribe el archivo a disco a partir del contenido
     * ya descargado en memoria. Solo debe llamarse cuando ya se determino
     * que la imagen realmente cambio (checksum distinto).
     */
    private function saveImageAttachment(array $downloaded): ?string
    {
        try {
            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name' => $downloaded['fileName'],
                'type' => $this->getMimeType($downloaded['extension']),
                'role' => 'Attachment',
                'size' => strlen($downloaded['content']),
                'relatedType' => 'User',
                'field' => 'cImagen'
            ]);

            $this->entityManager->saveEntity($attachment);

            $filePath = "data/upload/" . $attachment->getId();

            if (file_put_contents($filePath, $downloaded['content']) === false) {
                $this->entityManager->removeEntity($attachment);
                return null;
            }

            return $attachment->getId();

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Determina si la imagen del usuario debe actualizarse, comparando el
     * checksum del contenido descargado contra el checksum guardado
     * previamente (campo custom cChecksum), en vez de comparar la ruta.
     *
     * @return array{imageId:?string,checksum:?string,updated:bool}
     */
    public function processUserImage(?string $fotoPath, ?string $currentImageId, ?string $currentChecksum): array
    {
        $result = [
            'imageId' => $currentImageId,
            'checksum' => $currentChecksum,
            'updated' => false
        ];

        if (!empty($fotoPath)) {
            $downloaded = $this->downloadImageToMemory($fotoPath);

            if ($downloaded === null) {
                // No se pudo descargar: si no hay imagen actual, se intenta la default.
                if (empty($currentImageId)) {
                    $defaultId = $this->getDefaultImageId();
                    if ($defaultId) {
                        $result['imageId'] = $defaultId;
                        $result['checksum'] = null;
                        $result['updated'] = true;
                    }
                }
                return $result;
            }

            // Si el checksum es igual al guardado y ya hay una imagen asignada,
            // el contenido no cambio: no hace falta crear un Attachment nuevo.
            if (!empty($currentImageId) && $downloaded['checksum'] === $currentChecksum) {
                return $result;
            }

            $newImageId = $this->saveImageAttachment($downloaded);

            if ($newImageId !== null) {
                $result['imageId'] = $newImageId;
                $result['checksum'] = $downloaded['checksum'];
                $result['updated'] = true;
            }

            return $result;
        }

        $defaultImageId = $this->getDefaultImageId();

        if ($defaultImageId === null) {
            return $result;
        }

        if ($currentImageId !== $defaultImageId) {
            $result['imageId'] = $defaultImageId;
            $result['checksum'] = null;
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
