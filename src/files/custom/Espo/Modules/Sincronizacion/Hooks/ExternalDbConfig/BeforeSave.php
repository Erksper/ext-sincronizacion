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
        error_log('[Hook BeforeSave] Iniciando encriptación...');
        error_log('[Hook BeforeSave] Entity ID: ' . ($entity->getId() ?? 'nuevo'));
        
        // Solo encriptar si el campo cambió
        foreach ($this->encryptedFields as $field) {
            error_log("[Hook BeforeSave] Verificando campo: {$field}");
            
            if ($entity->has($field)) {
                error_log("[Hook BeforeSave] Campo {$field} existe en entity");
                
                if ($entity->isAttributeChanged($field)) {
                    error_log("[Hook BeforeSave] Campo {$field} ha cambiado");
                    
                    $value = $entity->get($field);
                    error_log("[Hook BeforeSave] Valor de {$field}: " . substr($value ?? '', 0, 10) . '...');
                    
                    if (!empty($value) && !$this->isEncrypted($value)) {
                        error_log("[Hook BeforeSave] Encriptando campo {$field}");
                        $encrypted = $this->encrypt($value);
                        $entity->set($field, $encrypted);
                        error_log("[Hook BeforeSave] Campo {$field} encriptado exitosamente");
                    } else {
                        error_log("[Hook BeforeSave] Campo {$field} ya está encriptado o está vacío");
                    }
                } else {
                    error_log("[Hook BeforeSave] Campo {$field} NO ha cambiado");
                }
            } else {
                error_log("[Hook BeforeSave] Campo {$field} NO existe en entity");
            }
        }
        
        error_log('[Hook BeforeSave] Finalizado');
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