<?php
namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Handlers\ImageHandler;
use Espo\Modules\Sincronizacion\Handlers\TeamHandler;
use Espo\Modules\Sincronizacion\Traits\Loggable;
use Espo\Core\Utils\PasswordHash;

class UserHandler
{
    use Loggable;

    private EntityManager $entityManager;
    private ImageHandler $imageHandler;
    private TeamHandler $teamHandler;
    private PasswordHash $passwordHash;
    
    public function __construct(
        EntityManager $entityManager,
        ImageHandler $imageHandler,
        TeamHandler $teamHandler,
        PasswordHash $passwordHash
    ) {
        $this->entityManager = $entityManager;
        $this->imageHandler = $imageHandler;
        $this->teamHandler = $teamHandler;
        $this->passwordHash = $passwordHash;
    }
    
    public function syncUsuarios(
        array $usuariosExternos,
        array $usuariosInactivos,
        array $afiliadosExternos,
        array $rolesExternos,
        string $configId,
        array &$summary
    ): void {
        $afiliadosMap = $this->createAfiliadosMap($afiliadosExternos);
        $rolesMap = $this->createRolesMap($rolesExternos);
        
        $existingUsers = $this->getExistingUsersMap();
        $processedUsers = [];
        
        $loteSize = 50;
        $lotes = array_chunk($usuariosExternos, $loteSize);
        $totalLotes = count($lotes);
        $loteActual = 0;
        $totalUsuarios = count($usuariosExternos);
        
        $this->log('info', 'User', null, 'Sincronización', 'success',
                  "Procesando {$totalUsuarios} usuarios", $configId); 
        
        foreach ($lotes as $lote) {
            $loteActual++;
            
            foreach ($lote as $usuarioExterno) {
                try {
                    $idExterno = $usuarioExterno['id'];
                    $username = $usuarioExterno['username'] ?? 'N/A';
                    
                    if (!$this->validateUserData($usuarioExterno, $summary, $configId)) {
                        continue;
                    }
                    
                    $idAfiliado = $usuarioExterno['idAfiliados'];
                    $userId = (string)$idExterno;
                    
                    if (!isset($afiliadosMap[$idAfiliado])) {
                        $this->log('info', 'User', $userId, "usuario: ({$userId}) {$username}", 'warning',
                                  "Usuario omitido: oficina {$idAfiliado} no encontrada", $configId);
                        $summary['users']['skipped']++;
                        continue;
                    }
                    
                    $afiliado = $afiliadosMap[$idAfiliado];
                    $teamId = $afiliado['licencia'];
                    $zona = $afiliado['zona'];
                    $claId = "CLA{$zona}";
                    
                    if (!$this->teamHandler->teamExists($teamId)) {
                        $this->log('info', 'User', $userId, "id: ({$userId}) {$username}", 'warning',
                                  "Usuario omitido: equipo {$teamId} no existe", $configId);
                        $summary['users']['skipped']++;
                        continue;
                    }
                    
                    if (!$this->teamHandler->teamExists($claId)) {
                        $this->log('info', 'User', $userId, "id: ({$userId}) {$username}", 'warning',
                                  "Usuario omitido: CLA {$claId} no existe", $configId);
                        $summary['users']['skipped']++;
                        continue;
                    }
                    
                    $user = $this->entityManager->getEntityById('User', $userId);
                    
                    if (!$user) {
                        $usernameLower = StringUtils::toLowerCase($usuarioExterno['username']);
                        $userByName = $this->entityManager->getRDBRepository('User')
                            ->where(['userName' => $usernameLower])
                            ->findOne();
                        
                        if ($userByName) {
                            $existingId = $userByName->getId();
                            $this->log('info', 'User', $existingId, "id: ({$existingId}) {$username}", 'warning',
                                      "ID desincronizado - EspoCRM: {$existingId}, 21online: {$userId}", $configId);
                            $user = $userByName;
                        }
                    }
                    
                    if (!$user) {
                        $this->createUser($usuarioExterno, $userId, $teamId, $claId, $rolesMap, $configId, $summary);
                    } else {
                        $this->updateUser($user, $usuarioExterno, $teamId, $claId, $rolesMap, $configId, $summary);
                    }
                    
                    $processedUsers[$userId] = true;
                    
                } catch (\Exception $e) {
                    $summary['users']['errors']++;
                    $username = $usuarioExterno['username'] ?? 'Desconocido';
                    $userId = $usuarioExterno['id'] ?? 'Unknown';
                    $this->log('error', 'User', $userId, "id: ({$userId}) {$username}", 'error',
                              "Error: " . $e->getMessage(), $configId);
                }
            }
        }
        
        $this->deactivateInactiveUsers($usuariosInactivos, $configId, $summary);
        $this->deactivateUsersWithoutTeam($processedUsers, $configId, $summary);
        
        $this->log('info', 'User', null, 'Resumen Usuarios', 'success',
                  "Creados: {$summary['users']['created']} | " .
                  "Actualizados: {$summary['users']['updated']} | " .
                  "Desactivados: {$summary['users']['disabled']} | " .
                  "Sin cambios: {$summary['users']['no_changes']} | " .
                  "Omitidos: {$summary['users']['skipped']} | " .
                  "Errores: {$summary['users']['errors']}", $configId);
    }
    
