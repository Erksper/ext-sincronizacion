# Guía de Implementación Completa
## Módulo Sincronización Century 21

---

## 📋 Checklist Pre-Instalación

### 1. Requisitos del Servidor
- [ ] EspoCRM >= 8.0.0 instalado y funcionando
- [ ] PHP >= 8.1 con extensiones:
  - [ ] `openssl`
  - [ ] `pdo_mysql`
  - [ ] `mbstring`
  - [ ] `json`
- [ ] MySQL/MariaDB accesible desde el servidor EspoCRM
- [ ] Conectividad de red entre servidores (si BD externa está en otro servidor)

### 2. Preparación de Base de Datos Externa
- [ ] Base de datos creada
- [ ] Usuario con permisos de SELECT creado
- [ ] Tablas `afiliados` y `usuarios` creadas
- [ ] Datos de prueba insertados
- [ ] Ejecutar `test_connection.php` exitosamente

### 3. Configuración EspoCRM
- [ ] Backup completo de EspoCRM realizado
- [ ] Rol "Asesor" creado en el sistema
- [ ] Permisos del rol configurados
- [ ] Usuario administrador disponible

---

## 🚀 Proceso de Instalación

### Paso 1: Preparar Módulo
```bash
# 1. Clonar o copiar archivos del módulo
cd /path/to/espocrm-ext-template

# 2. Verificar estructura de archivos
tree src/

# 3. Construir paquete
php build.php

# 4. Verificar que se generó el .zip
ls -lh build/
```

### Paso 2: Instalar en EspoCRM
1. **Ir a Administración**
   - Menu > Administración > Extensiones

2. **Subir Extensión**
   - Click en "Subir Paquete"
   - Seleccionar el archivo .zip generado
   - Click en "Instalar"

3. **Verificar Instalación**
   - Esperar a que termine la instalación
   - Verificar que no haya errores en pantalla
   - Refrescar caché: Administración > Limpiar Caché

4. **Verificar Módulos Visibles**
   - Debe aparecer "Configuración BD Externa" en el menú
   - Debe aparecer "Logs de Sincronización" en el menú

### Paso 3: Crear Rol "Asesor"
```
Administración > Roles > Crear Rol

Nombre: Asesor
Configurar permisos según necesidades de tu organización
```

**Permisos Sugeridos para Rol Asesor:**
- **Accounts**: read: all, edit: own, delete: no, create: yes
- **Contacts**: read: all, edit: own, delete: no, create: yes
- **Leads**: read: all, edit: own, delete: no, create: yes
- **Opportunities**: read: all, edit: own, delete: no, create: yes
- **Tasks**: read: own, edit: own, delete: own, create: yes
- **Meetings**: read: team, edit: own, delete: own, create: yes
- **Calls**: read: team, edit: own, delete: own, create: yes

### Paso 4: Configurar BD Externa
```
Menu > Configuración BD Externa > Crear

Completar:
- Nombre: "BD Century 21 Producción"
- Host: 192.168.1.100 (o localhost)
- Puerto: 3306
- Base de Datos: century21_external
- Usuario: espocrm_reader
- Contraseña: ********
- Email Notificaciones: admin@century21.com
- Activa: ✓

Guardar
```

### Paso 5: Configurar Job Programado
```
Administración > Jobs Programados

Buscar: "Sincronizar Usuarios y Teams desde BD Externa"

Configurar:
- Estado: Activo
- Frecuencia: */15 * * * * (cada 15 minutos)
- Guardar
```

### Paso 6: Primera Sincronización Manual
```
Administración > Jobs Programados
> Buscar el job de sincronización
> Click en "Ejecutar"

Esperar 30 segundos

Verificar:
Menu > Logs de Sincronización
- Debe haber registros nuevos
- Verificar que no haya errores
```

### Paso 7: Verificación Post-Instalación
- [ ] Ver Teams creados: Administración > Teams
- [ ] Ver Usuarios creados: Administración > Usuarios
- [ ] Verificar que usuarios tienen Team asignado
- [ ] Verificar que usuarios tienen Rol "Asesor"
- [ ] Probar login con un usuario sincronizado

---

## 🔧 Troubleshooting

