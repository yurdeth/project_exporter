<?php
$basePath = __DIR__;
$action = $_GET['action'] ?? null;

// ─── API: Browse subfolders with sizes ───
if ($action === 'browse') {
    header('Content-Type: application/json');
    set_time_limit(30);

    $root = basename($_GET['root'] ?? '');
    $subpath = trim($_GET['subpath'] ?? '', '/ ');
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

    usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    echo json_encode(['folders' => $folders, 'subpath' => $subpath]);
    exit;
}

// ─── API: Prepare ZIP with exclusions ───
if ($action === 'prepare') {
    header('Content-Type: application/json');
    set_time_limit(0);
    ignore_user_abort(false);

    $input = json_decode(file_get_contents('php://input'), true);
    $folder = basename($input['folder'] ?? '');
    $exclude = $input['exclude'] ?? [];
    $folderPath = $basePath . DIRECTORY_SEPARATOR . $folder;

    if (!$folder || !is_dir($folderPath)) {
        echo json_encode(['error' => 'Carpeta no encontrada']);
        exit;
    }

    $exclude = array_map(fn($p) => trim($p, '/ '), array_filter((array)$exclude));

    $fileList = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
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
            $fileList[] = ['real' => $file->getRealPath(), 'relative' => $relative];
        }
    }

    $token = uniqid('zip_', true);
    $zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.zip';
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.progress';

    file_put_contents($progressFile, json_encode([
        'status' => 'compressing', 'total' => count($fileList), 'done' => 0,
        'zipFile' => $zipFile, 'folder' => $folder,
    ]));

    if (function_exists('fastcgi_finish_request')) {
        echo json_encode(['token' => $token, 'total' => count($fileList)]);
        fastcgi_finish_request();
        buildZip($fileList, $zipFile, $progressFile, $folder);
    } else {
        buildZip($fileList, $zipFile, $progressFile, $folder);
        echo json_encode(['token' => $token, 'total' => count($fileList)]);
    }
    exit;
}

// ─── API: Progress ───
if ($action === 'progress') {
    header('Content-Type: application/json');
    $token = preg_replace('/[^a-z0-9_.]/i', '', $_GET['token'] ?? '');
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.progress';

    if (!file_exists($progressFile)) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    $data = json_decode(file_get_contents($progressFile), true);
    if ($data['status'] === 'done') {
        $data['downloadUrl'] = '?action=download&token=' . $token;
        $data['size'] = file_exists($data['zipFile'] ?? '') ? filesize($data['zipFile']) : 0;
    }
    echo json_encode($data);
    exit;
}

