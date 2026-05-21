# Project Exporter

Project Exporter es una aplicacion PHP monousuario diseñada para explorar y descargar carpetas como archivos ZIP. La aplicacion utiliza streaming para comprimir y transmitir archivos al navegador sin crear archivos temporales en el servidor, lo que permite gestionar descargas de proyectos grandes sin consumir espacio en disco.

**Opcionalmente** puede compilarse con sistema de autenticacion que incluye login, roles (admin/user), y gestion de usuarios.

## Caracteristicas

### Exploracion y descarga
- Exploracion visual de estructura de carpetas con navegacion jerarquica
- Seleccion y exclusion de carpetas individuales antes de la descarga
- Compresion en tiempo real con streaming directo al navegador
- Calculo de tamanos de carpeta optimizado mediante comandos de shell
- Interfaz responsive con ordenamiento multiple (nombre, tamano, seleccion)
- Barra de progreso en tiempo real durante la compresion
- Sin archivos temporales: el ZIP se genera y transmite sobre la marcha

### Sistema de autenticacion (opcional)
- **Login con sesiones PHP** — Seguro, sin librerias externas
- **Roles de usuario** — Admin (gestiona usuarios) y User (solo navega/descarga)
- **Gestion de usuarios** — CRUD desde interfaz (solo admin)
- **Recuerdame** — Sesion persistente por 7 dias
- **Cambio de contraseña** — Propio y de otros usuarios (admin)
- **Almacenamiento dual** — SQLite o TXT (fallback automatico)
- **Logs integrados** — error.log en la misma carpeta para debugging

## Arquitectura

### Estructura del codigo fuente

```
src/
├── php/
│   ├── api.php        - Endpoints del backend (browse, download, progress)
│   ├── helpers.php    - Funciones auxiliares (hasSubfolders, getFolderSizes)
│   ├── auth.php       - Sistema de autenticacion (sesiones, almacenamiento dual)
│   ├── auth-api.php   - Endpoints de autenticacion (login, logout, CRUD)
│   └── template.phtml - Plantilla HTML con marcadores para inyeccion
├── css/
│   ├── style.css      - Estilos de la interfaz principal
│   └── auth.css       - Estilos del sistema de login y gestion de usuarios
└── js/
    ├── app.js         - Logica del frontend (explorador, descargas)
    └── auth.js        - Logica de autenticacion (login, CRUD usuarios)
```

### Archivos generados

**Sin login:**
```
dist/
├── exporter.php  - Aplicacion completa compilada
└── vendor.phar   - Dependencias empaquetadas (ZipStream)
```

**Con login (`php exporter --login`):**
```
dist/
├── exporter.php      - Aplicacion completa con login
├── vendor.phar       - Dependencias empaquetadas
├── exporter.sqlite   - Base de datos SQLite (usuarios)
└── exporter.txt      - Archivo de respaldo JSONL (usuarios)
```

### Dependencias

El proyecto depende de la biblioteca ZipStream para la generacion de ZIPs con streaming, empaquetada en el archivo `vendor.phar`.

**Versiones de ZipStream y requisitos de PHP:**
- `maennchen/zipstream-php:^0.5` - PHP 5.3+ (usado actualmente, sin type hints)
- `maennchen/zipstream-php:^1.2` - PHP 7.1+ (tiene type hints que requieren parcheo)
- `maennchen/zipstream-php:^2.2` - PHP 7.4+ (no compatible con este proyecto)
- `maennchen/zipstream-php:^3.0` - PHP 8.1+ (no compatible con este proyecto)

## Requisitos del sistema

### Basico (sin login)
- PHP 7.1 o superior
- Extensiones PHP: `json`, `zip`, `mbstring`
- Acceso a `shell_exec()` con comando `du` para calculo optimizado de tamanos
- Permiso de escritura en directorio temporal del sistema para archivos de progreso

### Con sistema de login
- Todo lo basico, plus:
- Extensiones PHP: `session` (habitualmente incluida), `pdo` y `pdo_sqlite` (opcional, para SQLite)
- Permiso de escritura en el directorio de la aplicacion (para `error.log` y archivos de datos)
- **Sin SQLite:** funcionara con almacenamiento TXT automaticamente
- **Con SQLite:** mejor rendimiento, requiere extension `pdo_sqlite`

### Dependencias

