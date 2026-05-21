<?php
use ZipStream\ZipStream;

$basePath = __DIR__;
$action = $_GET['op'] ?? null;

// ─── API: Browse subfolders with sizes ───
if ($action === 'browse') {
    header('Content-Type: application/json');
    set_time_limit(30);
    $root = basename($_GET['r'] ?? '');
    $subpath = trim($_GET['s'] ?? '', '/ ');
    $rootPath = $basePath . DIRECTORY_SEPARATOR . $root;
    $realRoot = realpath($rootPath);

    if (!$root || !$realRoot || !is_dir($realRoot)) {
        echo json_encode(['error' => 'Carpeta no encontrada']);
        exit;
    }

    $browsePath = $realRoot;
    if ($subpath !== '') {
        $candidate = $realRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subpath);
        $realBrowse = realpath($candidate);
        if ($realBrowse === false || strpos($realBrowse, $realRoot) !== 0) {
            echo json_encode(['error' => 'Ruta inválida']);
            exit;
        }
        $browsePath = $realBrowse;
        $subpath = trim(substr($realBrowse, strlen($realRoot)), DIRECTORY_SEPARATOR);
        $subpath = str_replace(DIRECTORY_SEPARATOR, '/', $subpath);
    }

    $folders = [];
    foreach (scandir($browsePath) as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = $browsePath . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($itemPath)) continue;

        $relPath = ($subpath !== '' ? $subpath . '/' : '') . $item;
        $folders[] = [
            'name' => $item,
            'path' => $relPath,
            'hasChildren' => hasSubfolders($itemPath),
        ];
    }

    $sizes = getFolderSizes($browsePath, $folders);
    foreach ($folders as &$f) {
        $f['size'] = $sizes[$f['name']] ?? 0;
    }
    unset($f);

    usort($folders, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    echo json_encode(['folders' => $folders, 'subpath' => $subpath]);
    exit;
}

// ─── API: Download Stream ───
if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    if ($loginEnabled) session_write_close();
    ignore_user_abort(true);

    // Deshabilitar compresión de salida del servidor para evitar conflictos con el ZIP stream
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');

    $folder = basename($_POST['folder'] ?? '');
    $excludeJson = $_POST['exclude'] ?? '[]';
    $exclude = json_decode($excludeJson, true) ?? [];

    $folderPath = $basePath . DIRECTORY_SEPARATOR . $folder;

    if (!$folder || !is_dir($folderPath)) {
        die('Carpeta no encontrada o ruta inválida.');
    }

    $exclude = array_map(function($p) {
        return trim($p, '/ ');
    }, array_filter((array)$exclude));

    // Crear iterador personalizado que maneja directorios sin permisos
    $safeIterator = new class($folderPath, RecursiveDirectoryIterator::SKIP_DOTS) extends RecursiveDirectoryIterator {
        public function getChildren() {
            try {
                return parent::getChildren();
            } catch (\UnexpectedValueException $e) {
                // Directorio sin permisos, retornar un iterador vacío
                return new RecursiveArrayIterator([]);
            }
        }
    };

    $iterator = new RecursiveIteratorIterator(
        $safeIterator,
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    // Contar archivos sin cargar en memoria para el progreso
    $total = 0;
    $iterator->rewind();
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $relative = substr($file->getRealPath(), strlen(realpath($folderPath)) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        $excluded = false;
        foreach ($exclude as $ex) {
            if ($relative === $ex || strpos($relative, $ex . '/') === 0) {
                $excluded = true;
                break;
            }
        }
        if (!$excluded) {
            $total++;
        }
    }

    if ($total === 0) {
        die('No hay archivos para comprimir tras aplicar exclusiones.');
    }

    $token = preg_replace('/[^a-z0-9_.]/i', '', $_GET['t'] ?? '');
    $progressFile = '';
    if ($token) {
        $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.progress';
        file_put_contents($progressFile, json_encode([
            'status' => 'compressing', 'total' => $total, 'done' => 0
        ]));

        // Limpiar archivos de progreso antiguos (más de 1 hora)
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip_*.progress') as $oldFile) {
            if (filemtime($oldFile) < time() - 3600) {
                @unlink($oldFile);
            }
        }
    }

    // Opciones para ZipStream v0.5.2
    $options = array(
        'sendHttpHeaders' => true,
        'zeroHeader' => true,
    );

    // Inicia la transmisión en vivo. El navegador recibirá el ZIP dinámicamente.
    $zip = new ZipStream($folder . '.zip', $options);

    $done = 0;
    $failed = 0;
    $lastWrite = 0;

    // Iterar directamente sobre el directorio sin cargar array en memoria
    $iterator->rewind();
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $relative = substr($file->getRealPath(), strlen(realpath($folderPath)) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        $excluded = false;
        foreach ($exclude as $ex) {
            if ($relative === $ex || strpos($relative, $ex . '/') === 0) {
                $excluded = true;
                break;
            }
        }
        if ($excluded) continue;

        $realPath = $file->getRealPath();
        if (!file_exists($realPath) || !is_readable($realPath)) {
            $failed++;
            continue;
        }

        try {
            $zip->addFileFromPath($relative, $realPath);
        } catch (\Exception $e) {
            $failed++;
            error_log("ZipStream error al añadir archivo: {$realPath} - {$e->getMessage()}");
        }

        $done++;
        if ($progressFile) {
            $now = microtime(true);
            if ($done % 50 === 0 || $done === $total || ($now - $lastWrite) > 0.5) {
                file_put_contents($progressFile, json_encode([
                    'status' => 'compressing', 'total' => $total, 'done' => $done
                ]));
                $lastWrite = $now;

                // Verificar si el cliente sigue conectado
                if (connection_aborted()) {
                    break;
                }
            }
        }
    }

    $zip->finish();

    if ($progressFile) {
        file_put_contents($progressFile, json_encode([
            'status' => 'done',
            'total' => $total,
            'done' => $done,
            'failed' => $failed
        ]));
    }
    exit;
}

// ─── API: Progress ───
if ($action === 'progress') {
    header('Content-Type: application/json');
    $token = preg_replace('/[^a-z0-9_.]/i', '', $_GET['t'] ?? '');
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.progress';

    if (!file_exists($progressFile)) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    $data = json_decode(file_get_contents($progressFile), true);

    // Si la descarga terminó (done o error), eliminar el archivo de progreso después de enviarlo
    if (isset($data['status']) && ($data['status'] === 'done' || $data['status'] === 'error')) {
        // Marcar para limpieza en el próximo request
        $data['_cleanup'] = true;
    }

    echo json_encode($data);

    // Limpiar archivos de progreso antiguos (más de 10 minutos)
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip_*.progress') as $oldFile) {
        if (filemtime($oldFile) < time() - 600) {
            @unlink($oldFile);
        }
    }

    // Si terminó, eliminar este archivo específico
    if (isset($data['status']) && ($data['status'] === 'done' || $data['status'] === 'error')) {
        @unlink($progressFile);
    }
    exit;
}