// ─── API: Download ───
if ($action === 'download') {
    $token = preg_replace('/[^a-z0-9_.]/i', '', $_GET['token'] ?? '');
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.progress';

    if (!file_exists($progressFile)) {
        http_response_code(404);
        die('Descarga no encontrada.');
    }

    $data = json_decode(file_get_contents($progressFile), true);
    $zipFile = $data['zipFile'] ?? '';
    $folder = $data['folder'] ?? 'download';

    if (!file_exists($zipFile)) {
        http_response_code(404);
        die('Archivo ZIP no encontrado.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $folder . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($zipFile);

    @unlink($zipFile);
    @unlink($progressFile);
    exit;
}

// ─── Helpers ───
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

function buildZip(array $fileList, string $zipFile, string $progressFile, string $folder): void {
    if (!class_exists('ZipArchive')) {
        file_put_contents($progressFile, json_encode([
            'status' => 'error', 'error' => 'ZipArchive no disponible',
            'total' => count($fileList), 'done' => 0, 'zipFile' => $zipFile, 'folder' => $folder,
        ]));
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        file_put_contents($progressFile, json_encode([
            'status' => 'error', 'error' => 'No se pudo crear el ZIP',
            'total' => count($fileList), 'done' => 0, 'zipFile' => $zipFile, 'folder' => $folder,
        ]));
        return;
    }

    $total = count($fileList);
    $done = 0;
    $lastWrite = 0;

    foreach ($fileList as $fileInfo) {
        $zip->addFile($fileInfo['real'], $fileInfo['relative']);
        $done++;
        $now = microtime(true);
        if ($done % 50 === 0 || $done === $total || ($now - $lastWrite) > 0.5) {
            file_put_contents($progressFile, json_encode([
                'status' => 'compressing', 'total' => $total, 'done' => $done,
                'zipFile' => $zipFile, 'folder' => $folder,
            ]));
            $lastWrite = $now;
        }
    }

    $zip->close();
    file_put_contents($progressFile, json_encode([
        'status' => 'done', 'total' => $total, 'done' => $total,
        'zipFile' => $zipFile, 'folder' => $folder,
    ]));
}

// ─── Main page ───
$folders = [];
foreach (scandir($basePath) as $item) {
    if ($item === '.' || $item === '..' || $item === 'index.php') continue;
    if (is_dir($basePath . DIRECTORY_SEPARATOR . $item)) $folders[] = $item;
}
sort($folders);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proyectos</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a; color: #e2e8f0;
            min-height: 100vh; padding: 2rem;
        }
        .top-bar {
            display: flex; align-items: center; gap: 1rem;
            margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        h1 { font-size: 1.5rem; color: #f8fafc; display: flex; align-items: center; gap: 0.5rem; }
        .count {
            font-size: 0.75rem; background: #334155;
            padding: 0.15rem 0.5rem; border-radius: 9999px; color: #94a3b8;
        }
        .search-input {
            padding: 0.5rem 1rem; background: #1e293b; border: 1px solid #334155;
            border-radius: 6px; color: #e2e8f0; font-size: 0.85rem;
            outline: none; width: 260px; margin-left: auto;
        }
        .search-input:focus { border-color: #6366f1; }
        .search-input::placeholder { color: #64748b; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1rem;
        }
        .card {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 8px; padding: 1rem 1.25rem;
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; transition: all 0.15s ease; color: inherit;
        }
        .card:hover { background: #334155; border-color: #6366f1; transform: translateY(-1px); }
        .card.hidden { display: none; }
        .icon { font-size: 1.5rem; flex-shrink: 0; }
        .info { min-width: 0; }
        .name {
            font-weight: 600; font-size: 0.9rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .action { font-size: 0.7rem; color: #6366f1; margin-top: 0.15rem; }
        .empty { text-align: center; padding: 3rem; color: #64748b; }

        /* Overlay */
        .overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.6); z-index: 100;
            justify-content: center; align-items: center;
        }
        .overlay.active { display: flex; }

        /* Explorer modal */
        .explorer {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 12px; width: 90%; max-width: 560px;
            display: flex; flex-direction: column; max-height: 80vh;
        }
        .explorer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; border-bottom: 1px solid #334155;
        }
        .explorer-header h2 { font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .explorer-close {
            background: none; border: none; color: #94a3b8;
            font-size: 1.3rem; cursor: pointer; padding: 0.25rem;
        }
        .explorer-close:hover { color: #f8fafc; }

        /* Breadcrumb */
        .breadcrumb {
            padding: 0.6rem 1.25rem; border-bottom: 1px solid #334155;
            display: flex; gap: 0.25rem; flex-wrap: wrap;
            font-size: 0.75rem; color: #94a3b8;
        }
        .breadcrumb span { cursor: pointer; color: #6366f1; }
        .breadcrumb span:hover { text-decoration: underline; }
        .breadcrumb .sep { color: #475569; cursor: default; }
        .breadcrumb .current { color: #e2e8f0; cursor: default; }
        .breadcrumb .current:hover { text-decoration: none; }

        /* Sort bar */
        .sort-bar {
            display: flex; align-items: center; gap: 0;
            padding: 0.35rem 1.25rem; border-bottom: 1px solid #334155;
        }
        .sort-btn {
            background: none; border: 1px solid #334155;
            color: #94a3b8; padding: 0.2rem 0.5rem;
            font-size: 0.6rem; cursor: pointer; transition: all 0.1s;
        }
        .sort-btn:first-child { border-radius: 4px 0 0 4px; }
        .sort-btn:last-child { border-radius: 0 4px 4px 0; }
        .sort-btn + .sort-btn { border-left: none; }
        .sort-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; }
        .sort-btn:hover:not(.active) { background: #253349; color: #e2e8f0; }

        /* Master toggle row */
        .master-row {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.4rem 1.25rem; border-bottom: 1px solid #1e293b;
            background: #162032; font-size: 0.72rem; color: #94a3b8;
        }
        .master-row input[type="checkbox"] {
            accent-color: #6366f1; width: 14px; height: 14px; cursor: pointer;
        }
        .master-row label { cursor: pointer; user-select: none; }

        /* Folder list */
        .explorer-body { flex: 1; overflow-y: auto; padding: 0.5rem 0; }
        .folder-row {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.55rem 1.25rem; cursor: default; transition: background 0.1s;
        }
        .folder-row:hover { background: #253349; }
        .folder-row.excluded-parent { opacity: 0.4; }
        .folder-row.partial {
            background: rgba(251, 191, 36, 0.06);
            border-left: 2px solid rgba(251, 191, 36, 0.4);
        }
        .folder-row.partial:hover { background: rgba(251, 191, 36, 0.1); }
        .folder-row input[type="checkbox"] {
            accent-color: #6366f1; width: 15px; height: 15px;
            cursor: pointer; flex-shrink: 0;
        }
        .folder-row input[type="checkbox"]:disabled { cursor: not-allowed; }
        .folder-row .ficon { font-size: 1.1rem; flex-shrink: 0; }
        .folder-row .fname {
            flex: 1; font-size: 0.85rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .folder-row .fname.navigable { cursor: pointer; color: #93c5fd; }
        .folder-row .fname.navigable:hover { color: #bfdbfe; text-decoration: underline; }
        .folder-row .fname.partial-label {
            color: #fbbf24; font-size: 0.6rem; margin-left: 0.4rem; font-weight: 400;
        }
        .folder-row .fsize { font-size: 0.75rem; color: #94a3b8; flex-shrink: 0; min-width: 55px; text-align: right; }
        .folder-row .farrow { color: #475569; font-size: 0.8rem; flex-shrink: 0; width: 20px; text-align: center; }

        /* Footer */
        .explorer-footer {
            padding: 0.75rem 1.25rem; border-top: 1px solid #334155;
            display: flex; align-items: center; justify-content: space-between;
        }
        .explorer-footer .summary { font-size: 0.75rem; color: #94a3b8; }
        .btn-download {
            padding: 0.45rem 1.2rem; background: #6366f1; color: #fff;
            border: none; border-radius: 6px; font-size: 0.8rem;
            cursor: pointer; font-weight: 500;
        }
        .btn-download:hover { background: #818cf8; }
        .btn-download:disabled { background: #334155; color: #64748b; cursor: not-allowed; }

        .empty-state { padding: 2rem; text-align: center; color: #64748b; font-size: 0.85rem; }
        .loading-state { padding: 2rem; text-align: center; color: #94a3b8; font-size: 0.85rem; }

        /* Progress modal */
        .progress-modal {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 12px; padding: 2rem; width: 90%; max-width: 400px; text-align: center;
        }
        .progress-modal h2 { font-size: 1.1rem; margin-bottom: 0.5rem; }
        .progress-modal .folder-name { color: #6366f1; font-weight: 600; word-break: break-all; }
        .progress-modal .status-text { color: #94a3b8; font-size: 0.85rem; margin: 1rem 0; }
        .progress-bar { width: 100%; height: 6px; background: #334155; border-radius: 3px; overflow: hidden; margin-bottom: 1rem; }
        .progress-fill { height: 100%; background: #6366f1; border-radius: 3px; transition: width 0.3s ease; width: 0%; }
        .progress-fill.error { background: #ef4444; }
        .progress-fill.done { background: #22c55e; }
        .progress-modal .detail { font-size: 0.75rem; color: #64748b; }
        .progress-modal button {
            margin-top: 1rem; padding: 0.5rem 1.5rem;
            border: 1px solid #334155; background: #0f172a; color: #e2e8f0;
            border-radius: 6px; cursor: pointer; font-size: 0.85rem;
        }
        .progress-modal button:hover { background: #334155; }
        .spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid #334155; border-top-color: #6366f1;
            border-radius: 50%; animation: spin 0.6s linear infinite;
            vertical-align: middle; margin-right: 0.4rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Proyectos <span class="count"><?= count($folders) ?></span></h1>
        <?php if (!empty($folders)): ?>
            <input type="text" class="search-input" id="searchInput" placeholder="Buscar proyecto..." oninput="filterProjects()">
        <?php endif ?>
    </div>

    <?php if (empty($folders)): ?>
        <div class="empty">No se encontraron carpetas de proyectos.</div>
    <?php else: ?>
        <div class="grid" id="projectGrid">
            <?php foreach ($folders as $name): ?>
                <div class="card" data-name="<?= htmlspecialchars(strtolower($name)) ?>" onclick="openExplorer('<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
                    <span class="icon">&#128193;</span>
                    <div class="info">
                        <div class="name"><?= htmlspecialchars($name) ?></div>
                        <div class="action">Click para explorar y descargar</div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <!-- Explorer modal -->
    <div class="overlay" id="explorerOverlay">
        <div class="explorer">
            <div class="explorer-header">
                <h2><span>&#128193;</span> <span id="explorerTitle"></span></h2>
                <button class="explorer-close" onclick="closeExplorer()">&times;</button>
            </div>
            <div class="breadcrumb" id="breadcrumb"></div>
            <div class="sort-bar">
                <button class="sort-btn active" data-sort="name-asc" onclick="changeSort('name-asc')">A-Z</button>
                <button class="sort-btn" data-sort="name-desc" onclick="changeSort('name-desc')">Z-A</button>
                <button class="sort-btn" data-sort="size-desc" onclick="changeSort('size-desc')">Peso ↓</button>
                <button class="sort-btn" data-sort="size-asc" onclick="changeSort('size-asc')">Peso ↑</button>
                <button class="sort-btn" data-sort="sel-first" onclick="changeSort('sel-first')">Sel. primero</button>
                <button class="sort-btn" data-sort="desel-first" onclick="changeSort('desel-first')">No sel. primero</button>
            </div>
            <div class="master-row" id="masterRow">
                <input type="checkbox" id="masterCheck" onchange="toggleAll(this.checked)">
                <label for="masterCheck" id="masterLabel">Seleccionar todo</label>
            </div>
            <div class="explorer-body" id="explorerBody">
                <div class="loading-state"><span class="spinner"></span>Cargando...</div>
            </div>
            <div class="explorer-footer">
                <span class="summary" id="explorerSummary"></span>
                <button class="btn-download" id="btnDownload" onclick="startDownload()">Descargar</button>
            </div>
        </div>
    </div>

    <!-- Progress modal -->
    <div class="overlay" id="progressOverlay">
        <div class="progress-modal">
            <h2>Comprimiendo <span class="folder-name" id="progressName"></span></h2>
            <div class="status-text" id="progressStatus"><span class="spinner"></span>Preparando...</div>
            <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
            <div class="detail" id="progressDetail"></div>
            <button id="progressClose" onclick="closeProgress()" style="display:none">Cerrar</button>
        </div>
    </div>

<script>
// ─── State ───
let currentRoot = '';
let currentSubpath = '';
let exclusions = new Set();
let rootFolderData = [];
let currentFolderData = [];
let currentSort = 'name-asc';
let folderSizeMap = {};

// ─── ESC to close ───
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('progressOverlay').classList.contains('active')) {
            closeProgress();
        } else if (document.getElementById('explorerOverlay').classList.contains('active')) {
            closeExplorer();
        }
    }
});

function formatBytes(b) {
    if (!b || b === 0) return '0 B';
    const k = 1024, s = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
}

// ─── Main page search ───
function filterProjects() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#projectGrid .card').forEach(card => {
        const name = card.getAttribute('data-name');
        card.classList.toggle('hidden', !name.includes(query));
    });
}

// ─── Sort ───
function changeSort(sort) {
    currentSort = sort;
    document.querySelectorAll('.sort-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.sort === sort);
    });
    if (currentFolderData.length > 0) {
        renderFolders(sortFolders(currentFolderData), currentSubpath);
    }
}

function sortFolders(folders) {
    const sorted = [...folders];
    switch (currentSort) {
        case 'name-asc':   sorted.sort((a, b) => a.name.localeCompare(b.name)); break;
        case 'name-desc':  sorted.sort((a, b) => b.name.localeCompare(a.name)); break;
        case 'size-desc':  sorted.sort((a, b) => b.size - a.size); break;
        case 'size-asc':   sorted.sort((a, b) => a.size - b.size); break;
        case 'sel-first':  sorted.sort((a, b) => {
            const ae = isFolderExcluded(a.path) ? 1 : 0;
            const be = isFolderExcluded(b.path) ? 1 : 0;
            return ae - be || a.name.localeCompare(b.name);
        }); break;
        case 'desel-first': sorted.sort((a, b) => {
            const ae = isFolderExcluded(a.path) ? 0 : 1;
            const be = isFolderExcluded(b.path) ? 0 : 1;
            return ae - be || a.name.localeCompare(b.name);
        }); break;
    }
    return sorted;
}

function isFolderExcluded(path) {
    return exclusions.has(path) || checkParentExcluded(path);
}

// ─── Explorer ───
function openExplorer(folder) {
    currentRoot = folder;
    currentSubpath = '';
    exclusions = new Set();
    rootFolderData = [];
    currentFolderData = [];
    folderSizeMap = {};
    currentSort = 'name-asc';

    document.getElementById('explorerTitle').textContent = folder;
    document.getElementById('explorerOverlay').classList.add('active');
    document.querySelectorAll('.sort-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.sort === 'name-asc');
    });
    loadSubfolders('');
}

function closeExplorer() {
    document.getElementById('explorerOverlay').classList.remove('active');
}

function loadSubfolders(subpath) {
    currentSubpath = subpath;
    const body = document.getElementById('explorerBody');
    body.innerHTML = '<div class="loading-state"><span class="spinner"></span>Cargando...</div>';

    fetch('?action=browse&root=' + encodeURIComponent(currentRoot) + '&subpath=' + encodeURIComponent(subpath))
        .then(r => r.json())
        .then(data => {
            if (data.error) { body.innerHTML = '<div class="empty-state">' + data.error + '</div>'; updateMasterCheckbox(); return; }

            for (const f of data.folders) {
                folderSizeMap[f.path] = f.size;
            }

            if (subpath === '') rootFolderData = data.folders;
            currentFolderData = data.folders;

            renderBreadcrumb(subpath);
            renderFolders(sortFolders(currentFolderData), subpath);
            updateSummary();
            updateMasterCheckbox();
        })
        .catch(() => {
            body.innerHTML = '<div class="empty-state">Error al cargar carpetas.</div>';
            updateMasterCheckbox();
        });
}

function renderBreadcrumb(subpath) {
    const bc = document.getElementById('breadcrumb');
    let html = '<span onclick="loadSubfolders(\'\')">' + escHtml(currentRoot) + '</span>';

    if (subpath) {
        const parts = subpath.split('/');
        let accumulated = '';
        parts.forEach((part, i) => {
            accumulated += (accumulated ? '/' : '') + part;
            html += ' <span class="sep">/</span> ';
            if (i < parts.length - 1) {
                const path = accumulated;
                html += '<span onclick="loadSubfolders(\'' + escAttr(path) + '\')">' + escHtml(part) + '</span>';
            } else {
                html += '<span class="current">' + escHtml(part) + '</span>';
            }
        });
    }
    bc.innerHTML = html;
}

function renderFolders(folders, subpath) {
    const body = document.getElementById('explorerBody');

    if (folders.length === 0) {
        body.innerHTML = '<div class="empty-state">No hay subcarpetas.</div>';
        return;
    }

    let html = '';
    for (const f of folders) {
        const isParentExcl = checkParentExcluded(f.path);
        const isExcl = isParentExcl || exclusions.has(f.path);
        const canNavigate = f.hasChildren && !isParentExcl;
        const isPartial = !isExcl && hasPartialExclusion(f.path);

        html += '<div class="folder-row' + (isParentExcl ? ' excluded-parent' : '') + (isPartial ? ' partial' : '') + '">';
        html += '<input type="checkbox"' + (isExcl ? '' : ' checked') + (isParentExcl ? ' disabled' : '')
             + ' onchange="toggleExclusion(\'' + escAttr(f.path) + '\', this.checked)"'
             + ' title="' + (isParentExcl ? 'Padre excluido' : isPartial ? 'Seleccion parcial' : 'Incluir en descarga') + '">';
        html += '<span class="ficon">&#128193;</span>';
        html += '<span class="fname' + (canNavigate ? ' navigable' : '') + '"'
             + (canNavigate ? ' onclick="loadSubfolders(\'' + escAttr(f.path) + '\')"' : '')
             + '>' + escHtml(f.name)
             + (isPartial ? '<span class="partial-label">(parcial)</span>' : '')
             + '</span>';
        html += '<span class="fsize">' + formatBytes(f.size) + '</span>';
        html += '<span class="farrow">' + (canNavigate ? '&#8250;' : '') + '</span>';
        html += '</div>';
    }
    body.innerHTML = html;

    // Set indeterminate on partial checkboxes
    const checkboxes = body.querySelectorAll('input[type="checkbox"]');
    const folderArr = folders;
    checkboxes.forEach((cb, i) => {
        if (i < folderArr.length) {
            const f = folderArr[i];
            const isExcl = exclusions.has(f.path) || checkParentExcluded(f.path);
            if (!isExcl && hasPartialExclusion(f.path)) {
                cb.indeterminate = true;
                cb.checked = false;
            }
        }
    });
}

function checkParentExcluded(path) {
    const parts = path.split('/');
    for (let i = 1; i < parts.length; i++) {
        if (exclusions.has(parts.slice(0, i).join('/'))) return true;
    }
    return false;
}

function hasPartialExclusion(path) {
    for (const excl of exclusions) {
        if (excl.startsWith(path + '/')) return true;
    }
    return false;
}

// ─── Master checkbox ───
function toggleAll(checked) {
    for (const f of currentFolderData) {
        if (checkParentExcluded(f.path)) continue; // skip if parent excluded
        if (checked) {
            exclusions.delete(f.path);
        } else {
            exclusions.add(f.path);
        }
    }
    renderFolders(sortFolders(currentFolderData), currentSubpath);
    updateSummary();
    updateMasterCheckbox();
}

function updateMasterCheckbox() {
    const master = document.getElementById('masterCheck');
    const label = document.getElementById('masterLabel');
    const masterRow = document.getElementById('masterRow');

    if (currentFolderData.length === 0) {
        master.checked = false;
        master.indeterminate = false;
        master.disabled = true;
        label.textContent = 'Sin carpetas';
        return;
    }

    // Check if the current path's parent is excluded
    const currentParentExcluded = currentSubpath !== '' && checkParentExcluded(currentSubpath);
    if (currentParentExcluded) {
        master.checked = false;
        master.indeterminate = false;
        master.disabled = true;
        label.textContent = 'Padre excluido';
        return;
    }

    master.disabled = false;
    let selected = 0;
    let total = 0;
    for (const f of currentFolderData) {
        if (checkParentExcluded(f.path)) continue;
        total++;
        if (!exclusions.has(f.path)) selected++;
    }

    if (total === 0) {
        master.checked = false;
        master.indeterminate = false;
        master.disabled = true;
        label.textContent = 'Sin carpetas';
    } else if (selected === total) {
        master.checked = true;
        master.indeterminate = false;
        label.textContent = 'Deseleccionar todo';
    } else if (selected === 0) {
        master.checked = false;
        master.indeterminate = false;
        label.textContent = 'Seleccionar todo';
    } else {
        master.checked = false;
        master.indeterminate = true;
        label.textContent = selected + '/' + total + ' seleccionadas';
    }
}

// ─── Toggle single exclusion ───
function toggleExclusion(path, checked) {
    if (checked) {
        exclusions.delete(path);
    } else {
        exclusions.add(path);
    }
    renderFolders(sortFolders(currentFolderData), currentSubpath);
    updateSummary();
    updateMasterCheckbox();
}

// ─── Summary ───
function updateSummary() {
    const summary = document.getElementById('explorerSummary');
    const btn = document.getElementById('btnDownload');

    if (rootFolderData.length === 0) {
        summary.textContent = '';
        btn.disabled = true;
        return;
    }

    let totalSize = 0;
    let includedRoots = 0;
    const totalRoots = rootFolderData.length;

    for (const rootFolder of rootFolderData) {
        if (exclusions.has(rootFolder.path) || checkParentExcluded(rootFolder.path)) continue;

        includedRoots++;
        let effectiveSize = rootFolder.size;

        for (const exclPath of exclusions) {
            if (exclPath.startsWith(rootFolder.path + '/')) {
                let parentAlsoExcluded = false;
                for (const other of exclusions) {
                    if (other !== exclPath && exclPath.startsWith(other + '/')) {
                        parentAlsoExcluded = true;
                        break;
                    }
                }
                if (!parentAlsoExcluded && folderSizeMap[exclPath] !== undefined) {
                    effectiveSize -= folderSizeMap[exclPath];
                }
            }
        }
        totalSize += Math.max(0, effectiveSize);
    }

    if (includedRoots === 0) {
        summary.textContent = 'Nada seleccionado';
        btn.disabled = true;
    } else if (exclusions.size === 0) {
        summary.textContent = 'Todo seleccionado (' + formatBytes(totalSize) + ')';
        btn.disabled = false;
    } else {
        summary.textContent = includedRoots + '/' + totalRoots + ' carpetas, ' + exclusions.size + ' exclusion' + (exclusions.size > 1 ? 'es' : '') + ' (' + formatBytes(totalSize) + ')';
        btn.disabled = false;
    }
}

// ─── Download ───
function startDownload() {
    const excludeArr = Array.from(exclusions);

    document.getElementById('explorerOverlay').classList.remove('active');
    const pOverlay = document.getElementById('progressOverlay');
    const pName = document.getElementById('progressName');
    const pStatus = document.getElementById('progressStatus');
    const pFill = document.getElementById('progressFill');
    const pDetail = document.getElementById('progressDetail');
    const pClose = document.getElementById('progressClose');

    pName.textContent = currentRoot;
    pStatus.innerHTML = '<span class="spinner"></span>Preparando...';
    pFill.style.width = '0%';
    pFill.className = 'progress-fill';
    pDetail.textContent = excludeArr.length ? 'Excluyendo ' + excludeArr.length + ' carpeta(s)' : '';
    pClose.style.display = 'none';
    pOverlay.classList.add('active');

    fetch('?action=prepare', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ folder: currentRoot, exclude: excludeArr }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        pollProgress(data.token);
    })
    .catch(err => {
        pStatus.textContent = 'Error: ' + err.message;
        pFill.className = 'progress-fill error';
        pFill.style.width = '100%';
        pClose.style.display = '';
    });
}

function pollProgress(token) {
    const pStatus = document.getElementById('progressStatus');
    const pFill = document.getElementById('progressFill');
    const pDetail = document.getElementById('progressDetail');
    const pClose = document.getElementById('progressClose');

    const poll = () => {
        fetch('?action=progress&token=' + encodeURIComponent(token))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'compressing') {
                    const pct = data.total > 0 ? Math.round((data.done / data.total) * 100) : 0;
                    pFill.style.width = pct + '%';
                    pStatus.innerHTML = '<span class="spinner"></span>Comprimiendo...';
                    pDetail.textContent = data.done + ' / ' + data.total + ' archivos (' + pct + '%)';
                    setTimeout(poll, 500);
                } else if (data.status === 'done') {
                    pFill.style.width = '100%';
                    pFill.className = 'progress-fill done';
                    pStatus.textContent = 'Completado';
                    pDetail.textContent = (data.size ? formatBytes(data.size) : '') + ' — ' + data.total + ' archivos';
                    window.location.href = data.downloadUrl;
                    pClose.style.display = '';
                } else if (data.status === 'error') {
                    pFill.className = 'progress-fill error';
                    pFill.style.width = '100%';
                    pStatus.textContent = 'Error: ' + (data.error || 'desconocido');
                    pClose.style.display = '';
                } else {
                    pStatus.textContent = 'Esperando...';
                    setTimeout(poll, 1000);
                }
            })
            .catch(() => setTimeout(poll, 1000));
    };
    poll();
}

function closeProgress() {
    document.getElementById('progressOverlay').classList.remove('active');
}

// ─── XSS helpers ───
function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function escAttr(s) {
    return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}
</script>
</body>
</html>
