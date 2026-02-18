<?php

namespace Espo\Modules\Sincronizacion\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\Sincronizacion\Utils\StringUtils;
use Espo\Modules\Sincronizacion\Handlers\ImageHandler;
use Espo\Modules\Sincronizacion\Handlers\TeamHandler;
use Espo\Core\Utils\PasswordHash;

/**
 * Manejador de sincronización de usuarios
 * 
 * MODELO DE SINCRONIZACIÓN:
 * - Usuario en 21online con id=3147 → Usuario en EspoCRM con id="3147"
 * - NO se usa campo externalId
 * - Búsqueda por ID directo, fallback a userName para detectar conflictos
 * - Solo se tocan usuarios con IDs numéricos (de 21online)
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
        error_log('[UserHandler] Total usuarios inactivos: ' . count($usuariosInactivos));
        
        // Crear mapeos para búsqueda rápida
        $afiliadosMap = $this->createAfiliadosMap($afiliadosExternos);
        $rolesMap = $this->createRolesMap($rolesExternos);
        
        error_log('[UserHandler] Afiliados en mapa: ' . count($afiliadosMap));
        error_log('[UserHandler] Roles en mapa: ' . count($rolesMap));
        
        // Obtener usuarios existentes (solo los de 21online - IDs numéricos)
        $existingUsers = $this->getExistingUsersMap();

        $this->logInfo('[UserHandler] Usuarios existentes de 21online: ' . count($existingUsers));

        $processedUsers = []; // Lista de IDs procesados exitosamente
        $contador = 0;
        $totalUsuarios = count($usuariosExternos);
        
        // Procesar en lotes para evitar timeout
        $loteSize = 50;
        $lotes = array_chunk($usuariosExternos, $loteSize);
        $totalLotes = count($lotes);
        $loteActual = 0;
        
        error_log("[UserHandler] Procesando {$totalUsuarios} usuarios en {$totalLotes} lotes de {$loteSize}");
        
        // PASO 1: Procesar usuarios ACTIVOS de 21online
        foreach ($lotes as $lote) {
            $loteActual++;
            error_log("[UserHandler] === LOTE {$loteActual}/{$totalLotes} ===");
            
            foreach ($lote as $usuarioExterno) {
                try {
                    $contador++;
                    $idExterno = $usuarioExterno['id'];
                    $username = $usuarioExterno['username'] ?? 'N/A';
                    
                    error_log("[UserHandler] Procesando usuario {$contador}/{$totalUsuarios}: ID={$idExterno}, Username={$username}");
                    
                    // Validar datos básicos
                if (!$this->validateUserData($usuarioExterno, $summary)) {
                    error_log("[UserHandler] ✗ Usuario {$username} RECHAZADO por validación");
                    continue;
                }
                
                $idAfiliado = $usuarioExterno['idAfiliados'];
                $userId = (string)$idExterno; // ID que se usará en EspoCRM
                
                // Validar que existe el afiliado
                if (!isset($afiliadosMap[$idAfiliado])) {
                    error_log("[UserHandler] ✗ Usuario {$username} RECHAZADO: Afiliado {$idAfiliado} no encontrado");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "Usuario '{$username}' referencia afiliado inexistente: {$idAfiliado}");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                $afiliado = $afiliadosMap[$idAfiliado];
                $teamId = $afiliado['licencia'];
                $zona = $afiliado['zona'];
                $claId = "CLA{$zona}";
                
                // Verificar que el equipo (oficina) existe
                if (!$this->teamHandler->teamExists($teamId)) {
                    error_log("[UserHandler] ✗ Usuario {$username} RECHAZADO: Equipo {$teamId} NO EXISTE");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "Equipo no encontrado para usuario '{$username}'");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                // Verificar que el CLA existe
                if (!$this->teamHandler->teamExists($claId)) {
                    error_log("[UserHandler] ✗ Usuario {$username} RECHAZADO: CLA {$claId} NO EXISTE");
                    $this->addIncidencia('missing_team', 'User', null, $username,
                                       "CLA no encontrado para usuario '{$username}'");
                    $summary['users']['skipped']++;
                    continue;
                }
                
                // Buscar usuario existente por ID directo
                $user = $this->entityManager->getEntityById('User', $userId);
                
                if (!$user) {
                    // No existe por ID → buscar por userName para detectar conflictos
                    $usernameLower = StringUtils::toLowerCase($usuarioExterno['username']);
                    $userByName = $this->entityManager->getRDBRepository('User')
                        ->where(['userName' => $usernameLower])
                        ->findOne();
                    
                    if ($userByName) {
                        // WARNING: Usuario existe con userName pero ID diferente
                        $existingId = $userByName->getId();
                        error_log("[UserHandler] ⚠ WARNING: ID desincronizado detectado!");
                        error_log("[UserHandler]   Username: {$username}");
                        error_log("[UserHandler]   ID en EspoCRM: {$existingId}");
                        error_log("[UserHandler]   ID en 21online: {$userId}");
                        
                        $this->addIncidencia('id_mismatch', 'User', $existingId, $username,
                            "Usuario con ID desincronizado - EspoCRM: {$existingId}, 21online: {$userId} - Requiere corrección manual en BD");
                        
                        // Usar el usuario encontrado para actualizar
                        $user = $userByName;
                    }
                }
                
                if (!$user) {
                    // Crear nuevo usuario
                    $this->createUser($usuarioExterno, $userId, $teamId, $claId, $rolesMap, $configId, $summary);
                } else {
                    // Actualizar usuario existente
                    $this->updateUser($user, $usuarioExterno, $teamId, $claId, $rolesMap, $configId, $summary);
                }
                
                // Marcar como procesado
                $processedUsers[$userId] = true;
                
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
            
            // Pausa entre lotes para evitar timeout
            if ($loteActual < $totalLotes) {
                error_log("[UserHandler] === Lote {$loteActual}/{$totalLotes} completado ===");
                sleep(1);
            }
        }
        
        error_log('[UserHandler] Usuarios activos procesados: ' . count($processedUsers) . '/' . $totalUsuarios);
        error_log('[UserHandler] ========================================');
        error_log('[UserHandler] RESUMEN DE SINCRONIZACIÓN:');
        error_log('[UserHandler] - Total usuarios en 21online: ' . $totalUsuarios);
        error_log('[UserHandler] - Procesados exitosamente: ' . count($processedUsers));
        error_log('[UserHandler] - Rechazados/Omitidos: ' . ($totalUsuarios - count($processedUsers)));
        error_log('[UserHandler] - Creados: ' . ($summary['users']['created'] ?? 0));
        error_log('[UserHandler] - Actualizados: ' . ($summary['users']['updated'] ?? 0));
        error_log('[UserHandler] - Sin cambios: ' . ($summary['users']['no_changes'] ?? 0));
        error_log('[UserHandler] - Errores: ' . ($summary['users']['errors'] ?? 0));
        error_log('[UserHandler] ========================================');
        
        // PASO 2: Desactivar usuarios INACTIVOS en 21online
        $this->deactivateInactiveUsers($usuariosInactivos, $configId, $summary);
        
        // PASO 3 (ÚLTIMO): Desactivar usuarios sin equipo válido
        $this->deactivateUsersWithoutTeam($processedUsers, $configId, $summary);
    }
    
    /**
     * Crear nuevo usuario con ID de 21online
     */
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
        
        $this->logInfo("[UserHandler] Creando usuario: {$username} (ID: {$userId})");
        
        // Preparar datos del usuario
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            $this->logError("[UserHandler] ERROR: No se pudieron preparar datos del usuario");
            $summary['users']['skipped']++;
            return;
        }
        
        // Crear usuario con ID de 21online
        $user = $this->entityManager->getNewEntity('User');
        $user->set('id', $userId); // CRÍTICO: Asignar ID de 21online
        $user->set($userData);
        
        // Establecer contraseña
        $hashedPassword = $this->passwordHash->hash($usuarioExterno['password']);
        $user->set('password', $hashedPassword);
        
        try {
            $this->entityManager->saveEntity($user);
            $this->logInfo("[UserHandler] ✓ Usuario creado con ID: {$userId}");
        } catch (\Exception $e) {
            $this->logError("[UserHandler] ERROR CRÍTICO al guardar usuario: " . $e->getMessage());
            $this->logError("[UserHandler] Usuario: {$username}, ID: {$userId}");
            $this->logError("[UserHandler] Stack trace: " . $e->getTraceAsString());
            $summary['users']['errors']++;
            $this->addIncidencia('create_error', 'User', $userId, $username, 
                "Error al crear usuario: " . $e->getMessage());
            throw $e;
        }
        
        // Procesar imagen UNA SOLA VEZ al crear
        $imageFieldName = $this->imageHandler->getImageFieldName();
        $fotoPath = $usuarioExterno['fotoPath'] ?? null;
        $imageResult = $this->imageHandler->processUserImage($fotoPath, null);
        
        if ($imageResult['imageId']) {
            $user->set($imageFieldName, $imageResult['imageId']);
            $this->entityManager->saveEntity($user);
            error_log("[UserHandler] ✓ Imagen asignada al nuevo usuario");
        }
        
        // Asignar a AMBOS equipos: oficina (por defecto) y CLA
        $this->assignUserToTeams($user, $teamId, $claId);
        
        $summary['users']['created']++;
        $this->addLog('created', 'User', $userId, $userData['userName'], 'success',
                     "Usuario '{$userData['userName']}' creado", $configId);
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
        
        $changes = [];
        $needsUpdate = false;
        
        // Verificar que el equipo del usuario existe
        $currentTeamId = $user->get('defaultTeamId');
        
        if ($currentTeamId && !$this->teamHandler->teamExists($currentTeamId)) {
            if ($user->get('isActive')) {
                $user->set('isActive', false);
                $needsUpdate = true;
                $changes[] = "desactivado por equipo inexistente";
                $this->addIncidencia('missing_team', 'User', $user->getId(), $username,
                                   "Usuario desactivado porque su equipo no existe");
            }
        }
        
        // Preparar datos actualizados
        $userData = $this->prepareUserData($usuarioExterno, $teamId, $rolesMap);
        
        if (!$userData) {
            return;
        }
        
        // Comparar campos (excepto roles que se maneja aparte)
        foreach ($userData as $field => $newValue) {
            if ($field === 'rolesIds') {
                continue; // Roles se manejan con lógica especial
            }
            
            $currentValue = $user->get($field);
            
            // Normalizar para comparación
            $normalizedCurrent = StringUtils::normalize($currentValue);
            $normalizedNew = StringUtils::normalize($newValue);
            
            if ($normalizedCurrent !== $normalizedNew) {
                $user->set($field, $newValue);
                $needsUpdate = true;
                $changes[] = "{$field}";
            }
        }
        
        // ROLES: Lógica aditiva - mantener roles extras, solo actualizar rol de 21online
        $rolesChanged = $this->updateUserRoles($user, $userData['rolesIds'] ?? [], $rolesMap);
        if ($rolesChanged) {
            $needsUpdate = true;
            $changes[] = "roles";
        }
        
        // Verificar contraseña usando password_verify()
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
        
        // Verificar imagen usando cFoto para comparar URL
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
        
        // Guardar si hay cambios
        if ($needsUpdate) {
            $this->entityManager->saveEntity($user);
            
            // Verificar si cambió de equipo
            if ($user->get('defaultTeamId') !== $teamId) {
                $this->assignUserToTeams($user, $teamId, $claId);
                $changes[] = "equipos";
            }
            
            $summary['users']['updated']++;
            $changesStr = implode(', ', $changes);
            $this->addLog('updated', 'User', $user->getId(), $username, 'success',
                         "Usuario actualizado: {$changesStr}", $configId);
        } else {
            $summary['users']['no_changes']++;
        }
    }
    
    /**
     * Actualizar roles del usuario (lógica aditiva)
     * Mantiene roles extras, solo actualiza el rol de 21online
     */
    private function updateUserRoles($user, array $rolesIds21online, array $rolesMap21online): bool
    {
        // Obtener roles actuales del usuario
        $currentRoles = $user->get('roles');
        $currentRoleIds = [];
        if ($currentRoles) {
            foreach ($currentRoles as $role) {
                $currentRoleIds[] = $role->getId();
            }
        }
        
        // Identificar cuáles son roles de 21online
        $roles21onlineIds = array_values($rolesMap21online);
        
        // Separar: roles de 21online vs roles extras (añadidos en EspoCRM)
        $rolesExtras = array_diff($currentRoleIds, $roles21onlineIds);
        
        // Combinar: nuevos roles de 21online + roles extras
        $newRoleIds = array_merge($rolesIds21online, $rolesExtras);
        
        // Comparar
        sort($currentRoleIds);
        sort($newRoleIds);
        
        if ($currentRoleIds !== $newRoleIds) {
            $user->set('rolesIds', $newRoleIds);
            return true;
        }
        
        return false;
    }
    
    /**
     * Preparar datos de usuario formateados correctamente
     * Campos opcionales: nombre, apellidos, email, teléfono
     */
    private function prepareUserData(array $usuarioExterno, string $teamId, array $rolesMap): ?array
    {
        // Username (obligatorio, ya validado)
        $userName = StringUtils::toLowerCase($usuarioExterno['username']);
        
        // Nombre: Si existe usar el nombre, sino usar username
        $firstName = !empty($usuarioExterno['nombre']) 
            ? StringUtils::capitalizeWords($usuarioExterno['nombre'])
            : StringUtils::capitalizeWords($usuarioExterno['username']);
        
        if (empty($usuarioExterno['nombre'])) {
            error_log("[UserHandler] Usuario {$userName}: sin nombre, usando username como firstName");
        }
        
        // Apellido: Combinar apellidos si existen, sino dejar NULL
        $lastName = StringUtils::combineApellidos(
            $usuarioExterno['apellidoP'] ?? null,
            $usuarioExterno['apellidoM'] ?? null
        );
        
        if (empty($lastName)) {
            error_log("[UserHandler] Usuario {$userName}: sin apellidos, dejando lastName vacío");
        }
        
        // Email: Si existe usar email, sino dejar NULL
        $emailAddress = !empty($usuarioExterno['email'])
            ? StringUtils::toLowerCase($usuarioExterno['email'])
            : null;
        
        if (empty($usuarioExterno['email'])) {
            error_log("[UserHandler] Usuario {$userName}: sin email, dejando emailAddress vacío");
        }
        
        // Teléfono: Opcional
        $phoneNumber = !empty($usuarioExterno['telMovil']) 
            ? StringUtils::toLowerCase($usuarioExterno['telMovil']) 
            : null;
        
        // Obtener equipo
        $team = $this->entityManager->getEntityById('Team', $teamId);
        if (!$team) {
            $this->addIncidencia('missing_team', 'User', null, $userName,
                               "Equipo no encontrado: {$teamId}");
            return null;
        }
        
        // Obtener rol de 21online (obligatorio, ya validado)
        $roleId = null;
        $puesto = $usuarioExterno['puesto'] ?? null;
        if (!empty($puesto) && isset($rolesMap[$puesto])) {
            $roleId = $rolesMap[$puesto];
        } else {
            // Si el rol no existe en el mapa, es un error
            $this->addIncidencia('missing_role', 'User', null, $userName,
                               "Rol '{$puesto}' no encontrado en EspoCRM");
            return null;
        }
        
        // Construir URL de foto (opcional, sin descargar aún)
        $fotoPath = $usuarioExterno['fotoPath'] ?? null;
        $fotoUrl = !empty($fotoPath) ? 'https://venezuela.21online.lat/' . ltrim($fotoPath, '/') : null;
        
        $userData = [
            'userName' => $userName,
            'firstName' => $firstName,
            'defaultTeamId' => $team->getId(),
            'isActive' => true,
            'rolesIds' => [$roleId]
        ];
        
        // Apellido: solo si existe
        if (!empty($lastName)) {
            $userData['lastName'] = $lastName;
        }
        
        // Email: solo si existe
        if (!empty($emailAddress)) {
            $userData['emailAddress'] = $emailAddress;
        }
        
        if (!empty($phoneNumber)) {
            $userData['phoneNumber'] = $phoneNumber;
        }
        
        // Guardar URL de foto para comparación futura (campo cFoto)
        if ($fotoUrl !== null) {
            $userData['cFoto'] = $fotoUrl;
        }
        
        return $userData;
    }
    
    /**
     * Asignar usuario a DOS equipos: oficina (por defecto) y CLA
     */
    private function assignUserToTeams($user, string $oficinaId, string $claId): void
    {
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
            
            // Asignar CLA
            $this->entityManager->getRDBRepository('User')
                ->getRelation($user, 'teams')
                ->relate($cla);
                
        } catch (\Exception $e) {
            error_log("[UserHandler] Error asignando equipos: " . $e->getMessage());
        }
    }
    
    /**
     * Desactivar usuarios que están inactivos en 21online
     * Busca primero por ID, luego por userName
     * Verifica si el userName está en lista de activos antes de reportar conflicto
     */
    private function deactivateInactiveUsers(
        array $usuariosInactivos,
        string $configId,
        array &$summary
    ): void {
        $this->logInfo('[UserHandler] Desactivando usuarios inactivos de 21online...');
        
        // Crear mapa de usernames activos para verificación rápida
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
        
        error_log("[UserHandler] Usuarios activos en EspoCRM (21online): " . count($activeUsernames));
        
        $contadorDesactivados = 0;
        $contadorNoEncontrados = 0;
        
        foreach ($usuariosInactivos as $usuarioInactivo) {
            try {
                $userId = (string)$usuarioInactivo['id'];
                $username = StringUtils::toLowerCase($usuarioInactivo['username'] ?? 'Unknown');
                
                // Buscar por ID primero
                $user = $this->entityManager->getEntityById('User', $userId);
                
                // Si no existe por ID, buscar por userName
                if (!$user) {
                    $user = $this->entityManager->getRDBRepository('User')
                        ->where(['userName' => $username])
                        ->findOne();
                    
                    if ($user) {
                        $existingId = $user->getId();
                        
                        // Verificar si el userName está en la lista de ACTIVOS
                        if (isset($activeUsernames[$username])) {
                            // Usuario está ACTIVO en 21online con este userName
                            // No hay conflicto real - el inactivo simplemente no existe
                            error_log("[UserHandler] Usuario {$username} inactivo en 21online pero activo con ID {$existingId} - OK");
                            continue;
                        }
                        
                        // Usuario existe con ID diferente y NO está en activos
                        error_log("[UserHandler] ⚠ Usuario inactivo con ID desincronizado:");
                        error_log("[UserHandler]   Username: {$username}");
                        error_log("[UserHandler]   ID en EspoCRM: {$existingId}");
                        error_log("[UserHandler]   ID en 21online (inactivo): {$userId}");
                        
                        $this->addIncidencia('id_mismatch_inactive', 'User', $existingId, $username,
                            "Usuario inactivo con ID desincronizado - EspoCRM: {$existingId}, 21online: {$userId}");
                    } else {
                        // No existe ni por ID ni por userName
                        $contadorNoEncontrados++;
                        if ($contadorNoEncontrados <= 10) { // Limitar logs
                            error_log("[UserHandler] Usuario inactivo {$username} (ID: {$userId}) no existe en EspoCRM");
                        }
                        continue;
                    }
                }
                
                // Desactivar si está activo
                if ($user && $user->get('isActive')) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $contadorDesactivados++;
                    $summary['users']['disabled']++;
                    
                    $this->addLog('disabled', 'User', $user->getId(), $user->get('userName'), 'success',
                                 "Usuario desactivado (inactivo en 21online)", $configId);
                    error_log("[UserHandler] ✓ Usuario desactivado: {$user->get('userName')} (inactivo en 21online)");
                }
                
            } catch (\Exception $e) {
                error_log("[UserHandler] ERROR desactivando usuario inactivo: " . $e->getMessage());
            }
        }
        
        if ($contadorNoEncontrados > 10) {
            error_log("[UserHandler] ... y " . ($contadorNoEncontrados - 10) . " usuarios inactivos más no encontrados");
        }
        
        $this->logInfo("[UserHandler] Total usuarios desactivados por inactividad: {$contadorDesactivados}");
        $this->logInfo("[UserHandler] Usuarios inactivos no encontrados: {$contadorNoEncontrados}");
    }
    
    /**
     * Desactivar usuarios activos en 21online pero sin equipo válido
     * Este paso se ejecuta AL FINAL para evitar reactivaciones accidentales
     */
    private function deactivateUsersWithoutTeam(
        array $processedUsers,
        string $configId,
        array &$summary
    ): void {
        $this->logInfo('[UserHandler] Verificando usuarios sin equipo válido...');
        
        $contadorDesactivados = 0;
        
        // Obtener TODOS los usuarios de 21online (IDs numéricos)
        $users = $this->entityManager->getRDBRepository('User')->find();
        
        foreach ($users as $user) {
            $userId = $user->getId();
            
            // Solo usuarios de 21online (IDs numéricos)
            if (!is_numeric($userId)) {
                continue;
            }
            
            // Si fue procesado exitosamente, saltar
            if (isset($processedUsers[$userId])) {
                continue;
            }
            
            // Usuario de 21online que NO fue procesado → verificar equipo
            $defaultTeamId = $user->get('defaultTeamId');
            
            if (!$defaultTeamId || !$this->teamHandler->teamExists($defaultTeamId)) {
                if ($user->get('isActive')) {
                    $user->set('isActive', false);
                    $this->entityManager->saveEntity($user);
                    
                    $contadorDesactivados++;
                    $summary['users']['disabled']++;
                    
                    $this->addLog('disabled', 'User', $userId, $user->get('userName'), 'success',
                                 "Usuario desactivado (equipo no existe)", $configId);
                    error_log("[UserHandler] ✓ Usuario desactivado por equipo inexistente: {$user->get('userName')}");
                }
            }
        }
        
        $this->logInfo("[UserHandler] Usuarios desactivados por equipo inexistente: {$contadorDesactivados}");
    }
    
    /**
     * Validar datos básicos del usuario (solo campos OBLIGATORIOS)
     */
    private function validateUserData(array $usuarioExterno, array &$summary): bool
    {
        // ID es obligatorio
        if (empty($usuarioExterno['id'])) {
            $this->addIncidencia('validation_error', 'User', null, 'Unknown',
                               "Usuario sin ID");
            $summary['users']['skipped']++;
            return false;
        }
        
        // Username es obligatorio
        if (empty($usuarioExterno['username'])) {
            $this->addIncidencia('validation_error', 'User', $usuarioExterno['id'], 'Unknown',
                               "Usuario ID {$usuarioExterno['id']} sin username");
            $summary['users']['skipped']++;
            return false;
        }
        
        // idAfiliados es obligatorio
        if (empty($usuarioExterno['idAfiliados'])) {
            $this->addIncidencia('validation_error', 'User', $usuarioExterno['id'], $usuarioExterno['username'],
                               "Usuario '{$usuarioExterno['username']}' sin idAfiliados");
            $summary['users']['skipped']++;
            return false;
        }
        
        // password es obligatorio
        if (empty($usuarioExterno['password'])) {
            $this->addIncidencia('validation_error', 'User', $usuarioExterno['id'], $usuarioExterno['username'],
                               "Usuario '{$usuarioExterno['username']}' sin password");
            $summary['users']['skipped']++;
            return false;
        }
        
        // puesto es obligatorio
        if (empty($usuarioExterno['puesto'])) {
            $this->addIncidencia('validation_error', 'User', $usuarioExterno['id'], $usuarioExterno['username'],
                               "Usuario '{$usuarioExterno['username']}' sin puesto (rol)");
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
     * Obtener mapa de usuarios existentes de 21online (solo IDs numéricos)
     */
    private function getExistingUsersMap(): array
    {
        $this->logInfo('[UserHandler] Obteniendo usuarios existentes de 21online...');
        
        $map = [];
        
        try {
            $users = $this->entityManager->getRDBRepository('User')->find();
            
            foreach ($users as $user) {
                $userId = $user->getId();
                
                // Solo usuarios de 21online (IDs numéricos)
                if (is_numeric($userId)) {
                    $map[$userId] = $user;
                }
            }
            
            $this->logInfo('[UserHandler] Usuarios de 21online encontrados: ' . count($map));
            
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
}