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
    const token = 'zip_' + Math.random().toString(36).substr(2, 9);
    
    document.getElementById('explorerOverlay').classList.remove('active');
    
    const pOverlay = document.getElementById('progressOverlay');
    const pName = document.getElementById('progressName');
    const pStatus = document.getElementById('progressStatus');
    const pFill = document.getElementById('progressFill');
    const pDetail = document.getElementById('progressDetail');
    const pClose = document.getElementById('progressClose');

    pName.textContent = currentRoot;
    pStatus.innerHTML = '<span class="spinner"></span>Iniciando compresión temporal...';
    pFill.style.width = '0%';
    pFill.className = 'progress-fill';
    pDetail.textContent = 'Calculando progreso...';
    pClose.style.display = 'none';
    pOverlay.classList.add('active');

    // Create a hidden form to trigger the native download stream via POST
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '?action=download&token=' + token;
    
    const inputFolder = document.createElement('input');
    inputFolder.type = 'hidden';
    inputFolder.name = 'folder';
    inputFolder.value = currentRoot;
    form.appendChild(inputFolder);
    
    const inputExclude = document.createElement('input');
    inputExclude.type = 'hidden';
    inputExclude.name = 'exclude';
    inputExclude.value = JSON.stringify(excludeArr);
    form.appendChild(inputExclude);
    
    document.body.appendChild(form);
    form.submit();
    setTimeout(() => document.body.removeChild(form), 1000);
    
    pollProgress(token, exclusions.size);
}

function pollProgress(token, exclCount) {
    const pStatus = document.getElementById('progressStatus');
    const pFill = document.getElementById('progressFill');
    const pDetail = document.getElementById('progressDetail');
    const pClose = document.getElementById('progressClose');

    const poll = () => {
        if (!document.getElementById('progressOverlay').classList.contains('active')) return;

        fetch('?action=progress&token=' + encodeURIComponent(token))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'compressing') {
                    const pct = data.total > 0 ? Math.round((data.done / data.total) * 100) : 0;
                    pFill.style.width = pct + '%';
                    pStatus.innerHTML = '<span class="spinner"></span>Comprimiendo y enviando (' + pct + '%)...';
                    let detailText = data.done + ' / ' + data.total + ' archivos transmitidos.';
                    if (exclCount) detailText += '\nExcluyendo ' + exclCount + ' carpeta(s).';
                    if (data.failed > 0) detailText += '\n⚠️ ' + data.failed + ' archivos no pudieron leerse.';
                    pDetail.textContent = detailText;
                    setTimeout(poll, 400);
                } else if (data.status === 'done') {
                    pFill.style.width = '100%';
                    pFill.className = 'progress-fill done';
                    pStatus.textContent = '¡Completado!';
                    let detailText = data.total + ' archivos transmitidos correctamente al navegador.';
                    if (data.failed > 0) {
                        detailText += '\n⚠️ ' + data.failed + ' archivos no pudieron leerse y fueron omitidos.';
                        pFill.className = 'progress-fill done warning';
                    }
                    pDetail.textContent = detailText;
                    pClose.style.display = '';
                } else if (data.status === 'error') {
                    pFill.className = 'progress-fill error';
                    pFill.style.width = '100%';
                    pStatus.textContent = 'Error: ' + (data.error || 'Desconocido');
                    pClose.style.display = '';
                } else {
                    setTimeout(poll, 800);
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