    private function createUser(
        array $usuarioExterno,
        string $userId,
        string $teamId,
        string $claId,
        array $rolesMap,
        string $configId,
        array &$summary
    ): void {
        $username = $usuarioExterno['username'] ?? 'Unknown';
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            $summary['users']['skipped']++;
            return;
        }
        
        try {
            $user = $this->entityManager->getNewEntity('User');
            $user->set('id', $userId);
            $user->set($userData);
            
            $hashedPassword = $this->passwordHash->hash($usuarioExterno['password']);
            $user->set('password', $hashedPassword);
            
            $this->entityManager->saveEntity($user);
            
            $imageFieldName = $this->imageHandler->getImageFieldName();
            $fotoPath = $usuarioExterno['fotoPath'] ?? null;
            $imageResult = $this->imageHandler->processUserImage($fotoPath, null);
            
            if ($imageResult['imageId']) {
                $user->set($imageFieldName, $imageResult['imageId']);
                $this->entityManager->saveEntity($user);
            }
            
            $this->assignUserToTeams($user, $teamId, $claId);
            
            $summary['users']['created']++;
            $this->log('created', 'User', $userId, "usuario: ({$userId}) {$username}", 'success',
                      "Usuario creado", $configId);
            
        } catch (\Exception $e) {
            $summary['users']['errors']++;
            $this->log('error', 'User', $userId, "id: ({$userId}) {$username}", 'error',
                      "Error al crear: " . $e->getMessage(), $configId);
            throw $e;
        }
    }
    
    private function updateUser(
        $user,
        array $usuarioExterno,
        string $teamId,
        string $claId,
        array $rolesMap,
        string $configId,
        array &$summary
    ): void {
        $username = $user->get('userName');
        $userId = $user->getId();
        $changes = [];
        $needsUpdate = false;
        
        $currentTeamId = $user->get('defaultTeamId');
        
        if ($currentTeamId && !$this->teamHandler->teamExists($currentTeamId)) {
            if ($user->get('isActive')) {
                $user->set('isActive', false);
                $needsUpdate = true;
                $changes[] = "desactivado por equipo inexistente";
                $this->log('info', 'User', $userId, "id: ({$userId}) {$username}", 'warning',
                          "Usuario desactivado porque su equipo no existe", $configId);
            }
        }
        
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            return;
        }
        
        foreach ($userData as $field => $newValue) {
            if ($field === 'rolesIds') {
                continue;
            }
            
            $currentValue = $user->get($field);
            $normalizedCurrent = StringUtils::normalize($currentValue);
            $normalizedNew = StringUtils::normalize($newValue);
            
            if ($normalizedCurrent !== $normalizedNew) {
                $user->set($field, $newValue);
                $needsUpdate = true;
                $changes[] = $field;
            }
        }
        
        $rolesChanged = $this->updateUserRoles($user, $userData['rolesIds'] ?? [], $rolesMap);
        if ($rolesChanged) {
            $needsUpdate = true;
            $changes[] = "roles";
        }
        
        if (!empty($usuarioExterno['password'])) {
            $currentPasswordHash = $user->get('password');
            $plainPassword = $usuarioExterno['password'];
            $passwordChanged = empty($currentPasswordHash) || !password_verify($plainPassword, $currentPasswordHash);
            
            if ($passwordChanged) {
                $hashedPassword = $this->passwordHash->hash($plainPassword);
                $user->set('password', $hashedPassword);
                $needsUpdate = true;
                $changes[] = "password";
            }
        }
        
        $imageFieldName = $this->imageHandler->getImageFieldName();
        $fotoPath = $usuarioExterno['fotoPath'] ?? null;
        $fotoUrlNueva = !empty($fotoPath) ? 'https://venezuela.21online.lat/' . ltrim($fotoPath, '/') : null;
        $fotoUrlActual = $user->get('cFoto');
        
        if ($fotoUrlNueva !== $fotoUrlActual) {
            $imageResult = $this->imageHandler->processUserImage($fotoPath, $user->get($imageFieldName));
            
            if ($imageResult['updated']) {
                $user->set($imageFieldName, $imageResult['imageId']);
                $needsUpdate = true;
                $changes[] = "imagen";
            }
        }
        
        if ($needsUpdate) {
            $this->entityManager->saveEntity($user);
            
            if ($user->get('defaultTeamId') !== $teamId) {
                $this->assignUserToTeams($user, $teamId, $claId);
                $changes[] = "equipos";
            }
            
            $summary['users']['updated']++;
            $changesStr = implode(', ', $changes);
            $this->log('updated', 'User', $userId, "usuario: ({$userId}) {$username}", 'success',
                      "Usuario actualizado: {$changesStr}", $configId);
        } else {
            $summary['users']['no_changes']++;
        }
    }
    
    private function updateUserRoles($user, array $rolesIds21online, array $rolesMap21online): bool
    {
        $currentRoles = $user->get('roles');
        $currentRoleIds = [];
        if ($currentRoles) {
            foreach ($currentRoles as $role) {
                $currentRoleIds[] = $role->getId();
            }
        }
        
        $roles21onlineIds = array_values($rolesMap21online);
        $rolesExtras = array_diff($currentRoleIds, $roles21onlineIds);
        $newRoleIds = array_merge($rolesIds21online, $rolesExtras);
        
        sort($currentRoleIds);
        sort($newRoleIds);
        
        if ($currentRoleIds !== $newRoleIds) {
            $user->set('rolesIds', $newRoleIds);
            return true;
        }
        
        return false;
    }
    
    private function prepareUserData(array $usuarioExterno, string $teamId, array $rolesMap): ?array
    {
        $userName = StringUtils::toLowerCase($usuarioExterno['username']);
        $firstName = !empty($usuarioExterno['nombre']) 
            ? StringUtils::capitalizeWords($usuarioExterno['nombre'])
            : StringUtils::capitalizeWords($usuarioExterno['username']);
        
        $lastName = StringUtils::combineApellidos(
            $usuarioExterno['apellidoP'] ?? null,
            $usuarioExterno['apellidoM'] ?? null
        );
        
        $emailAddress = !empty($usuarioExterno['email'])
            ? StringUtils::toLowerCase($usuarioExterno['email'])
            : null;
        
        $phoneNumber = !empty($usuarioExterno['telMovil']) 
            ? StringUtils::toLowerCase($usuarioExterno['telMovil']) 
            : null;
        
        $team = $this->entityManager->getEntityById('Team', $teamId);
        if (!$team) {
            $this->log('info', 'User', null, $userName, 'warning',
                      "Equipo no encontrado: {$teamId}", null);
            return null;
        }
        
        $roleId = null;
        $puesto = $usuarioExterno['puesto'] ?? null;
        if (!empty($puesto) && isset($rolesMap[$puesto])) {
            $roleId = $rolesMap[$puesto];
        } else {
            $this->log('info', 'User', null, $userName, 'warning',
                      "Rol '{$puesto}' no encontrado", null);
            return null;
        }
        
        $fotoPath = $usuarioExterno['fotoPath'] ?? null;
        $fotoUrl = !empty($fotoPath) ? 'https://venezuela.21online.lat/' . ltrim($fotoPath, '/') : null;
        
        $userData = [
            'userName' => $userName,
            'firstName' => $firstName,
            'defaultTeamId' => $team->getId(),
            'isActive' => true,
            'rolesIds' => [$roleId]
        ];
        
        if (!empty($lastName)) {
            $userData['lastName'] = $lastName;
        }
        
        if (!empty($emailAddress)) {
            $userData['emailAddress'] = $emailAddress;
        }
        
        if (!empty($phoneNumber)) {
            $userData['phoneNumber'] = $phoneNumber;
        }
        
        if ($fotoUrl !== null) {
            $userData['cFoto'] = $fotoUrl;
        }
        
        return $userData;
    }
    
    private function assignUserToTeams($user, string $oficinaId, string $claId): void
    {
        $oficina = $this->entityManager->getEntityById('Team', $oficinaId);
        $cla = $this->entityManager->getEntityById('Team', $claId);
        
        if (!$oficina || !$cla) {
            return;
        }
        
        try {
            $currentTeams = $user->get('teams');
            if ($currentTeams) {
                foreach ($currentTeams as $currentTeam) {
                    $this->entityManager->getRDBRepository('User')
                        ->getRelation($user, 'teams')
                        ->unrelate($currentTeam);
                }
            }
            
            $this->entityManager->getRDBRepository('User')
                ->getRelation($user, 'teams')
                ->relate($oficina);
            
            $this->entityManager->getRDBRepository('User')
                ->getRelation($user, 'teams')
                ->relate($cla);
                
        } catch (\Exception $e) {
        }
    }
    
    private function deactivateInactiveUsers(
        array $usuariosInactivos,
        string $configId,
        array &$summary
    ): void {
        $activeUsernames = [];
        $activeUsers = $this->entityManager->getRDBRepository('User')
            ->where(['isActive' => true])
            ->find();
        
        foreach ($activeUsers as $activeUser) {
            $userId = $activeUser->getId();
            if (is_numeric($userId)) {
                $activeUsernames[$activeUser->get('userName')] = $userId;
            }
        }
        
        $contadorDesactivados = 0;
        
        foreach ($usuariosInactivos as $usuarioInactivo) {
            try {
                $userId = (string)$usuarioInactivo['id'];
                $username = StringUtils::toLowerCase($usuarioInactivo['username'] ?? 'Unknown');
                
                $user = $this->entityManager->getEntityById('User', $userId);
                
                if (!$user) {
                    $user = $this->entityManager->getRDBRepository('User')
                        ->where(['userName' => $username])
                        ->findOne();
                    
                    if ($user && isset($activeUsernames[$username])) {
                        continue;
                    }
                }
                
                if ($user && $user->get('isActive')) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $contadorDesactivados++;
                    $summary['users']['disabled']++;
                    
                    $this->log('disabled', 'User', $user->getId(), "usuario: ({$user->getId()}) {$user->get('userName')}", 'success',
                              "Usuario desactivado (inactivo en 21online)", $configId);
                }
                
            } catch (\Exception $e) {
            }
        }
        
        if ($contadorDesactivados > 0) {
            $this->log('info', 'User', null, 'Desactivados', 'success',
                      "Total usuarios desactivados por inactividad: {$contadorDesactivados}", $configId);
        }
    }
    
    private function deactivateUsersWithoutTeam(
        array $processedUsers,
        string $configId,
        array &$summary
    ): void {
        $contadorDesactivados = 0;
        
        $users = $this->entityManager->getRDBRepository('User')->find();
        
        foreach ($users as $user) {
            $userId = $user->getId();
            
            if (!is_numeric($userId)) {
                continue;
            }
            
            if (isset($processedUsers[$userId])) {
                continue;
            }
            
            $defaultTeamId = $user->get('defaultTeamId');
            
            if (!$defaultTeamId || !$this->teamHandler->teamExists($defaultTeamId)) {
                if ($user->get('isActive')) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $contadorDesactivados++;
                    $summary['users']['disabled']++;
                    
                    $this->log('disabled', 'User', $userId, "usuario: ({$userId}) {$user->get('userName')}", 'success',
                              "Usuario desactivado (equipo no existe)", $configId);
                }
            }
        }
        
        if ($contadorDesactivados > 0) {
            $this->log('info', 'User', null, 'Desactivados', 'success',
                      "Usuarios desactivados por equipo inexistente: {$contadorDesactivados}", $configId);
        }
    }
    
    private function validateUserData(array $usuarioExterno, array &$summary, string $configId): bool
    {
        if (empty($usuarioExterno['id'])) {
            $this->log('info', 'User', null, 'Unknown', 'warning',
                      "Usuario sin ID", $configId);
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['username'])) {
            $this->log('info', 'User', $usuarioExterno['id'], "id: ({$usuarioExterno['id']}) Unknown", 'warning',
                      "Usuario ID {$usuarioExterno['id']} sin username", $configId);
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['idAfiliados'])) {
            $this->log('info', 'User', $usuarioExterno['id'], "id: ({$usuarioExterno['id']}) {$usuarioExterno['username']}", 'warning',
                      "Usuario sin idAfiliados", $configId);
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['password'])) {
            $this->log('info', 'User', $usuarioExterno['id'], "id: ({$usuarioExterno['id']}) {$usuarioExterno['username']}", 'warning',
                      "Usuario sin password", $configId);
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['puesto'])) {
            $this->log('info', 'User', $usuarioExterno['id'], "id: ({$usuarioExterno['id']}) {$usuarioExterno['username']}", 'warning',
                      "Usuario sin puesto", $configId);
            $summary['users']['skipped']++;
            return false;
        }
        
        return true;
    }
    
    private function createAfiliadosMap(array $afiliadosExternos): array
    {
        $map = [];
        foreach ($afiliadosExternos as $afiliado) {
            if (!empty($afiliado['licencia'])) {
                $map[$afiliado['licencia']] = $afiliado;
            }
        }
        return $map;
    }
    
    private function createRolesMap(array $rolesExternos): array
    {
        $map = [];
        $roles = $this->entityManager->getRDBRepository('Role')->find();
        
        foreach ($roles as $role) {
            $roleName = $role->get('name');
            if (in_array($roleName, $rolesExternos)) {
                $map[$roleName] = $role->getId();
            }
        }
        
        return $map;
    }
    
    private function getExistingUsersMap(): array
    {
        $map = [];
        
        try {
            $users = $this->entityManager->getRDBRepository('User')->find();
            
            foreach ($users as $user) {
                $userId = $user->getId();
                if (is_numeric($userId)) {
                    $map[$userId] = $user;
                }
            }
        } catch (\Exception $e) {
        }
        
        return $map;
    }
}