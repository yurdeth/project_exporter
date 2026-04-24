<?php
function hasSubfolders($path) {
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir($path . DIRECTORY_SEPARATOR . $item)) return true;
    }
    return false;
}

function getFolderSizes($parentPath, $folders) {
    $sizes = [];
    if (empty($folders)) return $sizes;

    if (function_exists('shell_exec')) {
        $cmd = 'du -sb';
        foreach ($folders as $f) {
            $cmd .= ' ' . escapeshellarg($parentPath . DIRECTORY_SEPARATOR . $f['name']);
        }
        $cmd .= ' 2>/dev/null';
        $output = shell_exec($cmd);
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                $parts = explode("\t", $line, 2);
                if (count($parts) === 2) {
                    $sizes[basename($parts[1])] = (int)$parts[0];
                }
            }
            if (!empty($sizes)) return $sizes;
        }
    }

    foreach ($folders as $f) {
        $total = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($parentPath . DIRECTORY_SEPARATOR . $f['name'], RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) { if ($file->isFile()) $total += $file->getSize(); }
        $sizes[$f['name']] = $total;
    }
    return $sizes;
}