- **ZipStream-PHP v0.5.2**: Compatible con PHP 5.3+ sin type hints
- **Sin librerias externas para login:** usa funciones nativas de PHP

## Proceso de compilacion

El proceso de compilacion transforma los modulos separados en un unico archivo PHP monolitico listo para produccion.

### Paso 1: Preparacion de dependencias

El archivo `vendor.phar` debe existir en la raiz del proyecto. Este archivo contiene la biblioteca ZipStream necesaria para la generacion de archivos ZIP con streaming.

Si no esta presente, debe ser generado o copiado desde una instalacion valida de Composer.

### Paso 2: Ejecucion del script de compilacion

El script `exporter` realiza el siguiente proceso:

1. Lectura del archivo CSS (`src/css/style.css`)
2. Lectura del archivo JavaScript (`src/js/app.js`)
3. Lectura de la logica del backend (`src/php/api.php`)
4. Lectura de las funciones auxiliares (`src/php/helpers.php`)
5. Carga de la plantilla HTML (`src/php/template.phtml`)

### Paso 3: Inyeccion de contenido

La plantilla HTML contiene marcadores de posicion que son reemplazados:

- El marcador `{{CSS}}` se sustituye por el contenido completo de `style.css`
- El marcador `{{JS}}` se sustituye por el contenido completo de `app.js`

El resultado es una pagina HTML autocontenida con estilos y scripts embebidos.

### Paso 4: Generacion del archivo final

El script genera el archivo `dist/exporter.php` que contiene:

- La logica PHP completa del backend
- Las funciones auxiliares
- La plantilla HTML con CSS y JS embebidos
- Todo el codigo necesario para ejecutar la aplicacion de forma independiente

### Paso 5: Copia de dependencias

El archivo `vendor.phar` se copia al directorio `dist/` para acompañar al archivo principal.

## Comandos de compilacion

### Sin login (comportamiento original)

```bash
php exporter
```

Genera una aplicacion sin autenticacion, accesible por cualquiera que conozca la URL.

### Con login (recomendado para produccion)

```bash
php exporter --login
```

El script solicitara interactivamente:
- **Nombre de usuario admin** — 3-30 caracteres alfanumericos, guiones o guiones bajos
- **Contraseña admin** — Minimo 6 caracteres, con confirmacion

Genera una aplicacion con login y crea el usuario administrador inicial.

### Resultado

Los archivos se generan en el directorio `dist/`:

- `exporter.php` — Aplicacion completa (con o sin login segun compilacion)
- `vendor.phar` — Dependencias de ZipStream
- `exporter.sqlite` — Base de datos SQLite (solo con --login)
- `exporter.txt` — Archivo de respaldo JSONL (solo con --login)

## Proceso de despliegue

### Preparacion de archivos

Una vez compilado, el directorio `dist/` contiene:

**Sin login:**
1. `exporter.php` - La aplicacion completa
2. `vendor.phar` - Las dependencias

**Con login:**
1. `exporter.php` - La aplicacion completa con sistema de autenticacion
2. `vendor.phar` - Las dependencias
3. `exporter.sqlite` - Base de datos de usuarios
4. `exporter.txt` - Archivo de respaldo de usuarios

### Configuracion del servidor

#### Configuracion de ruta base

La variable `$basePath` en `exporter.php` determina el directorio raiz que sera explorado por la aplicacion. Por defecto, se establece en `__DIR__`, que apunta al directorio donde reside el archivo.

Para modificar el directorio a explorar, puede:
1. Editar la variable `$basePathSuffix` en `exporter` antes de compilar, o
2. Editar la linea correspondiente en `dist/exporter.php` despues de compilar:

```php
$basePath = __DIR__; // Apunta al propio directorio
// Alternativa:
$basePath = __DIR__ . '/proyectos'; // Subcarpeta proyectos
```

#### Permisos de ejecucion

**Sin login:**
- Lectura en el directorio `$basePath` y subdirectorios
- Escritura en el directorio temporal del sistema (para archivos de progreso)

**Con login:**
- Todo lo anterior, plus:
- Escritura en el directorio de la aplicacion (para `error.log`)
- Escritura en `exporter.sqlite` o `exporter.txt` (segun corresponda)

#### Permisos recomendados para produccion

