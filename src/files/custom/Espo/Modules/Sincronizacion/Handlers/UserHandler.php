<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Handlers\ImageHandler;
use Espo\Modules\Sincronizacion\Handlers\TeamHandler;
use Espo\Core\Utils\PasswordHash;

/**
 * Manejador de sincronización de usuarios
 */
class UserHandler
{
    private EntityManager $entityManager;
    private ImageHandler $imageHandler;
    private TeamHandler $teamHandler;
    private PasswordHash $passwordHash;
    private array $incidencias = [];
    
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
    
    /**
     * Logging dual: error_log + EspoCRM Log global
     */
    private function logInfo(string $message): void
    {
        error_log($message);
        if (isset($GLOBALS['log'])) {
            $GLOBALS['log']->info($message);
        }
    }
    
    private function logError(string $message): void
    {
        error_log($message);
        if (isset($GLOBALS['log'])) {
            $GLOBALS['log']->error($message);
        }
    }
    
    /**
     * Sincronizar usuarios desde BD externa
     */
    public function syncUsuarios(
        array $usuariosExternos,
        array $usuariosInactivos,     
        array $afiliadosExternos,
        array $rolesExternos,
        string $configId,
        array &$summary
    ): void {
        error_log('[UserHandler] === Sincronizando Usuarios ===');
        error_log('[UserHandler] Total usuarios externos a procesar: ' . count($usuariosExternos));
        
        // Crear mapeos para búsqueda rápida
        $afiliadosMap = $this->createAfiliadosMap($afiliadosExternos);
        $rolesMap = $this->createRolesMap($rolesExternos);
        
        error_log('[UserHandler] Afiliados en mapa: ' . count($afiliadosMap));
        error_log('[UserHandler] Roles en mapa: ' . count($rolesMap));
        
        // Obtener usuarios existentes
        $existingUsers = $this->getExistingUsersMap();

        $this->logInfo('[UserHandler] Usuarios existentes en mapa: ' . count($existingUsers));
        error_log('[UserHandler] Usuarios existentes en mapa: ' . count($existingUsers));

        $processedUsers = [];
        $contador = 0;
        
        foreach ($usuariosExternos as $usuarioExterno) {
            try {
                $contador++;
                $idExterno = $usuarioExterno['id'];
                $username = $usuarioExterno['username'] ?? 'N/A';
                
                error_log("[UserHandler] ========================================");
                error_log("[UserHandler] Procesando usuario {$contador}/" . count($usuariosExternos) . ": ID={$idExterno}, Username={$username}");
                
                $processedUsers[$idExterno] = true;
                
                // Validar datos básicos
                if (!$this->validateUserData($usuarioExterno, $summary)) {
                    error_log("[UserHandler] Usuario {$username} NO pasó validación básica");
                    continue;
                }
                
                error_log("[UserHandler] Usuario {$username} pasó validación básica");
                
                $idAfiliado = $usuarioExterno['idAfiliados'];
                $externalId = (string)$idExterno;
                
                // Normalizar username para búsqueda
                $usernameLower = strtolower(trim($usuarioExterno['username']));
                
                error_log("[UserHandler] Usuario {$username}: idAfiliado={$idAfiliado}, externalId={$externalId}");
                
                // Validar que existe el afiliado
                if (!isset($afiliadosMap[$idAfiliado])) {
                    error_log("[UserHandler] ERROR: Afiliado {$idAfiliado} no encontrado en mapa");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "Usuario '{$username}' referencia afiliado inexistente: {$idAfiliado}");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                $afiliado = $afiliadosMap[$idAfiliado];
                $teamId = $afiliado['licencia']; // ID directo de la oficina
                $zona = $afiliado['zona'];
                $claId = "CLA{$zona}"; // ID del CLA
                
                error_log("[UserHandler] Afiliado encontrado: Licencia={$afiliado['licencia']}, TeamId={$teamId}, Zona={$zona}, CLAId={$claId}");
                
                // Verificar que el equipo (oficina) existe
                if (!$this->teamHandler->teamExists($teamId)) {
                    error_log("[UserHandler] ERROR: Equipo (oficina) {$teamId} no existe");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "Equipo no encontrado para usuario '{$username}' (Afiliado: {$idAfiliado})");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                // Verificar que el CLA existe
                if (!$this->teamHandler->teamExists($claId)) {
                    error_log("[UserHandler] ERROR: CLA {$claId} no existe");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "CLA no encontrado para usuario '{$username}' (Zona: {$zona})");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                error_log("[UserHandler] Equipo {$teamId} y CLA {$claId} existen");
                $this->logInfo("[UserHandler] Equipo {$teamId} y CLA {$claId} existen");
                
                // Buscar usuario existente por externalId
                $user = $existingUsers[$externalId] ?? null;
                
                if (!$user) {
                    // Si no se encontró por externalId, buscar por userName para evitar duplicados
                    $this->logInfo("[UserHandler] Usuario NO encontrado por externalId, buscando por userName...");
                    
                    $usernameLower = StringUtils::toLowerCase($usuarioExterno['username']);
                    $userByName = $this->entityManager->getRDBRepository('User')
                        ->where(['userName' => $usernameLower])
                        ->findOne();
                    
                    if ($userByName) {
                        $this->logInfo("[UserHandler] ¡Usuario ENCONTRADO por userName! Asignando externalId...");
                        
                        // Usuario existe pero sin externalId - asignar externalId
                        $userByName->set('externalId', $externalId);
                        $this->entityManager->saveEntity($userByName);
                        
                        // Usar este usuario para actualizar
                        $user = $userByName;
                        $this->logInfo("[UserHandler] ExternalId asignado a usuario existente: {$externalId}");
                    }
                }
                
                if (!$user) {
                    error_log("[UserHandler] Usuario {$username} NO existe, creando nuevo...");
                    $this->logInfo("[UserHandler] Usuario {$username} NO existe, creando nuevo...");
                    // Crear nuevo usuario
                    $this->createUser($usuarioExterno, $teamId, $claId, $rolesMap, $configId, $summary);
                } else {
                    error_log("[UserHandler] Usuario {$username} existe (ID: {$user->getId()}), actualizando...");
                    // Actualizar usuario existente
                    $this->updateUser($user, $usuarioExterno, $teamId, $claId, $rolesMap, $configId, $summary);
                }
                
            } catch (\Exception $e) {
                $summary['users']['errors']++;
                $username = $usuarioExterno['username'] ?? 'Desconocido';
                $mensaje = "Error sincronizando usuario '{$username}': " . $e->getMessage();
                error_log("[UserHandler] ERROR: {$mensaje}");
                error_log("[UserHandler] Stack trace: " . $e->getTraceAsString());
                $this->addIncidencia('sync_error', 'User', null, $username, $mensaje);
                $this->addLog('error', 'User', null, $username, 'error', $mensaje, $configId);
            }
        }
        
        error_log('[UserHandler] Finalizando sincronización de usuarios');
        error_log('[UserHandler] Usuarios procesados exitosamente: ' . count($processedUsers));
        
        // Desactivar usuarios que ya no están en BD externa o cuyo equipo no existe
        $this->deactivateObsoleteUsers($existingUsers, $processedUsers, $configId, $summary);
    }
    
