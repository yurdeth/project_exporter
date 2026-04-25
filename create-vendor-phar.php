<?php
// Script para crear vendor.phar con las dependencias de ZipStream v1.2.0

$pharFile = __DIR__ . '/vendor.phar';
$vendorDir = __DIR__ . '/vendor';

// Eliminar phar anterior si existe
if (file_exists($pharFile)) {
    echo "Eliminando vendor.phar anterior...\n";
    unlink($pharFile);
}

echo "Creando vendor.phar...\n";

// Crear el phar
$phar = new Phar($pharFile, 0, 'vendor.phar');
$phar->startBuffering();

// Agregar archivos del vendor recursivamente
echo "Agregando archivos de dependencias...\n";
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($vendorDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$count = 0;
/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $localPath = str_replace($vendorDir . '/', '', $file->getPathname());
        $phar->addFile($file->getPathname(), $localPath);
        $count++;
    }
}

echo "Archivos agregados al phar: $count\n";

// Verificar antes de cerrar buffer
echo "\nVerificando archivos clave antes de cerrar...\n";
echo "- autoload.php: " . (isset($phar['autoload.php']) ? '[OK]' : '[FALTA]') . "\n";
echo "- composer/autoload_classmap.php: " . (isset($phar['composer/autoload_classmap.php']) ? '[OK]' : '[FALTA]') . "\n";
echo "- maennchen/zipstream-php/src/ZipStream.php: " . (isset($phar['maennchen/zipstream-php/src/ZipStream.php']) ? '[OK]' : '[FALTA]') . "\n";

// Crear stub
$stub = <<<PHP
<?php
// Phar stub para vendor.phar
if (!class_exists('Phar')) {
    return;
}

Phar::mapPhar('vendor.phar');

// Cargar autoloader
require 'phar://vendor.phar/autoload.php';

__HALT_COMPILER();
PHP;

$phar->setStub($stub);
$phar->stopBuffering();

echo "\nvendor.phar creado exitosamente!\n";
echo "Ubicación: $pharFile\n";
echo "Tamaño: " . number_format(filesize($pharFile)) . " bytes\n";

// Verificar que funciona
echo "\nVerificando que el phar funciona correctamente...\n";
try {
    // Incluir el phar para probarlo
    require $pharFile;

    if (class_exists('ZipStream\ZipStream')) {
        echo "ZipStream disponible correctamente\n";
    } else {
        echo "ZipStream no encontrado\n";
        echo "Clases disponibles en namespace ZipStream:\n";
        foreach (get_declared_classes() as $class) {
            if (strpos($class, 'ZipStream') !== false) {
                echo "  - $class\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Error al cargar phar: " . $e->getMessage() . "\n";
}
