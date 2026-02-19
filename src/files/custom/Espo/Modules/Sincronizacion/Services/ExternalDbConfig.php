<?php
namespace Espo\Modules\Sincronizacion\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Services\Record;
use Espo\ORM\Entity;

class ExternalDbConfig extends Record
{
    protected array $encryptedFields = ['host', 'database', 'username', 'password'];
    
    protected function init()
    {
        parent::init();
    }
    
    public function read(string $id, \Espo\Core\Record\ReadParams $params = null): Entity
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores pueden ver configuraciones de BD externa');
        }
        
        $entity = parent::read($id, $params ?? \Espo\Core\Record\ReadParams::create());
        
        foreach ($this->encryptedFields as $field) {
            if ($field === 'password') continue;
            
            $value = $entity->get($field);
            if (!empty($value) && $this->isEncrypted($value)) {
                try {
                    $decrypted = $this->decrypt($value);
                    $entity->set($field, $decrypted);
                } catch (\Exception $e) {
                }
            }
        }
        
        return $entity;
    }
    
    public function create(\stdClass $data, \Espo\Core\Record\CreateParams $params = null): Entity
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores pueden crear configuraciones de BD externa');
        }
        
        if (isset($data->isActive) && $data->isActive) {
            $this->deactivateOthers();
        }
        
        return parent::create($data, $params ?? \Espo\Core\Record\CreateParams::create());
    }
    
    public function update(string $id, \stdClass $data, \Espo\Core\Record\UpdateParams $params = null): Entity
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Solo administradores pueden modificar configuraciones de BD externa');
        }
        
        if (isset($data->isActive) && $data->isActive) {
            $this->deactivateOthers($id);
        }
        
        return parent::update($id, $data, $params ?? \Espo\Core\Record\UpdateParams::create());
    }
    
    private function deactivateOthers(?string $exceptId = null): void
    {
        $query = $this->entityManager
            ->getRDBRepository('ExternalDbConfig')
            ->where(['isActive' => true]);
        
        if ($exceptId) {
            $query->where(['id!=' => $exceptId]);
        }
        
        $configs = $query->find();
        
        foreach ($configs as $config) {
            $config->set('isActive', false);
            $this->entityManager->saveEntity($config, ['skipHooks' => true]);
        }
    }
    
    public function getActiveConfigDecrypted(): ?array
    {
        $config = $this->entityManager
            ->getRDBRepository('ExternalDbConfig')
            ->where(['isActive' => true])
            ->order('createdAt', 'DESC')
            ->findOne();
        
        if (!$config) {
            return null;
        }
        
        return [
            'id' => $config->getId(),
            'name' => $config->get('name'),
            'host' => $this->decrypt($config->get('host')),
            'port' => $config->get('port'),
            'database' => $this->decrypt($config->get('database')),
            'username' => $this->decrypt($config->get('username')),
            'password' => $this->decrypt($config->get('password')),
            'notificationEmail' => $config->get('notificationEmail')
        ];
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
    
    private function decrypt(string $encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return '';
        }
        
        if (!$this->isEncrypted($encryptedValue)) {
            return $encryptedValue;
        }
        
        try {
            $passwordSalt = $this->config->get('passwordSalt');
            $siteUrl = $this->config->get('siteUrl');
            $secretKey = hash('sha256', $passwordSalt . $siteUrl, true);
            
            $data = base64_decode($encryptedValue, true);
            if ($data === false) {
                return $encryptedValue;
            }
            
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            
            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);
            
            return $decrypted !== false ? $decrypted : $encryptedValue;
        } catch (\Exception $e) {
            throw new \RuntimeException('Error al desencriptar: ' . $e->getMessage());
        }
    }
    
    private function isEncrypted(string $value): bool
    {
        if (empty($value) || strlen($value) < 24) {
            return false;
        }
        
        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) >= 16;
    }
    
    public function updateSyncStatus(string $configId, string $status): void
    {
        $config = $this->entityManager->getEntityById('ExternalDbConfig', $configId);
        
        if ($config) {
            $config->set([
                'lastSync' => date('Y-m-d H:i:s'),
                'lastSyncStatus' => $status
            ]);
            $this->entityManager->saveEntity($config);
        }
    }
}