    /**
     * Crear nuevo usuario
     */
    private function createUser(
        array $usuarioExterno,
        string $teamId,
        string $claId,
        array $rolesMap,
        string $configId,
        array &$summary
    ): void {
        $externalId = "USR_{$usuarioExterno['id']}";
        $username = $usuarioExterno['username'] ?? 'Unknown';
        
        $this->logInfo("[UserHandler] === CREANDO NUEVO USUARIO ===");
        $this->logInfo("[UserHandler] ID Externo: {$externalId}");
        $this->logInfo("[UserHandler] Username: {$username}");
        
        // Preparar datos del usuario
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            $this->logError("[UserHandler] ERROR: No se pudieron preparar datos del usuario");
            $summary['users']['skipped']++;
            return;
        }
        
        $this->logInfo("[UserHandler] Datos preparados: userName={$userData['userName']}, firstName={$userData['firstName']}, lastName={$userData['lastName']}");
        
        // VERIFICAR NUEVAMENTE que no exista por userName antes de crear
        $existingUser = $this->entityManager->getRDBRepository('User')
            ->where(['userName' => $userData['userName']])
            ->findOne();
        
        if ($existingUser) {
            $this->logError("[UserHandler] ERROR CRÍTICO: Usuario con userName '{$userData['userName']}' YA EXISTE!");
            $this->logError("[UserHandler] ID del usuario existente: " . $existingUser->getId());
            $this->logError("[UserHandler] ExternalId del usuario existente: " . ($existingUser->get('externalId') ?? 'NULL'));
            
            // Asignar externalId y actualizar en lugar de crear
            $existingUser->set('externalId', $externalId);
            $this->entityManager->saveEntity($existingUser);
            
            $this->logInfo("[UserHandler] Convirtiendo a actualización en lugar de creación");
            $this->updateUser($existingUser, $usuarioExterno, $teamId, $claId, $rolesMap, $configId, $summary);
            return;
        }
        
