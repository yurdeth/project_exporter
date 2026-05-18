<?php
$srcDir = __DIR__ . '/src';
$distDir = __DIR__ . '/dist';

if (!is_dir($distDir)) {
    mkdir($distDir, 0777, true);
}

// Ensure the phar exists
if (!file_exists(__DIR__ . '/vendor.phar')) {
    die("Error: vendor.phar no existe. Compílalo primero.\n");
}

// ──— CONFIGURACIÓN DE RUTA BASE ───
// Especifica la ruta relativa desde __DIR__ hacia la carpeta a exportar
$basePathSuffix = ''; // Dejar vacío para usar __DIR__ únicamente

// 1. Read components
echo "Buscando componentes...\n";
$css = trim(file_get_contents($srcDir . '/css/style.css'));
$js = trim(file_get_contents($srcDir . '/js/app.js'));

// Remove <?php tags and $basePath declarations from source files
$api = trim(str_replace('<?php', '', file_get_contents($srcDir . '/php/api.php')));
$helpers = trim(str_replace('<?php', '', file_get_contents($srcDir . '/php/helpers.php')));
$template = file_get_contents($srcDir . '/php/template.phtml');

// Remove $basePath declarations to avoid duplication
$api = preg_replace('/^\s*\$basePath\s*=\s*[^;]+;\s*\n?/m', '', $api);
$template = preg_replace('/^\s*\$basePath\s*=\s*[^;]+;\s*\n?/m', '', $template);

// 2. Wrap HTML inside PHP properly with injected styles
echo "Empalmando módulos...\n";

// Replace variables in template
$html = str_replace('{{CSS}}', $css, $template);
$html = str_replace('{{JS}}', $js, $html);

// 3. Assemble the final php file
$finalCode = "<?php\n\n";
$finalCode .= "// ─── CONFIGURACIÓN DE RUTA BASE ───\n";
$finalCode .= "\$basePath = __DIR__ . " . var_export($basePathSuffix, true) . ";\n\n";

$finalCode .= "// ─── CARGA DE COMPOSER LIBRERÍAS (Zips/Etc) ───\n";
$finalCode .= "if (file_exists(__DIR__ . '/vendor.phar')) {\n    require_once __DIR__ . '/vendor.phar';\n}\n\n";
$finalCode .= "// ─── Helpers ───\n" . $helpers . "\n\n";
$finalCode .= "// ─── API Lógica ───\n" . $api . "\n\n";
$finalCode .= "?>\n"; // Close PHP before dumping template
$finalCode .= $html;

// 4. Save to dist directory
$distIndex = $distDir . '/exporter.php';
file_put_contents($distIndex, $finalCode);

// 5. Copy the phar locally into dist
copy(__DIR__ . '/vendor.phar', $distDir . '/vendor.phar');

echo "\n¡ÉXITO! Compilación terminada.\n";
echo "=> Tu aplicación minificada está lista en: \n   " . $distDir . "\n";
echo "=> Ruta base configurada como: __DIR__" . ($basePathSuffix ? " . '$basePathSuffix'" : "") . "\n";
echo "Solo necesitas copiar 'dist/exporter.php' y 'dist/vendor.phar' a tu servidor y listo.\n";
