# Project Exporter

Project Exporter es una aplicacion PHP monousuario diseñada para explorar y descargar carpetas como archivos ZIP. La aplicacion utiliza streaming para comprimir y transmitir archivos al navegador sin crear archivos temporales en el servidor, lo que permite gestionar descargas de proyectos grandes sin consumir espacio en disco.

## Caracteristicas

- Exploracion visual de estructura de carpetas con navegacion jerarquica
- Seleccion y exclusion de carpetas individuales antes de la descarga
- Compresion en tiempo real con streaming directo al navegador
- Calculo de tamanos de carpeta optimizado mediante comandos de shell
- Interfaz responsive con ordenamiento multiple (nombre, tamano, seleccion)
- Barra de progreso en tiempo real durante la compresion
- Sin archivos temporales: el ZIP se genera y transmite sobre la marcha

## Arquitectura

### Estructura del codigo fuente

```
src/
├── php/
│   ├── api.php        - Endpoints del backend (browse, download, progress)
│   ├── helpers.php    - Funciones auxiliares (hasSubfolders, getFolderSizes)
│   └── template.phtml - Plantilla HTML con marcadores para inyeccion
├── css/
│   └── style.css      - Estilos de la interfaz
└── js/
    └── app.js         - Logica del frontend en vanilla JavaScript
```

### Archivos generados

```
dist/
├── exporter.php  - Aplicacion completa compilada lista para produccion
└── vendor.phar   - Dependencias empaquetadas (ZipStream)
```

### Dependencias

El proyecto depende de la biblioteca ZipStream para la generacion de ZIPs con streaming, empaquetada en el archivo `vendor.phar`.

**Versiones de ZipStream y requisitos de PHP:**
- `maennchen/zipstream-php:^0.5` - PHP 5.3+ (usado actualmente, sin type hints)
- `maennchen/zipstream-php:^1.2` - PHP 7.1+ (tiene type hints que requieren parcheo)
- `maennchen/zipstream-php:^2.2` - PHP 7.4+ (no compatible con este proyecto)
- `maennchen/zipstream-php:^3.0` - PHP 8.1+ (no compatible con este proyecto)

## Requisitos del sistema

- PHP 7.1 o superior
- Extensiones PHP: `json`, `zip`, `mbstring`
- Acceso a `shell_exec()` con comando `du` para calculo optimizado de tamanos
- Permiso de escritura en directorio temporal del sistema para archivos de progreso

### Dependencias

- **ZipStream-PHP v0.5.2**: Compatible con PHP 5.3+ sin type hints

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

Para compilar el proyecto, ejecute el siguiente comando en la raiz del proyecto:

```bash
php exporter
```

Este comando generara los archivos en el directorio `dist/`.

## Proceso de despliegue

### Preparacion de archivos

Una vez compilado, el directorio `dist/` contiene dos archivos esenciales:

1. `exporter.php` - La aplicacion completa
2. `vendor.phar` - Las dependencias

### Configuracion del servidor

#### Configuracion de ruta base

La variable `$basePath` en `exporter.php` determina el directorio raiz que sera explorado por la aplicacion. Por defecto, se establece en `__DIR__`, que apunta al directorio donde reside el archivo.

Para modificar el directorio a explorar, puede:
1. Editar la variable `$basePathSuffix` en `exporter` antes de compilar, o
2. Editar la linea correspondiente en `dist/exporter.php` despues de compilar:

```php
$basePath = __DIR__; // Apunta al propio directorio
// Alternativa:
$basePath = '/ruta/absoluta/a/sus/proyectos';
```

#### Permisos de ejecucion

Asegurese de que el servidor web tenga permisos de:

- Lectura en el directorio `$basePath` y subdirectorios
- Escritura en el directorio temporal del sistema (para archivos de progreso)
- Ejecucion de comandos de shell (opcional, para calculo de tamanos optimizado)

### Instalacion en el servidor

1. Copie los archivos `dist/exporter.php` y `dist/vendor.phar` al servidor
2. Coloque `exporter.php` en la ubicacion deseada (puede renombrarlo si es necesario)
3. Coloque `vendor.phar` en el mismo directorio que `exporter.php`
4. Configure el servidor web para servir el archivo PHP

### Servidor web

La aplicacion funciona con cualquier servidor web compatible con PHP:

- Apache con mod_php
- Nginx con PHP-FPM
- Servidores integrados de desarrollo (`php -S`)

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

## Solucion de problemas

### El comando du no esta disponible

Si el comando `du` no esta disponible o `shell_exec()` esta deshabilitado, la aplicacion utilizara automaticamente el metodo alternativo en PHP puro para calcular tamanos de carpeta. Este metodo es mas lento pero funciona en cualquier entorno PHP.

### Archivos temporales de progreso

Los archivos de progreso se almacenan en el directorio temporal del sistema (`sys_get_temp_dir()`) y se eliminan automaticamente cuando el navegador cierra la conexion o cuando se completa la descarga.

### Limites de memoria y tiempo

La aplicacion utiliza streaming para evitar cargar archivos completos en memoria. No obstante, puede ser necesario ajustar las directivas de PHP:

- `max_execution_time` - Se establece en 0 (sin limite) durante la descarga
- `memory_limit` - Ajustar si se procesan directorios con miles de archivos
