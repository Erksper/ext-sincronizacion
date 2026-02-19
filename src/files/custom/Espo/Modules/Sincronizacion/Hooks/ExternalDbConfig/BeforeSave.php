<?php
namespace Espo\Modules\Sincronizacion\Hooks\ExternalDbConfig;

use Espo\Core\Hook\Hook\BeforeSave as BeforeSaveHook;
use Espo\ORM\Entity;
use Espo\Core\Utils\Config;
use Espo\ORM\Repository\Option\SaveOptions;

class BeforeSave implements BeforeSaveHook
{
    private array $encryptedFields = ['host', 'database', 'username', 'password'];
    
    public function __construct(private Config $config)
    {
    }
    
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        foreach ($this->encryptedFields as $field) {
            if ($entity->has($field) && $entity->isAttributeChanged($field)) {
                $value = $entity->get($field);
                
                if (!empty($value) && !$this->isEncrypted($value)) {
                    $encrypted = $this->encrypt($value);
                    $entity->set($field, $encrypted);
                }
            }
        }
    }
    
    private function encrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        $passwordSalt = $this->config->get('passwordSalt');
        $siteUrl = $this->config->get('siteUrl');
        $secretKey = hash('sha256', $passwordSalt . $siteUrl, true);
        
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Error al encriptar datos');
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    private function isEncrypted(string $value): bool
    {
        if (empty($value) || strlen($value) < 24) {
            return false;
        }
        
        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) >= 16;
    }
}