        $this->logInfo("[UserHandler] Verificación OK: userName '{$userData['userName']}' no existe, procediendo a crear...");
        
        // Crear usuario
        $user = $this->entityManager->getNewEntity('User');
        $user->set($userData);
        
        // Establecer contraseña
        $hashedPassword = $this->passwordHash->hash($usuarioExterno['password']);
        $user->set('password', $hashedPassword);
        
        $this->logInfo("[UserHandler] Guardando usuario en BD...");
        
        try {
            $this->entityManager->saveEntity($user);
            $this->logInfo("[UserHandler] ✓ Usuario guardado exitosamente con ID: " . $user->getId());
        } catch (\Exception $e) {
            $this->logError("[UserHandler] ERROR al guardar usuario: " . $e->getMessage());
            throw $e;
        }
        
        // Asignar a AMBOS equipos: oficina (por defecto) y CLA
        $this->assignUserToTeams($user, $teamId, $claId);
        
        $summary['users']['created']++;
        $this->addLog('created', 'User', $user->getId(), $userData['userName'], 'success',
                     "Usuario '{$userData['userName']}' creado", $configId);
        error_log("[UserHandler] Usuario creado: {$userData['userName']}");
        $this->logInfo("[UserHandler] ✓✓✓ Usuario creado exitosamente: {$userData['userName']}");
    }
    
    /**
     * Actualizar usuario existente
     */
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
        error_log("[UserHandler] --- Actualizando usuario: {$username} ---");
        
        $changes = [];
        $needsUpdate = false;
        
        // Verificar que el equipo del usuario existe
        $currentTeamId = $user->get('defaultTeamId');
        error_log("[UserHandler] defaultTeamId actual: " . ($currentTeamId ?? 'NULL'));
        
        if ($currentTeamId) {
            // Si el equipo actual no existe, desactivar usuario
            if (!$this->teamHandler->teamExists($currentTeamId)) {
                error_log("[UserHandler] ¡Equipo del usuario NO existe! Desactivando usuario");
                if ($user->get('isActive')) {
                    $user->set('isActive', false);
                    $needsUpdate = true;
                    $changes[] = "desactivado por equipo inexistente";
                    $this->addIncidencia('missing_team', 'User', $user->getId(), $username,
                                       "Usuario desactivado porque su equipo no existe");
                }
            }
        }
        
        // Preparar datos actualizados
        error_log("[UserHandler] Preparando datos actualizados...");
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            error_log("[UserHandler] ERROR: No se pudieron preparar datos del usuario");
            return;
        }
        
        error_log("[UserHandler] Datos preparados correctamente");
        
        // Comparar campos
        foreach ($userData as $field => $newValue) {
            $currentValue = $user->get($field);
            
            // Manejar campos que son arrays (como rolesIds)
            if (is_array($newValue)) {
                error_log("[UserHandler] Comparando campo array '{$field}'");
                
                // Comparar arrays
                $currentArray = is_array($currentValue) ? $currentValue : [];
                $newArray = $newValue;
                
                sort($currentArray);
                sort($newArray);
                
                if ($currentArray !== $newArray) {
                    $user->set($field, $newValue);
                    $needsUpdate = true;
                    $changes[] = "{$field} actualizado";
                    error_log("[UserHandler] ✓ Cambio detectado en {$field}");
                }
                continue;
            }
            
            // Normalizar para comparación (solo strings)
            $normalizedCurrent = StringUtils::normalize($currentValue);
            $normalizedNew = StringUtils::normalize($newValue);
            
            error_log("[UserHandler] Comparando campo '{$field}': '{$normalizedCurrent}' vs '{$normalizedNew}'");
            
            if ($normalizedCurrent !== $normalizedNew) {
                $user->set($field, $newValue);
                $needsUpdate = true;
                $changes[] = "{$field}: '{$currentValue}' -> '{$newValue}'";
                error_log("[UserHandler] ✓ Cambio detectado en {$field}");
            }
        }
        
        // Verificar contraseña
        if (!empty($usuarioExterno['password'])) {
            $currentPasswordHash = $user->get('password');
            $newPassword = $usuarioExterno['password'];
            
            error_log("[UserHandler] Verificando contraseña...");
            
            // Verificar si la contraseña cambió
            if (!$this->passwordHash->check($newPassword, $currentPasswordHash)) {
                error_log("[UserHandler] ✓ Contraseña cambió, actualizando hash");
                $hashedPassword = $this->passwordHash->hash($newPassword);
                $user->set('password', $hashedPassword);
                $needsUpdate = true;
                $changes[] = "contraseña actualizada";
            } else {
                error_log("[UserHandler] Contraseña sin cambios");
            }
        }
        
        // Verificar imagen
        error_log("[UserHandler] Verificando imagen...");
        $imageResult = $this->imageHandler->processUserImage(
            $usuarioExterno['fotoPath'] ?? null,
            $user->get('cImageId')
        );
        
        if ($imageResult['updated']) {
            error_log("[UserHandler] ✓ Imagen actualizada");
            $user->set('cImageId', $imageResult['imageId']);
            $needsUpdate = true;
            $changes[] = "imagen actualizada";
        } else {
            error_log("[UserHandler] Imagen sin cambios");
        }
        
        // Guardar si hay cambios
        if ($needsUpdate) {
            error_log("[UserHandler] Guardando cambios en usuario {$username}...");
            $this->entityManager->saveEntity($user);
            
            // Verificar si cambió de equipo
            $currentDefaultTeam = $user->get('defaultTeamId');
            
            if ($currentDefaultTeam !== $teamId) {
                error_log("[UserHandler] Cambiando equipos del usuario...");
                $this->assignUserToTeams($user, $teamId, $claId);
                $changes[] = "equipos actualizados";
            }
            
            $summary['users']['updated']++;
            $changesStr = implode(', ', $changes);
            $this->addLog('updated', 'User', $user->getId(), $username, 'success',
                         "Usuario actualizado: {$changesStr}", $configId);
            error_log("[UserHandler] ✓ Usuario actualizado exitosamente: {$username} ({$changesStr})");
        } else {
            $summary['users']['no_changes']++;
            error_log("[UserHandler] Usuario {$username} sin cambios necesarios");
        }
    }
    
    /**
     * Preparar datos de usuario formateados correctamente
     */
    private function prepareUserData(array $usuarioExterno, string $teamId, array $rolesMap): ?array
    {
        // Formatear nombre y apellidos
        $firstName = StringUtils::capitalizeWords($usuarioExterno['nombre']);
        $lastName = StringUtils::combineApellidos(
            $usuarioExterno['apellidoP'] ?? null,
            $usuarioExterno['apellidoM'] ?? null
        );
        
        if (empty($firstName) || empty($lastName)) {
            $this->addIncidencia('validation_error', 'User', null, $usuarioExterno['username'] ?? 'Unknown',
                               "Usuario sin nombre o apellido completo");
            return null;
        }
        
        // Formatear campos en minúsculas
        $userName = StringUtils::toLowerCase($usuarioExterno['username']);
        $emailAddress = StringUtils::toLowerCase($usuarioExterno['email']);
        $phoneNumber = StringUtils::toLowerCase($usuarioExterno['telMovil'] ?? null);
        
        // Obtener equipo
        $team = $this->entityManager->getEntityById('Team', $teamId);
        if (!$team) {
            $this->addIncidencia('missing_team', 'User', null, $userName,
                               "Equipo no encontrado: {$teamId}");
            return null;
        }
        
        // Obtener rol
        $roleId = null;
        $puesto = $usuarioExterno['puesto'] ?? null;
        if (!empty($puesto) && isset($rolesMap[$puesto])) {
            $roleId = $rolesMap[$puesto];
        }
        
        // Procesar imagen
        $imageResult = $this->imageHandler->processUserImage(
            $usuarioExterno['fotoPath'] ?? null,
            null // Para usuarios nuevos no hay imagen actual
        );
        
        $userData = [
            'externalId' => "USR_{$usuarioExterno['id']}",
            'userName' => $userName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'emailAddress' => $emailAddress,
            'defaultTeamId' => $team->getId(),
            'isActive' => true
        ];
        
        if (!empty($phoneNumber)) {
            $userData['phoneNumber'] = $phoneNumber;
        }
        
        if ($roleId) {
            $userData['rolesIds'] = [$roleId];
        }
        
        if ($imageResult['imageId']) {
            $userData['cImageId'] = $imageResult['imageId'];
        }
        
        return $userData;
    }
    
    /**
     * Asignar usuario a DOS equipos: oficina (por defecto) y CLA
     */
    private function assignUserToTeams($user, string $oficinaId, string $claId): void
    {
        error_log("[UserHandler] Asignando usuario a oficina {$oficinaId} y CLA {$claId}");
        
        $oficina = $this->entityManager->getEntityById('Team', $oficinaId);
        $cla = $this->entityManager->getEntityById('Team', $claId);
        
        if (!$oficina || !$cla) {
            error_log("[UserHandler] ERROR: No se pudo obtener oficina o CLA");
            return;
        }
        
        try {
            // Limpiar equipos anteriores
            $currentTeams = $user->get('teams');
            if ($currentTeams) {
                foreach ($currentTeams as $currentTeam) {
                    $this->entityManager->getRDBRepository('User')
                        ->getRelation($user, 'teams')
                        ->unrelate($currentTeam);
                }
            }
            
            // Asignar oficina (equipo por defecto)
            $this->entityManager->getRDBRepository('User')
                ->getRelation($user, 'teams')
                ->relate($oficina);
            
            error_log("[UserHandler] ✓ Usuario asignado a oficina {$oficinaId}");
            
            // Asignar CLA
            $this->entityManager->getRDBRepository('User')
                ->getRelation($user, 'teams')
                ->relate($cla);
            
            error_log("[UserHandler] ✓ Usuario asignado a CLA {$claId}");
                
        } catch (\Exception $e) {
            error_log("[UserHandler] Error asignando equipos: " . $e->getMessage());
        }
    }
    
    /**
     * Desactivar usuarios obsoletos o sin equipo
     */
    private function deactivateObsoleteUsers(
        array $existingUsersData,
        array $processedUsers,
        string $configId,
        array &$summary
    ): void {
        $existingUsersByExternalId = $existingUsersData['byExternalId'];
        
        foreach ($existingUsersByExternalId as $externalId => $user) {
            $userId = str_replace('USR_', '', $externalId);
            
            if (!isset($processedUsers[$userId])) {
                // Usuario ya no existe en BD externa
                if ($user->get('isActive')) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $summary['users']['disabled']++;
                    $this->addLog('disabled', 'User', $user->getId(), $user->get('userName'), 'success',
                                 "Usuario desactivado (no existe en BD externa)", $configId);
                    error_log("[UserHandler] Usuario desactivado: {$user->get('userName')}");
                }
            } else {
                // Verificar que su equipo existe
                $defaultTeamId = $user->get('defaultTeamId');
                if ($defaultTeamId) {
                    if (!$this->teamHandler->teamExists($defaultTeamId)) {
                        if ($user->get('isActive')) {
                            $user->set('isActive', false);
                            $this->entityManager->saveEntity($user);
                            
                            $summary['users']['disabled']++;
                            $this->addLog('disabled', 'User', $user->getId(), $user->get('userName'), 'success',
                                         "Usuario desactivado (equipo no existe)", $configId);
                            error_log("[UserHandler] Usuario desactivado por equipo inexistente: {$user->get('userName')}");
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Validar datos básicos del usuario
     */
    private function validateUserData(array $usuarioExterno, array &$summary): bool
    {
        if (empty($usuarioExterno['username'])) {
            $this->addIncidencia('validation_error', 'User', null, 'Unknown',
                               "Usuario sin nombre de usuario (username)");
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['email'])) {
            $this->addIncidencia('validation_error', 'User', null, $usuarioExterno['username'],
                               "Usuario '{$usuarioExterno['username']}' sin email");
            $summary['users']['skipped']++;
            return false;
        }
        
        if (empty($usuarioExterno['nombre'])) {
            $this->addIncidencia('validation_error', 'User', null, $usuarioExterno['username'],
                               "Usuario '{$usuarioExterno['username']}' sin nombre");
            $summary['users']['skipped']++;
            return false;
        }
        
        return true;
    }
    
    /**
     * Crear mapa de afiliados para búsqueda rápida
     */
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
    
    /**
     * Crear mapa de roles para búsqueda rápida
     */
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
    
    /**
     * Obtener mapa de usuarios existentes SOLO por externalId
     */
    private function getExistingUsersMap(): array
    {
        $this->logInfo('[UserHandler] Obteniendo usuarios existentes...');
        
        $map = [];
        
        try {
            $users = $this->entityManager->getRDBRepository('User')
                ->where(['externalId!=' => null])
                ->find();
            
            $this->logInfo('[UserHandler] Usuarios con externalId encontrados: ' . count($users));
            
            foreach ($users as $user) {
                $externalId = $user->get('externalId');
                if ($externalId) {
                    $map[$externalId] = $user;
                }
            }
            
            $this->logInfo('[UserHandler] Mapa de usuarios creado: ' . count($map) . ' usuarios');
            
        } catch (\Exception $e) {
            $this->logError('[UserHandler] ERROR en getExistingUsersMap: ' . $e->getMessage());
        }
        
        return $map;
    }
    
    /**
     * Agregar incidencia
     */
    private function addIncidencia(string $tipo, string $entityType, ?string $entityId, string $entityName, string $mensaje): void
    {
        $this->incidencias[] = [
            'tipo' => $tipo,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'entityName' => $entityName,
            'mensaje' => $mensaje
        ];
    }
    
    /**
     * Obtener incidencias acumuladas
     */
    public function getIncidencias(): array
    {
        return $this->incidencias;
    }
    
    /**
     * Agregar log de sincronización
     */
    private function addLog(string $action, string $entityType, ?string $entityId, string $entityName,
                           string $status, string $message, ?string $configId = null): void
    {
        try {
            $log = $this->entityManager->getNewEntity('SyncLog');
            $log->set([
                'name' => "{$entityType}: {$entityName}",
                'syncDate' => date('Y-m-d H:i:s'),
                'entityType' => $entityType,
                'entityId' => $entityId,
                'entityName' => $entityName,
                'action' => $action,
                'status' => $status,
                'message' => $message
            ]);
            
            if ($configId) {
                $log->set('configId', $configId);
            }
            
            $this->entityManager->saveEntity($log);
        } catch (\Exception $e) {
            error_log('[UserHandler] Error creando log: ' . $e->getMessage());
        }
    }
    
    /**
     * Desactivar usuarios que están inactivos en BD externa
     */
    private function deactivateInactiveUsers(
        array $usuariosInactivos,
        array $existingUsers,
        string $configId,
        array &$summary
    ): void {
        $this->logInfo('[UserHandler] Desactivando usuarios inactivos de BD externa...');
        error_log('[UserHandler] Desactivando usuarios inactivos de BD externa...');
        
        $contadorDesactivados = 0;
        
        foreach ($usuariosInactivos as $usuarioInactivo) {
            try {
                $idExterno = $usuarioInactivo['id'];
                $externalId = "USR_{$idExterno}";
                $username = $usuarioInactivo['username'] ?? 'Unknown';
                
                // Buscar si existe en EspoCRM
                if (isset($existingUsers[$externalId])) {
                    $user = $existingUsers[$externalId];
                    
                    // Si está activo, desactivarlo
                    if ($user->get('isActive')) {
                        $user->set('isActive', false);
                        $this->entityManager->saveEntity($user);
                        
                        $contadorDesactivados++;
                        $summary['users']['disabled']++;
                        
                        $this->addLog('disabled', 'User', $user->getId(), $user->get('userName'), 'success',
                                     "Usuario desactivado (inactivo en BD externa)", $configId);
                        error_log("[UserHandler] ✓ Usuario desactivado: {$user->get('userName')} (inactivo en BD externa)");
                        $this->logInfo("[UserHandler] ✓ Usuario desactivado: {$user->get('userName')} (inactivo en BD externa)");
                    } else {
                        error_log("[UserHandler] Usuario {$username} ya está inactivo");
                    }
                } else {
                    error_log("[UserHandler] Usuario inactivo {$username} no existe en EspoCRM (se ignora)");
                }
                
            } catch (\Exception $e) {
                error_log("[UserHandler] ERROR desactivando usuario inactivo: " . $e->getMessage());
                $this->logError("[UserHandler] ERROR desactivando usuario inactivo: " . $e->getMessage());
            }
        }
        
        $mensaje = "[UserHandler] Total usuarios desactivados por estar inactivos en BD externa: {$contadorDesactivados}";
        error_log($mensaje);
        $this->logInfo($mensaje);
    }
}