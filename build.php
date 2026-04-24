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

// 1. Read components
echo "Buscando componentes...\n";
$css = trim(file_get_contents($srcDir . '/css/style.css'));
$js = trim(file_get_contents($srcDir . '/js/app.js'));
$api = trim(str_replace('<?php', '', file_get_contents($srcDir . '/php/api.php')));
$helpers = trim(str_replace('<?php', '', file_get_contents($srcDir . '/php/helpers.php')));
$template = file_get_contents($srcDir . '/php/template.phtml');

// 2. Wrap HTML inside PHP properly with injected styles
echo "Empalmando módulos...\n";

// Replace variables in template
$html = str_replace('{{CSS}}', $css, $template);
$html = str_replace('{{JS}}', $js, $html);

// 3. Assemble the final php file
$finalCode = "<?php\n\n";
$finalCode .= "// ─── CARGA DE COMPOSER LIBRERÍAS (Zips/Etc) ───\n";
$finalCode .= "if (file_exists(__DIR__ . '/vendor.phar')) {\n    require_once __DIR__ . '/vendor.phar';\n}\n\n";
$finalCode .= "// ─── Helpers ───\n" . $helpers . "\n\n";
$finalCode .= "// ─── API Lógica ───\n" . $api . "\n\n";
$finalCode .= "?>\n"; // Close PHP before dumping template
$finalCode .= $html;

// 4. Save to dist directory
$distIndex = $distDir . '/index.php';
file_put_contents($distIndex, $finalCode);

// 5. Copy the phar locally into dist
copy(__DIR__ . '/vendor.phar', $distDir . '/vendor.phar');

echo "\n¡ÉXITO! Compilación terminada.\n";
echo "=> Tu aplicación minificada está lista en: \n   " . $distDir . "\n";
echo "Solo necesitas copiar 'dist/index.php' y 'dist/vendor.phar' a tu servidor y listo.\n";