```bash
# Estructura recomendada
/var/www/proyecto/
├── data/                  # 777 o www-data:www-data (writable)
│   ├── exporter.sqlite
│   └── exporter.txt
└── public/                # 755 usuario:usuario (solo lectura)
    ├── exporter.php
    └── vendor.phar
```

El directorio `data/` es donde se escriben los archivos de datos y logs, separado del codigo fuente.

### Instalacion en el servidor

1. Copie los archivos de `dist/` al servidor
2. Coloque `exporter.php` y `vendor.phar` en la ubicacion deseada
3. Si usa login, coloque tambien `exporter.sqlite` y `exporter.txt`
4. Configure los permisos apropiados (ver seccion anterior)
5. Configure el servidor web para servir el archivo PHP

### Verificar instalacion

Acceda a la URL donde instalo `exporter.php`. Si compilo con login, vera la pantalla de inicio de sesion. Si no, vera directamente la lista de proyectos.

### Servidor web

La aplicacion funciona con cualquier servidor web compatible con PHP:

- Apache con mod_php
- Nginx con PHP-FPM
- Servidores integrados de desarrollo (`php -S`)

## Sistema de Autenticacion

### Vista de Login

Al compilar con `--login`, la aplicacion muestra primero una pantalla de inicio de sesion:

- Usuario y contrasena
- Checkbox "Recordar sesion" (7 dias)
- Mensajes de error para credenciales invalidas
- Proteccion contra fuerza bruta (5 intentos = bloqueo progresivo)

### Interfaz principal (autenticado)

Una vez autenticado, la barra superior muestra:

- **Usuario actual** — Nombre de usuario
- **Badge de rol** — Admin (azul) o User (gris)
- **Boton Usuarios** — Solo visible para admin, abre panel de gestion
- **Boton de candado** — Cambiar contrasena propia
- **Boton de logout** — Cerrar sesion

### Gestion de usuarios (solo admin)

El panel de gestion permite:

- **Listar usuarios** — Tabla con username, rol, fecha de creacion
- **Crear usuario** — Username + contraseña (minimo 6 caracteres), rol siempre "user"
- **Editar usuario** — Cambiar username o rol (admin ↔ user)
- **Eliminar usuario** — Protege al ultimo admin de ser eliminado

### Cambio de contrasena

**Propia:**
- Requiere contrasena actual
- Nueva contrasena (minimo 6 caracteres)
- Confirmacion de nueva contrasena

**De otros usuarios (admin):**
- Desde el panel de gestion
- No requiere contrasena actual del usuario
- Mismo proceso que crear/editar

### Almacenamiento de usuarios

**SQLite (prioridad):**
- Se usa si la extension `pdo_sqlite` esta disponible
- Mejor rendimiento para muchas operaciones
- El archivo `exporter.sqlite` se genera automaticamente al compilar

**TXT (fallback):**
- Se usa automaticamente si SQLite no esta disponible
- Formato JSONL (un JSON por linea)
- Compatible con cualquier entorno PHP
- El archivo `exporter.txt` se genera como respaldo

**Deteccion automatica:**
La aplicacion detecta en runtime cual usar, sin configuracion manual.

### Logging

El sistema genera automaticamente un archivo `error.log` en el mismo directorio que `exporter.php`:

**Informacion capturada:**
- Carga de modulo (PHP version, login enabled)
- Deteccion de tipo de almacenamiento
- Cambios de contrasena (paso a paso)
- Errores fatales, excepciones, warnings

**Rotacion automatica:**
- Cuando el log supera 5MB, se crea un backup con timestamp
- Formato: `error.2026-05-21.120000.log`

**Para ver logs:**
```bash
# En tiempo real
tail -f error.log

# Solo errores
grep "ERROR" error.log

# Solo fatales
grep "FATAL" error.log
```

## Uso de la aplicacion

### Interfaz principal

Al acceder a la aplicacion, se muestra una lista de carpetas disponibles en el `$basePath` configurado. Cada carpeta representa un proyecto descargable.

### Exploracion de carpetas

1. Haga clic en una carpeta para abrir el explorador
2. Navegue por la estructura jerarquica usando el breadcrumb o haciendo clic en subcarpetas
3. Use los controles de ordenamiento para organizar las carpetas por nombre, tamano o estado de seleccion

### Seleccion y exclusion