### Problema: "No se pudo conectar a la BD externa"

**Causas Posibles:**
1. Credenciales incorrectas
2. Host/IP inaccesible
3. Puerto bloqueado por firewall
4. Base de datos no existe

**Solución:**
```bash
# 1. Probar conexión desde servidor EspoCRM
mysql -h [HOST] -P [PORT] -u [USER] -p[PASSWORD] [DATABASE]

# 2. Verificar firewall
telnet [HOST] 3306

# 3. Verificar permisos de usuario
SHOW GRANTS FOR 'usuario'@'%';

# 4. Ejecutar test_connection.php
php test_connection.php
```

### Problema: "El rol 'Asesor' no existe"

**Solución:**
```
1. Ir a: Administración > Roles
2. Crear nuevo rol con nombre EXACTO: "Asesor"
3. Configurar permisos
4. Guardar
5. Volver a ejecutar sincronización
```

### Problema: "Usuarios creados pero no pueden iniciar sesión"

**Causas Posibles:**
1. Contraseña no se hasheó correctamente
2. Usuario marcado como inactivo
3. Email duplicado

**Solución:**
```sql
-- Verificar estado del usuario
SELECT id, user_name, email_address, is_active, LENGTH(password) as pwd_length
FROM user
WHERE user_name = 'nombre_usuario';

-- Si password es NULL o muy corto, el hasheo falló
-- Restablecer contraseña desde EspoCRM:
-- Administración > Usuarios > [Usuario] > Cambiar Contraseña
```

### Problema: "Usuarios no tienen Team asignado"

**Solución:**
```sql
-- Verificar en BD externa que idAfiliados existe
SELECT id, username, idAfiliados FROM usuarios WHERE username = 'problema';

-- Verificar que ese Team existe en EspoCRM
SELECT id, name FROM team WHERE id = '[idAfiliados]';

-- Si el Team no existe, sincronizar primero los Teams
-- Luego volver a sincronizar usuarios
```

### Problema: "Logs no se crean"

**Solución:**
```bash
# 1. Verificar permisos de la tabla
SHOW CREATE TABLE sync_log;

# 2. Verificar que la entidad existe
# Ir a: Administración > Entity Manager
# Debe aparecer "SyncLog"

# 3. Verificar logs del sistema
tail -f data/logs/espo-$(date +%Y-%m-%d).log | grep -i sync

# 4. Limpiar caché
Administración > Limpiar Caché > Rebuild
```

### Problema: "Job no se ejecuta automáticamente"

**Solución:**
```bash
# 1. Verificar que el cron de EspoCRM está activo
crontab -l | grep espo

# Debe existir algo como:
# * * * * * cd /var/www/espocrm; php cron.php

# 2. Verificar que el job está activo
# Administración > Jobs Programados
# Estado debe ser "Activo"

# 3. Ejecutar manualmente el cron
cd /var/www/espocrm
php cron.php

# 4. Ver logs del cron
tail -f data/logs/espo-$(date +%Y-%m-%d).log
```

### Problema: "Demasiados logs, BD se llena"

**Solución:**
```sql
-- Los logs se limpian automáticamente cada 30 días
-- Para limpiar manualmente:
DELETE FROM sync_log WHERE sync_date < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- O ajustar el período en el código (SyncService.php):
-- Cambiar línea:
$date30DaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
-- Por ejemplo a 7 días:
$date30DaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
```

### Problema: "Emails de notificación no llegan"

**Verificar:**
```
1. Configuración de email en EspoCRM:
   Administración > Email Accounts
   - Debe haber cuenta configurada para envío

2. Verificar email en configuración:
   Menu > Configuración BD Externa
   - Campo "Email de Notificaciones" debe estar lleno

3. Probar envío de email:
   Administración > Email Accounts
   > Test Connection

4. Ver logs de email:
   data/logs/espo-[fecha].log | grep -i "email\|mail"
```

---

## 📊 Monitoreo y Mantenimiento