- Active o desactive los checkbox para incluir o excluir carpetas de la descarga
- El checkbox maestro permite seleccionar o deseleccionar todas las carpetas visibles
- Las exclusiones se propagan: excluir una carpeta excluye automaticamente todas sus subcarpetas
- Las carpetas con exclusiones parciales muestran un estado indeterminado

### Descarga

1. Configure las exclusiones deseadas
2. Haga clic en el boton "Descargar"
3. La aplicacion mostrara una barra de progreso mientras comprime y transmite los archivos
4. El navegador descargara el archivo ZIP una vez completada la transmision

## Consideraciones de seguridad

### Validacion de rutas

La aplicacion utiliza `realpath()` para validar todas las rutas y prevenir ataques de directory traversal. Solo se permite acceso a rutas dentro del `$basePath` configurado.

### Sanitizacion de salida

Todos los datos enviados al HTML se sanitizan con `htmlspecialchars()` para prevenir ataques XSS. El JavaScript utiliza funciones auxiliares de escape para contenido HTML y atributos.

### Tokens de progreso

Los tokens utilizados para el seguimiento del progreso se sanitizan mediante expresiones regulares, permitiendo unicamente caracteres alfanumericos seguros.

### Seguridad del sistema de login

- **Contraseñas hasheadas** — `password_hash()` con algoritmo PASSWORD_DEFAULT (bcrypt)
- **Proteccion anti fuerza-bruta** — Bloqueo progresivo despues de 5 intentos fallidos
- **Regeneracion de sesion** — `session_regenerate_id(true)` al login previene fixation
- **Expiracion de sesion** — 30 minutos de inactividad automatica
- **Tokens remember seguros** — 32 bytes aleatorios, expiran en 7 dias
- **Proteccion de rutas** — Validacion con `realpath()` en todas las operaciones
- **Rol admin protegido** — No se puede eliminar al unico administrador
- **Inputs validados** — Usernames: 3-30 caracteres alfanumericos,Passwords: minimo 6 caracteres

### En produccion

- Mantener `exporter.php` y `vendor.phar` fuera del document root si es posible
- Usar HTTPS para proteger credenciales en transito
- Configurar permisos de directorio apropiadamente (seccion Permisos)
- Rotar periodicamente `error.log` (se hace automatico >5MB)
- Revisar `error.log` periocdicamente para detectar actividad sospechosa

## Solucion de problemas

### El comando du no esta disponible

Si el comando `du` no esta disponible o `shell_exec()` esta deshabilitado, la aplicacion utilizara automaticamente el metodo alternativo en PHP puro para calcular tamanos de carpeta. Este metodo es mas lento pero funciona en cualquier entorno PHP.

### Archivos temporales de progreso

Los archivos de progreso se almacenan en el directorio temporal del sistema (`sys_get_temp_dir()`) y se eliminan automaticamente cuando el navegador cierra la conexion o cuando se completa la descarga.

### Limites de memoria y tiempo

La aplicacion utiliza streaming para evitar cargar archivos completos en memoria. No obstante, puede ser necesario ajustar las directivas de PHP:

- `max_execution_time` - Se establece en 0 (sin limite) durante la descarga
- `memory_limit` - Ajustar si se procesan directorios con miles de archivos

### Problemas con el sistema de login

**Logout falla con "readonly database":**
- El archivo `exporter.sqlite` no es writable por el servidor web
- Solucion: `chmod 666 exporter.sqlite` o cambiar dueño a `www-data`

**No puedo cambiar contrasena, pero dice "exito":**
- Verificar `error.log` para ver detalles del problema
- Puede ser problema de permisos en `exporter.sqlite` o `exporter.txt`

**Detecta TXT pero quiero usar SQLite:**
- Verificar que la extension `pdo_sqlite` este cargada: `php -m | grep pdo_sqlite`
- Verificar que `exporter.sqlite` exista y sea legible

**Error "attempt to write a readonly database":**
- SQLite necesita permisos de escritura en el **directorio**, no solo en el archivo
- Solucion: `chmod 777 directorio/` o mover datos a ubicacion writable

**Sesion expira muy rapido:**
- La sesion expira despues de 30 minutos de inactividad (comportamiento normal)
- Use "Recordar sesion" para extender a 7 dias

**No puedo hacer login despues de cambiar contrasena:**
- Verificar que la contrasena nueva tenga al menos 6 caracteres
- Verificar `error.log` para ver si hay errores de validacion