### Monitoreo Diario
```bash
# Ver logs de hoy
tail -100 data/logs/espo-$(date +%Y-%m-%d).log | grep SyncJob

# Ver resumen de sincronización
mysql -u root -p espocrm << EOF
SELECT 
    DATE(sync_date) as fecha,
    COUNT(*) as operaciones,
    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errores
FROM sync_log
WHERE DATE(sync_date) = CURDATE()
GROUP BY DATE(sync_date);
EOF
```

### Tareas Semanales
- [ ] Revisar logs de errores
- [ ] Verificar cantidad de usuarios sincronizados
- [ ] Verificar emails de notificación
- [ ] Comprobar que usuarios nuevos pueden iniciar sesión

### Tareas Mensuales
- [ ] Backup completo de EspoCRM
- [ ] Revisar tamaño de tabla sync_log
- [ ] Auditar usuarios inactivos
- [ ] Verificar integridad de datos (queries_utiles.sql)

---

## 🔐 Seguridad

### Mejores Prácticas

1. **Credenciales de BD Externa**
   - Usar usuario con permisos READ ONLY
   - No usar root o usuarios con privilegios elevados
   - Cambiar contraseña periódicamente

2. **Acceso al Módulo**
   - Solo administradores pueden ver configuraciones
   - Auditar quién tiene rol de administrador
   - Revisar logs de acceso regularmente

3. **Encriptación**
   - Las credenciales se encriptan con AES-256
   - La clave usa el passwordSalt de EspoCRM
   - Mantener secreto el archivo data/config.php

4. **Firewall**
   - Permitir solo IP del servidor EspoCRM a BD externa
   - Usar conexión SSL/TLS si es posible (agregar en PDO):
     ```php
     PDO::MYSQL_ATTR_SSL_CA => '/path/to/ca.pem'
     ```

---

## 📈 Optimización

### Performance

Si tienes muchos usuarios (>1000):

1. **Ajustar índices en BD Externa**
   ```sql
   CREATE INDEX idx_usuarios_active_afiliados 
   ON usuarios(isActive, idAfiliados);
   
   CREATE INDEX idx_afiliados_active 
   ON afiliados(isActive);
   ```

2. **Aumentar memoria de PHP**
   ```ini
   # php.ini
   memory_limit = 512M
   max_execution_time = 300
   ```

3. **Reducir frecuencia del Job**
   - De cada 15 minutos a cada 30 minutos
   - O cada hora si los datos no cambian frecuentemente

4. **Implementar sincronización incremental**
   - Modificar queries para solo traer registros modificados
   - Agregar campo `updated_at` en BD externa
   - Guardar fecha de última sincronización exitosa

---

## 🆘 Contacto y Soporte

Si después de seguir esta guía sigues teniendo problemas:

1. **Recopilar información:**
   ```bash
   # Logs del sistema
   tail -200 data/logs/espo-$(date +%Y-%m-%d).log > debug_logs.txt
   
   # Últimos logs de sincronización
   mysql -u root -p espocrm << EOF > debug_sync.txt
   SELECT * FROM sync_log ORDER BY sync_date DESC LIMIT 50;
   EOF
   ```

2. **Información del entorno:**
   - Versión de EspoCRM
   - Versión de PHP
   - Sistema operativo
   - Cantidad de usuarios a sincronizar

3. **Contactar soporte** con toda la información recopilada

---

## ✅ Checklist Post-Instalación

- [ ] Módulo instalado sin errores
- [ ] Rol "Asesor" creado
- [ ] Configuración de BD externa guardada
- [ ] Job programado activado y funcionando
- [ ] Primera sincronización exitosa
- [ ] Teams/Oficinas creados correctamente
- [ ] Usuarios creados correctamente
- [ ] Usuarios pueden iniciar sesión
- [ ] Logs visibles en el sistema
- [ ] Emails de notificación funcionando
- [ ] Documentación entregada al equipo
- [ ] Backup realizado

---

## 📝 Notas Adicionales

- Este módulo NO modifica la BD externa, solo lee
- Los usuarios sincronizados son de tipo "regular"
- Los IDs se mantienen entre ambas BDs para facilitar tracking
- La sincronización es unidireccional (Externa → EspoCRM)
- Para sincronización bidireccional, contactar desarrollo

---

**Fecha de última actualización:** Enero 2025  
**Versión del módulo:** 1.0.0