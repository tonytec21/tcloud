<?php
require_once __DIR__ . '/bootstrap.php';
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$user = Auth::user();
$csrfToken = Auth::csrfToken();
$permissions = Auth::permissions();
?><!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= e($user['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="public/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCloud — Gerenciador de Arquivos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Context Menu -->
<div class="context-menu" id="contextMenu"></div>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="app-layout">
    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon"><i class="bi bi-cloud-fill"></i></div>
                <h2>TCloud</h2>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-item active" data-nav="files" onclick="CV.navigate('files')">
                    <i class="bi bi-folder2"></i> Meus Arquivos
                </div>
                <div class="nav-item" data-nav="recent" onclick="CV.navigate('recent')">
                    <i class="bi bi-clock-history"></i> Recentes
                </div>
                <div class="nav-item" data-nav="favorites" onclick="CV.navigate('favorites')">
                    <i class="bi bi-star"></i> Favoritos
                </div>
                <div class="nav-item" data-nav="shared" onclick="CV.navigate('shared')">
                    <i class="bi bi-share"></i> Compartilhados
                </div>
                <div class="nav-item" data-nav="trash" onclick="CV.navigate('trash')">
                    <i class="bi bi-trash3"></i> Lixeira
                    <span class="badge" id="trashBadge" style="display:none">0</span>
                </div>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Ações Rápidas</div>
                <div class="nav-item" onclick="CV.showNewFolderModal()">
                    <i class="bi bi-folder-plus"></i> Nova Pasta
                </div>
                <div class="nav-item" onclick="CV.showNewFileModal()">
                    <i class="bi bi-file-earmark-plus"></i> Novo Documento
                </div>
                <div class="nav-item" onclick="CV.triggerUpload()">
                    <i class="bi bi-cloud-arrow-up"></i> Enviar Arquivo
                </div>
                <div class="nav-item" onclick="CV.triggerFolderUpload()">
                    <i class="bi bi-folder-symlink"></i> Enviar Pasta
                </div>
            </div>
            <?php if (in_array($user['role_slug'], ['master','admin'])): ?>
            <div class="nav-section">
                <div class="nav-section-title">Administração</div>
                <div class="nav-item" data-nav="admin-dashboard" onclick="CV.navigate('admin-dashboard')">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </div>
                <div class="nav-item" data-nav="admin-users" onclick="CV.navigate('admin-users')">
                    <i class="bi bi-people"></i> Usuários
                </div>
                <div class="nav-item" data-nav="admin-logs" onclick="CV.navigate('admin-logs')">
                    <i class="bi bi-journal-text"></i> Logs
                </div>
                <div class="nav-item" data-nav="admin-settings" onclick="CV.navigate('admin-settings')">
                    <i class="bi bi-sliders"></i> Configurações
                </div>
            </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="storage-info">
                <div class="storage-bar-container">
                    <span id="storageText">Calculando...</span>
                </div>
                <div class="storage-bar">
                    <div class="storage-bar-fill" id="storageBar" style="width:0%"></div>
                </div>
            </div>
            <div class="sidebar-user" onclick="CV.showSettingsModal()">
                <div class="user-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                <div class="user-info">
                    <div class="user-name"><?= e($user['full_name']) ?></div>
                    <div class="user-role"><?= e($user['role_name']) ?></div>
                </div>
                <i class="bi bi-gear" style="color:var(--text-muted)"></i>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn btn-ghost btn-icon btn-menu-toggle" onclick="CV.toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <div class="breadcrumbs" id="breadcrumbs">
                    <div class="crumb active"><i class="bi bi-house-door"></i> Meus Arquivos</div>
                </div>
            </div>
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" placeholder="Pesquisar arquivos e pastas..." oninput="CV.debounceSearch(this.value)">
            </div>
            <div class="topbar-right">
                <button class="btn btn-ghost btn-icon" onclick="CV.refresh()" title="Atualizar">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="btn btn-ghost btn-icon" id="themeToggle" onclick="CV.toggleTheme()" title="Alternar tema">
                    <i class="bi bi-moon-stars"></i>
                </button>
                <button class="btn btn-ghost btn-icon" onclick="CV.logout()" title="Sair">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar" id="toolbar">
            <div class="toolbar-group">
                <button class="btn btn-primary btn-sm" onclick="CV.triggerUpload()">
                    <i class="bi bi-cloud-arrow-up"></i> Enviar
                </button>
                <button class="btn btn-secondary btn-sm" onclick="CV.showNewFolderModal()">
                    <i class="bi bi-folder-plus"></i> Nova Pasta
                </button>
                <button class="btn btn-secondary btn-sm" onclick="CV.showNewFileModal()">
                    <i class="bi bi-file-earmark-plus"></i> Novo Documento
                </button>
            </div>
            <div class="toolbar-divider"></div>
            <div class="toolbar-group" id="batchActions" style="display:none">
                <button class="btn btn-secondary btn-sm" onclick="CV.batchMove()"><i class="bi bi-arrows-move"></i> Mover</button>
                <button class="btn btn-secondary btn-sm" onclick="CV.batchCopy()"><i class="bi bi-copy"></i> Copiar</button>
                <button class="btn btn-secondary btn-sm" onclick="CV.batchDownload()"><i class="bi bi-download"></i> Baixar</button>
                <button class="btn btn-danger btn-sm" onclick="CV.batchTrash()"><i class="bi bi-trash3"></i> Excluir</button>
                <span class="selection-info" id="selectionInfo"></span>
            </div>
            <div style="flex:1"></div>
            <select class="sort-select" id="sortSelect" onchange="CV.changeSort(this.value)">
                <option value="name-asc">Nome A-Z</option>
                <option value="name-desc">Nome Z-A</option>
                <option value="date-desc">Mais recente</option>
                <option value="date-asc">Mais antigo</option>
                <option value="size-desc">Maior</option>
                <option value="size-asc">Menor</option>
                <option value="type-asc">Tipo</option>
            </select>
            <div class="view-toggle">
                <button id="viewList" class="active" onclick="CV.setView('list')" title="Lista"><i class="bi bi-list-ul"></i></button>
                <button id="viewGrid" onclick="CV.setView('grid')" title="Grade"><i class="bi bi-grid-3x3-gap"></i></button>
            </div>
        </div>

        <!-- File Area -->
        <div class="file-area" id="fileArea">
            <div id="fileContent">
                <!-- Conteúdo carregado via JS -->
            </div>
            <div id="lassoRect" style="display:none;position:absolute;border:1px solid var(--accent);background:rgba(109,140,255,0.12);pointer-events:none;z-index:50;border-radius:2px"></div>
        </div>
    </main>

    <!-- Properties Panel -->
    <aside class="properties-panel" id="propertiesPanel"></aside>
</div>

<!-- Upload Panel -->
<div class="upload-panel" id="uploadPanel">
    <div class="upload-panel-header" onclick="CV.toggleUploadExpand()">
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
            <i class="bi bi-cloud-arrow-up" id="uqIcon" style="font-size:18px;color:var(--accent)"></i>
            <div style="flex:1;min-width:0">
                <div id="uqHeaderTitle" style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Uploads</div>
                <div class="upload-progress" style="margin-top:4px"><div class="upload-progress-bar" id="uqHeaderBar" style="width:0%"></div></div>
            </div>
            <span id="uqHeaderPct" style="font-size:12px;font-weight:600;color:var(--accent);min-width:36px;text-align:right"></span>
        </div>
        <div style="display:flex;gap:2px;align-items:center;margin-left:8px" onclick="event.stopPropagation()">
            <button class="btn btn-ghost btn-icon" id="uqPauseBtn" onclick="CV.toggleUploadPause()" title="Pausar" style="width:28px;height:28px;display:none">
                <i class="bi bi-pause-fill"></i>
            </button>
            <button class="btn btn-ghost btn-icon" id="uqCancelBtn" onclick="CV.cancelUpload()" title="Cancelar" style="width:28px;height:28px;display:none">
                <i class="bi bi-x-lg" style="color:var(--danger)"></i>
            </button>
            <button class="btn btn-ghost btn-icon" onclick="CV.toggleUploadExpand()" id="uqExpandBtn" style="width:28px;height:28px">
                <i class="bi bi-chevron-up"></i>
            </button>
            <button class="btn btn-ghost btn-icon" onclick="CV.closeUploadPanel()" style="width:28px;height:28px">
                <i class="bi bi-x"></i>
            </button>
        </div>
    </div>
    <div class="upload-panel-body" id="uploadPanelBody" style="display:none">
        <div id="uqStats" style="display:none;padding:10px 14px;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border);display:flex;gap:16px">
            <span id="uqStatsText"></span>
        </div>
        <div id="uqFileList"></div>
    </div>
</div>

<!-- Hidden file input -->
<input type="file" id="fileInput" multiple style="display:none" onchange="CV.handleFileSelect(this.files)">
<input type="file" id="folderInput" webkitdirectory multiple style="display:none" onchange="CV.handleFileSelect(this.files)">

<!-- ===== MODAL TEMPLATES ===== -->

<!-- Generic Modal -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h3 id="modalTitle"></h3>
            <button class="btn btn-ghost btn-icon" onclick="CV.closeModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-footer" id="modalFooter"></div>
    </div>
</div>

<!-- Editor Modal -->
<div class="modal-overlay" id="editorOverlay">
    <div class="modal modal-full" style="display:flex;flex-direction:column">
        <div class="modal-header">
            <h3 id="editorTitle">Editor</h3>
            <div style="display:flex;gap:8px;align-items:center">
                <span id="editorStatus" style="font-size:12px;color:var(--text-muted)"></span>
                <button class="btn btn-primary btn-sm" id="editorSaveBtn" onclick="CV.editorSave()"><i class="bi bi-check-lg"></i> Salvar</button>
                <button class="btn btn-secondary btn-sm" onclick="CV.editorSaveAs()"><i class="bi bi-save"></i> Salvar como</button>
                <button class="btn btn-ghost btn-icon" onclick="CV.closeEditor()"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="modal-body" style="flex:1;display:flex;flex-direction:column;overflow:hidden;padding-bottom:0">
            <div class="editor-container">
                <div id="code-editor"></div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal-overlay" id="previewOverlay">
    <div class="modal modal-full" style="display:flex;flex-direction:column">
        <div class="modal-header">
            <h3 id="previewTitle">Visualizar</h3>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn btn-secondary btn-sm" id="previewEditBtn" onclick="CV.editFromPreview()" style="display:none">
                    <i class="bi bi-pencil"></i> Editar
                </button>
                <button class="btn btn-secondary btn-sm" onclick="CV.downloadPreviewFile()"><i class="bi bi-download"></i> Baixar</button>
                <button class="btn btn-ghost btn-icon" onclick="CV.closePreview()"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="modal-body" style="flex:1;overflow:hidden;padding-bottom:0">
            <div class="preview-container" id="previewContainer"></div>
        </div>
    </div>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Monaco Editor CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.min.js"></script>

<!-- Document Editor Module -->
<script src="public/js/doc-editor.js"></script>

<!-- ===== DOCUMENT EDITOR OVERLAY ===== -->
<div class="modal-overlay" id="docEditorOverlay" style="z-index:600">
    <div class="modal modal-full" style="display:flex;flex-direction:column;max-width:99vw;width:99vw;height:97vh">
        <div class="modal-header" style="padding:12px 20px;border-bottom:1px solid var(--border);flex-shrink:0">
            <div style="display:flex;align-items:center;gap:12px;min-width:0;flex:1">
                <div id="docEditorIcon" style="font-size:20px"></div>
                <div style="min-width:0">
                    <h3 id="docEditorTitle" style="font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></h3>
                    <span id="docEditorStatus" style="font-size:11px;color:var(--text-muted)"></span>
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
                <button class="btn btn-ghost btn-sm" id="docEditorFullscreenBtn" onclick="DocEditor.toggleFullscreen()" title="Tela cheia">
                    <i class="bi bi-arrows-fullscreen"></i>
                </button>
                <button class="btn btn-primary btn-sm" id="docEditorSaveBtn" onclick="DocEditor.save()">
                    <i class="bi bi-check-lg"></i> Salvar
                </button>
                <button class="btn btn-secondary btn-sm" id="docEditorSaveAsBtn" onclick="DocEditor.saveAs()">
                    <i class="bi bi-save"></i> Salvar como
                </button>
                <button class="btn btn-secondary btn-sm" id="docEditorPdfBtn" onclick="DocEditor.exportPdf()" title="Exportar PDF / Imprimir">
                    <i class="bi bi-file-pdf"></i>
                </button>
                <button class="btn btn-secondary btn-sm" onclick="CV.downloadFile(DocEditor._state.fileId)" title="Baixar">
                    <i class="bi bi-download"></i>
                </button>
                <button class="btn btn-ghost btn-icon" onclick="DocEditor.close()" title="Fechar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <div id="docEditorBody" style="flex:1;display:flex;flex-direction:column;overflow:hidden"></div>
    </div>
</div>

<script>
// ============================================================
// TCloud - Frontend Application
// ============================================================

const CV = {
    // Estado da aplicação
    state: {
        currentFolder: null,
        currentView: '<?= e($user['view_mode'] ?? 'list') ?>',
        currentNav: 'files',
        selectedItems: [],
        sort: 'name',
        order: 'asc',
        theme: '<?= e($user['theme'] ?? 'dark') ?>',
        csrfToken: '<?= $csrfToken ?>',
        user: <?= json_encode([
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role_slug' => $user['role_slug']
        ]) ?>,
        permissions: <?= json_encode($permissions) ?>,
        monacoEditor: null,
        editorFileId: null,
        previewFileId: null,
        searchTimeout: null,
        items: { folders: [], files: [] },
        _pollHash: '',
        _pollTimer: null,
        _locks: [],
        _myId: 0
    },

    // ==================== INICIALIZAÇÃO ====================
    init() {
        this.initDragDrop();
        this.initContextMenu();
        this.initKeyboard();
        this.initLassoSelect();
        this.loadFolder(null);
        // Definir view mode
        if (this.state.currentView === 'grid') {
            document.getElementById('viewList').classList.remove('active');
            document.getElementById('viewGrid').classList.add('active');
        }
        // Tema
        this.updateThemeIcon();
        // Start real-time polling
        this._startPolling();
    },

    // ==================== REAL-TIME POLLING ====================
    _startPolling() {
        if (this.state._pollTimer) clearInterval(this.state._pollTimer);
        this.state._pollTimer = setInterval(() => this._poll(), 5000);
    },

    async _poll() {
        try {
            const d = await this.api('poll_updates', { folder_id: this.state.currentFolder || '' });
            if (!d.success) return;
            this.state._myId = d.my_id;
            this.state._locks = d.locks || [];
            // Update lock indicators
            this._updateLockIcons();
            // If hash changed, refresh file list
            if (this.state._pollHash && this.state._pollHash !== d.hash) {
                this.refresh();
            }
            this.state._pollHash = d.hash;
        } catch(e) {}
    },

    _updateLockIcons() {
        document.querySelectorAll('.file-item, .file-grid-item').forEach(el => {
            const id = parseInt(el.dataset.id);
            const type = el.dataset.type;
            if (type !== 'file') return;
            const lock = this.state._locks.find(l => l.file_id == id && l.user_id != this.state._myId);
            let badge = el.querySelector('.lock-badge');
            if (lock) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'lock-badge';
                    badge.style.cssText = 'display:inline-flex;align-items:center;gap:3px;font-size:10px;color:var(--warning);margin-left:6px;';
                    badge.innerHTML = '<i class="bi bi-lock-fill"></i>';
                    const nameEl = el.querySelector('.file-name, .grid-name');
                    if (nameEl) nameEl.appendChild(badge);
                }
                badge.title = 'Editando: ' + lock.user_name;
            } else if (badge) {
                badge.remove();
            }
        });
    },

    _isFileLocked(fileId) {
        return this.state._locks.find(l => l.file_id == fileId && l.user_id != this.state._myId);
    },

    // ==================== API ====================
    async api(action, data = {}, method = 'POST') {
        const fd = new FormData();
        fd.append('action', action);
        for (const [k, v] of Object.entries(data)) {
            fd.append(k, v);
        }
        const opts = { method, headers: { 'X-CSRF-TOKEN': this.state.csrfToken } };
        if (method === 'POST') opts.body = fd;
        
        try {
            const res = await fetch('api/index.php', opts);
            const json = await res.json();
            if (json.redirect) {
                window.location.href = json.redirect;
                return json;
            }
            if (json.csrf_token) this.state.csrfToken = json.csrf_token;
            return json;
        } catch (e) {
            this.toast('Erro de conexão com o servidor.', 'error');
            throw e;
        }
    },

    // ==================== NAVEGAÇÃO ====================
    navigate(section) {
        document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
        const nav = document.querySelector(`[data-nav="${section}"]`);
        if (nav) nav.classList.add('active');
        this.state.currentNav = section;
        this.clearSelection();
        this.closeSidebar();

        switch(section) {
            case 'files': this.loadFolder(null); break;
            case 'recent': this.loadRecent(); break;
            case 'favorites': this.loadFavorites(); break;
            case 'shared': this.loadShared(); break;
            case 'trash': this.loadTrash(); break;
            case 'admin-dashboard': this.loadAdminDashboard(); break;
            case 'admin-users': this.loadAdminUsers(); break;
            case 'admin-logs': this.loadAdminLogs(); break;
            case 'admin-settings': this.loadAdminSettings(); break;
        }
    },

    async loadFolder(folderId) {
        this.state.currentFolder = folderId;
        this.state.currentNav = 'files';
        this.showLoading();

        const data = await this.api('list', {
            folder_id: folderId || '',
            sort: this.state.sort,
            order: this.state.order
        });

        if (data.success) {
            this.state.items = { folders: data.folders, files: data.files };
            this.renderBreadcrumbs(data.breadcrumbs);
            this.renderFiles(data.folders, data.files);
            this.updateStats(data.stats);
            document.getElementById('toolbar').style.display = '';
        }
    },

    async loadRecent() {
        this.showLoading();
        const data = await this.api('list_recent');
        if (data.success) {
            this.state.items = { folders: [], files: data.files };
            this.setBreadcrumbText('Recentes');
            this.renderFiles([], data.files);
        }
    },

    async loadFavorites() {
        this.showLoading();
        const data = await this.api('list_favorites');
        if (data.success) {
            this.state.items = data;
            this.setBreadcrumbText('Favoritos');
            this.renderFiles(data.folders, data.files);
        }
    },

    async loadShared() {
        this.showLoading();
        const data = await this.api('list_shared');
        if (data.success) {
            this.setBreadcrumbText('Compartilhados');
            let html = '<div class="tabs"><div class="tab active" onclick="CV.showSharedTab(\'byMe\',this)">Compartilhados por mim</div><div class="tab" onclick="CV.showSharedTab(\'withMe\',this)">Compartilhados comigo</div></div>';
            html += '<div id="sharedByMe">' + this.renderShareList(data.shared_by_me) + '</div>';
            html += '<div id="sharedWithMe" style="display:none">' + this.renderShareList(data.shared_with_me) + '</div>';
            document.getElementById('fileContent').innerHTML = html;
        }
    },

    showSharedTab(tab, el) {
        document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('sharedByMe').style.display = tab === 'byMe' ? '' : 'none';
        document.getElementById('sharedWithMe').style.display = tab === 'withMe' ? '' : 'none';
    },

    renderShareList(shares) {
        if (!shares.length) return '<div class="empty-state"><i class="bi bi-share"></i><h3>Nenhum compartilhamento</h3></div>';
        let html = '<table class="data-table"><thead><tr><th>Arquivo/Pasta</th><th>Permissão</th><th>Expira</th><th>Link</th></tr></thead><tbody>';
        shares.forEach(s => {
            const name = s.file_name || s.folder_name || '—';
            const link = s.token ? `${window.location.origin}/tcloud/share.php?token=${s.token}` : '';
            html += `<tr>
                <td>${this.esc(name)}</td>
                <td><span class="status-badge active">${s.permission}</span></td>
                <td>${s.expires_at ? this.esc(s.expires_at) : 'Nunca'}</td>
                <td>${link ? `<button class="btn btn-sm btn-secondary" onclick="navigator.clipboard.writeText('${link}');CV.toast('Link copiado!','success')"><i class="bi bi-clipboard"></i></button>` : '—'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        return html;
    },

    async loadTrash() {
        this.showLoading();
        const data = await this.api('list_trash');
        if (data.success) {
            this.setBreadcrumbText('Lixeira');
            this.state.items = data;
            let html = '';
            if (data.folders.length || data.files.length) {
                html += '<div style="margin-bottom:12px;display:flex;gap:8px">';
                html += '<button class="btn btn-danger btn-sm" onclick="CV.emptyTrash()"><i class="bi bi-trash3"></i> Esvaziar Lixeira</button>';
                html += '</div>';
            }
            const allItems = [...data.folders.map(f => ({...f, item_type:'folder'})), ...data.files.map(f => ({...f, item_type:'file'}))];
            if (!allItems.length) {
                html += '<div class="empty-state"><i class="bi bi-trash3"></i><h3>Lixeira vazia</h3><p>Itens excluídos aparecerão aqui</p></div>';
            } else {
                html += this.renderItemList(allItems, true);
            }
            document.getElementById('fileContent').innerHTML = html;
        }
    },

    // ==================== RENDERIZAÇÃO ====================
    renderBreadcrumbs(crumbs) {
        let html = `<div class="crumb" onclick="CV.loadFolder(null)"><i class="bi bi-house-door"></i></div>`;
        if (crumbs && crumbs.length) {
            crumbs.forEach((c, i) => {
                html += `<span class="separator"><i class="bi bi-chevron-right"></i></span>`;
                const isLast = i === crumbs.length - 1;
                html += `<div class="crumb ${isLast ? 'active' : ''}" onclick="CV.loadFolder(${c.id})">${this.esc(c.name)}</div>`;
            });
        }
        document.getElementById('breadcrumbs').innerHTML = html;
    },

    setBreadcrumbText(text) {
        document.getElementById('breadcrumbs').innerHTML = `<div class="crumb active"><i class="bi bi-${
            text === 'Recentes' ? 'clock-history' : text === 'Favoritos' ? 'star' : text === 'Compartilhados' ? 'share' : text === 'Lixeira' ? 'trash3' : 'speedometer2'
        }"></i> ${text}</div>`;
    },

    renderFiles(folders, files) {
        const all = [...folders.map(f => ({...f, item_type:'folder'})), ...files.map(f => ({...f, item_type:'file'}))];
        if (!all.length) {
            document.getElementById('fileContent').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    <h3>Pasta vazia</h3>
                    <p>Arraste arquivos ou pastas aqui ou clique em "Enviar"</p>
                </div>`;
            return;
        }
        if (this.state.currentView === 'grid') {
            this.renderGrid(all);
        } else {
            document.getElementById('fileContent').innerHTML = this.renderItemList(all, false);
        }
    },

    renderItemList(items, isTrash) {
        let html = `<div class="file-list-header">
            <div></div><div class="sorted" data-sort="name">Nome</div>
            <div data-sort="size">Tamanho</div><div data-sort="type">Tipo</div>
            <div data-sort="date">Modificado</div><div></div>
        </div>`;
        items.forEach(item => {
            const isFolder = item.item_type === 'folder';
            const icon = isFolder ? 'bi-folder-fill' : this.getFileIcon(item.extension || '');
            const iconColor = isFolder ? 'var(--warning)' : this.getFileColor(item.extension || '');
            const size = isFolder ? `${(item.file_count||0)} itens` : this.formatSize(item.size || 0);
            const type = isFolder ? 'Pasta' : (item.extension || '').toUpperCase();
            const date = item.updated_at ? this.timeAgo(item.updated_at) : '';
            const isFav = item.is_favorited == 1;
            const sel = this.state.selectedItems.find(s => s.id == item.id && s.type == item.item_type);

            html += `<div class="file-item ${sel ? 'selected' : ''} fade-in" 
                data-id="${item.id}" data-type="${item.item_type}" data-name="${this.esc(item.original_name || item.name)}"
                ondblclick="CV.itemDblClick('${item.item_type}', ${item.id})"
                onclick="CV.itemClick(event, '${item.item_type}', ${item.id})"
                oncontextmenu="CV.showContextMenu(event, '${item.item_type}', ${item.id}, ${isTrash})"
                draggable="true" ondragstart="CV.onDragStart(event, '${item.item_type}', ${item.id})"
                ${isFolder ? `ondragover="CV.onDragOver(event)" ondrop="CV.onDrop(event, ${item.id})"` : ''}>
                <div class="checkbox ${sel ? 'checked' : ''}" onclick="event.stopPropagation();CV.toggleSelect('${item.item_type}',${item.id})">
                    ${sel ? '<i class="bi bi-check" style="font-size:12px"></i>' : ''}
                </div>
                <div class="file-name-cell">
                    <div class="file-icon ${isFolder ? 'folder-icon' : ''}" style="${!isFolder ? 'background:' + iconColor + '18;color:' + iconColor : ''}">
                        <i class="bi ${icon}"></i>
                    </div>
                    <span class="file-name">${this.esc(item.original_name || item.name)}</span>
                </div>
                <div class="file-size">${size}</div>
                <div class="file-type">${type}</div>
                <div class="file-date">${date}</div>
                <div class="file-actions">
                    ${!isTrash ? `<i class="fav-star ${isFav ? 'active' : ''} bi ${isFav ? 'bi-star-fill' : 'bi-star'}" 
                        onclick="event.stopPropagation();CV.toggleFavorite('${item.item_type}',${item.id},this)" title="Favorito"></i>` : ''}
                    <button class="btn btn-ghost btn-icon" style="width:28px;height:28px" 
                        onclick="event.stopPropagation();CV.showContextMenu(event,'${item.item_type}',${item.id},${isTrash})">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                </div>
            </div>`;
        });
        return html;
    },

    renderGrid(items) {
        let html = '<div class="file-grid">';
        items.forEach(item => {
            const isFolder = item.item_type === 'folder';
            const icon = isFolder ? 'bi-folder-fill' : this.getFileIcon(item.extension || '');
            const iconColor = isFolder ? 'var(--warning)' : this.getFileColor(item.extension || '');
            const sel = this.state.selectedItems.find(s => s.id == item.id && s.type == item.item_type);
            const hasThumb = !isFolder && item.thumbnail_path;
            const meta = isFolder ? `${item.file_count||0} itens` : this.formatSize(item.size||0);

            html += `<div class="file-grid-item ${sel ? 'selected' : ''} fade-in"
                data-id="${item.id}" data-type="${item.item_type}"
                ondblclick="CV.itemDblClick('${item.item_type}', ${item.id})"
                onclick="CV.itemClick(event, '${item.item_type}', ${item.id})"
                oncontextmenu="CV.showContextMenu(event, '${item.item_type}', ${item.id}, false)">
                <div class="grid-checkbox ${sel ? 'checked' : ''}" onclick="event.stopPropagation();CV.toggleSelect('${item.item_type}',${item.id})">
                    ${sel ? '<i class="bi bi-check" style="font-size:12px;color:#fff"></i>' : ''}
                </div>
                ${hasThumb 
                    ? `<img class="grid-thumb" src="api/download.php?type=thumb&id=${item.id}" alt="">`
                    : `<span class="grid-icon" style="color:${iconColor}"><i class="bi ${icon}"></i></span>`
                }
                <div class="grid-name">${this.esc(item.original_name || item.name)}</div>
                <div class="grid-meta">${meta}</div>
            </div>`;
        });
        html += '</div>';
        document.getElementById('fileContent').innerHTML = html;
    },

    // ==================== SELEÇÃO ====================
    itemClick(event, type, id) {
        if (event.ctrlKey || event.metaKey) {
            this.toggleSelect(type, id);
        } else {
            this.clearSelection();
            this.toggleSelect(type, id);
        }
    },

    async itemDblClick(type, id) {
        if (type === 'folder') {
            this.loadFolder(id);
        } else {
            // Check if file is locked by another user
            const lock = this._isFileLocked(id);
            if (lock) {
                const r = await Swal.fire({
                    title: 'Arquivo em uso',
                    html: '<b>' + this.esc(lock.user_name) + '</b> está editando este arquivo.<br>Deseja abrir mesmo assim em modo leitura?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Abrir (leitura)',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: 'var(--accent)',
                    background: 'var(--bg-modal)', color: 'var(--text-primary)',
                    customClass: {popup:'swal-custom-popup'}
                });
                if (!r.isConfirmed) return;
            }
            const ext = this.getItemExt(id).toLowerCase();
            const docEditorTypes = ['doc','docx','xls','xlsx','pdf','txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml','svg'];
            if (docEditorTypes.includes(ext)) {
                DocEditor.open(id);
            } else {
                this.previewFile(id);
            }
        }
    },

    toggleSelect(type, id) {
        const idx = this.state.selectedItems.findIndex(s => s.id === id && s.type === type);
        if (idx >= 0) {
            this.state.selectedItems.splice(idx, 1);
        } else {
            this.state.selectedItems.push({ type, id });
        }
        this.updateSelectionUI();
    },

    clearSelection() {
        this.state.selectedItems = [];
        this.updateSelectionUI();
    },

    updateSelectionUI() {
        document.querySelectorAll('.file-item, .file-grid-item').forEach(el => {
            const id = parseInt(el.dataset.id);
            const type = el.dataset.type;
            const sel = this.state.selectedItems.find(s => s.id === id && s.type === type);
            el.classList.toggle('selected', !!sel);
            const cb = el.querySelector('.checkbox, .grid-checkbox');
            if (cb) {
                cb.classList.toggle('checked', !!sel);
                cb.innerHTML = sel ? '<i class="bi bi-check" style="font-size:12px;color:#fff"></i>' : '';
            }
        });
        const count = this.state.selectedItems.length;
        const batch = document.getElementById('batchActions');
        const info = document.getElementById('selectionInfo');
        if (count > 0) {
            batch.style.display = 'flex';
            info.textContent = `${count} selecionado(s)`;
        } else {
            batch.style.display = 'none';
        }
    },

    // ==================== CONTEXT MENU ====================
    showContextMenu(event, type, id, isTrash = false) {
        event.preventDefault();
        event.stopPropagation();
        const menu = document.getElementById('contextMenu');
        let html = '';

        if (isTrash) {
            html += this.ctxItem('bi-arrow-counterclockwise', 'Restaurar', `CV.restoreItems([{type:'${type}',id:${id}}])`);
            html += '<div class="context-menu-divider"></div>';
            html += this.ctxItem('bi-x-circle', 'Excluir permanentemente', `CV.deletePermanent([{type:'${type}',id:${id}}])`, true);
        } else {
            if (type === 'file') {
                html += this.ctxItem('bi-eye', 'Visualizar', `CV.previewFile(${id})`);
                html += this.ctxItem('bi-download', 'Baixar', `CV.downloadFile(${id})`);
                html += '<div class="context-menu-divider"></div>';
            }
            if (type === 'folder') {
                html += this.ctxItem('bi-folder2-open', 'Abrir', `CV.loadFolder(${id})`);
                html += '<div class="context-menu-divider"></div>';
            }
            html += this.ctxItem('bi-pencil', 'Renomear', `CV.showRenameModal('${type}',${id})`);
            html += this.ctxItem('bi-arrows-move', 'Mover para...', `CV.showMoveModal([{type:'${type}',id:${id}}])`);
            if (type === 'file') {
                html += this.ctxItem('bi-copy', 'Duplicar', `CV.duplicateFile(${id})`);
            }
            html += this.ctxItem('bi-share', 'Compartilhar', `CV.showShareModal('${type}',${id})`);
            html += this.ctxItem('bi-star', 'Favoritar', `CV.toggleFavoriteCtx('${type}',${id})`);
            if (type === 'file') {
                const ext = this.getItemExt(id).toLowerCase();
                const editorTypes = ['doc','docx','xls','xlsx','pdf','txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml','svg'];
                if (editorTypes.includes(ext)) {
                    html += '<div class="context-menu-divider"></div>';
                    const editLabel = ['doc','docx','xls','xlsx'].includes(ext) ? 'Abrir no editor' :
                                      ext === 'pdf' ? 'Visualizar PDF' : 'Editar no editor';
                    const editIcon = ['doc','docx'].includes(ext) ? 'bi-file-word' :
                                     ['xls','xlsx'].includes(ext) ? 'bi-file-excel' :
                                     ext === 'pdf' ? 'bi-file-pdf' : 'bi-code-square';
                    html += this.ctxItem(editIcon, editLabel, `DocEditor.open(${id})`);
                }
                if (ext === 'zip') {
                    html += this.ctxItem('bi-file-zip', 'Extrair aqui', `CV.extractZip(${id})`);
                }
            }
            html += '<div class="context-menu-divider"></div>';
            if (type === 'file') {
                html += this.ctxItem('bi-clock-history', 'Hist\u00f3rico de vers\u00f5es', `DocEditor.showVersions(${id})`);
            }
            html += this.ctxItem('bi-info-circle', 'Propriedades', `CV.showProperties('${type}',${id})`);
            html += '<div class="context-menu-divider"></div>';
            html += this.ctxItem('bi-trash3', 'Mover para lixeira', `CV.trashItems([{type:'${type}',id:${id}}])`, true);
        }

        menu.innerHTML = html;
        menu.classList.add('show');
        // Position
        const x = Math.min(event.clientX, window.innerWidth - 220);
        const y = Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 10);
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    },

    ctxItem(icon, label, action, danger = false) {
        return `<div class="context-menu-item ${danger ? 'danger' : ''}" onclick="${action};CV.hideContextMenu()">
            <i class="bi ${icon}"></i> ${label}
        </div>`;
    },

    hideContextMenu() {
        document.getElementById('contextMenu').classList.remove('show');
    },

    initContextMenu() {
        document.addEventListener('click', () => this.hideContextMenu());
        document.getElementById('fileArea').addEventListener('contextmenu', (e) => {
            if (e.target.closest('.file-item, .file-grid-item')) return;
            e.preventDefault();
            const menu = document.getElementById('contextMenu');
            menu.innerHTML = `
                ${this.ctxItem('bi-folder-plus', 'Nova pasta', 'CV.showNewFolderModal()')}
                ${this.ctxItem('bi-file-earmark-plus', 'Novo documento', 'CV.showNewFileModal()')}
                ${this.ctxItem('bi-cloud-arrow-up', 'Enviar arquivo', 'CV.triggerUpload()')}
                ${this.ctxItem('bi-folder-symlink', 'Enviar pasta', 'CV.triggerFolderUpload()')}
                <div class="context-menu-divider"></div>
                ${this.ctxItem('bi-arrow-clockwise', 'Atualizar', 'CV.refresh()')}
            `;
            menu.classList.add('show');
            menu.style.left = Math.min(e.clientX, window.innerWidth - 220) + 'px';
            menu.style.top = Math.min(e.clientY, window.innerHeight - 200) + 'px';
        });
    },

    // ==================== OPERAÇÕES ====================
    async showNewFolderModal() {
        this.openModal('Nova Pasta', `
            <div class="form-group">
                <label>Nome da pasta</label>
                <input type="text" class="form-control" id="newFolderName" placeholder="Nome da pasta" autofocus>
            </div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="CV.createFolder()">Criar</button>`);
        setTimeout(() => document.getElementById('newFolderName')?.focus(), 100);
    },

    async createFolder() {
        const name = document.getElementById('newFolderName').value.trim();
        if (!name) return this.toast('Digite um nome.', 'warning');
        const result = await this.api('create_folder', { name, parent_id: this.state.currentFolder || '' });
        if (result.success) {
            this.toast(result.message, 'success');
            this.closeModal();
            this.refresh();
        } else {
            this.toast(result.message, 'error');
        }
    },

    showNewFileModal() {
        this.openModal('Novo Documento', `
            <div class="form-group">
                <label>Tipo de documento</label>
                <select class="form-control" id="newFileType" onchange="CV.updateNewFileName()">
                    <option value="docx" data-icon="bi-file-word">Documento Word (.docx)</option>
                    <option value="xlsx" data-icon="bi-file-excel">Planilha Excel (.xlsx)</option>
                    <option value="pptx" data-icon="bi-file-ppt">Apresentação PowerPoint (.pptx)</option>
                    <option value="txt" data-icon="bi-file-text">Texto Simples (.txt)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Nome do documento</label>
                <input type="text" class="form-control" id="newFileName" value="Novo Documento.docx">
            </div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="CV.createNewFile()"><i class="bi bi-file-earmark-plus"></i> Criar</button>`);
    },

    updateNewFileName() {
        const ext = document.getElementById('newFileType').value;
        const input = document.getElementById('newFileName');
        const name = input.value.replace(/\.[^.]+$/, '');
        input.value = name + '.' + ext;
    },

    async createNewFile() {
        const name = document.getElementById('newFileName').value.trim();
        if (!name) return this.toast('Digite um nome.', 'warning');
        
        const ext = name.split('.').pop().toLowerCase();
        const officeTypes = ['docx', 'xlsx', 'pptx'];

        if (officeTypes.includes(ext)) {
            // Criar documento Office via API dedicada
            const result = await this.api('create_office', {
                name,
                content: '',
                folder_id: this.state.currentFolder || ''
            });
            if (result.success) {
                this.toast('Documento criado com sucesso!', 'success');
                this.closeModal();
                this.refresh();
                // Abrir no editor automaticamente
                const fileId = result.id || result.results?.[0]?.id;
                if (fileId) {
                    setTimeout(() => DocEditor.open(fileId), 300);
                }
            } else {
                this.toast(result.message, 'error');
            }
        } else {
            // Arquivo de texto simples
            const result = await this.api('create_file', {
                name,
                content: '',
                folder_id: this.state.currentFolder || ''
            });
            if (result.success) {
                this.toast(result.message, 'success');
                this.closeModal();
                this.refresh();
                // Abrir no editor automaticamente
                if (result.id) {
                    setTimeout(() => DocEditor.open(result.id), 300);
                }
            } else {
                this.toast(result.message, 'error');
            }
        }
    },

    async showRenameModal(type, id) {
        const item = this.findItem(type, id);
        const currentName = item ? (item.original_name || item.name) : '';
        this.openModal('Renomear', `
            <div class="form-group">
                <label>Novo nome</label>
                <input type="text" class="form-control" id="renameInput" value="${this.esc(currentName)}">
            </div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="CV.doRename('${type}',${id})">Renomear</button>`);
        setTimeout(() => {
            const inp = document.getElementById('renameInput');
            inp?.focus();
            // Selecionar apenas o nome, sem extensão
            const dot = currentName.lastIndexOf('.');
            inp?.setSelectionRange(0, dot > 0 ? dot : currentName.length);
        }, 100);
    },

    async doRename(type, id) {
        const name = document.getElementById('renameInput').value.trim();
        if (!name) return;
        const result = await this.api('rename', { type, id, name });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) { this.closeModal(); this.refresh(); }
    },

    async trashItems(items) {
        const ok = await this.confirm('Mover para lixeira', 'Os itens selecionados serão movidos para a lixeira.', 'Mover');
        if (!ok) return;
        const result = await this.api('trash', { items: JSON.stringify(items) });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) { this.clearSelection(); this.refresh(); }
    },

    async restoreItems(items) {
        const result = await this.api('restore', { items: JSON.stringify(items) });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) this.loadTrash();
    },

    async deletePermanent(items) {
        const ok = await this.confirm('Excluir permanentemente', 'Esta ação não pode ser desfeita! Os arquivos serão removidos para sempre.', 'Excluir', true);
        if (!ok) return;
        const result = await this.api('delete_permanent', { items: JSON.stringify(items) });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) this.loadTrash();
    },

    async emptyTrash() {
        const ok = await this.confirm('Esvaziar lixeira', 'Todos os itens da lixeira serão excluídos permanentemente. Esta ação não pode ser desfeita!', 'Esvaziar', true);
        if (!ok) return;
        const result = await this.api('empty_trash');
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) this.loadTrash();
    },

    async duplicateFile(id) {
        const result = await this.api('copy', { items: JSON.stringify([{type:'file',id}]), target_folder_id: this.state.currentFolder || '' });
        this.toast(result.message || 'Duplicado!', result.success ? 'success' : 'error');
        if (result.success) this.refresh();
    },

    async toggleFavorite(type, id, el) {
        const result = await this.api('toggle_favorite', { type, id });
        if (result.success && el) {
            el.classList.toggle('active', result.favorited);
            el.className = `fav-star ${result.favorited ? 'active bi bi-star-fill' : 'bi bi-star'}`;
        }
    },

    toggleFavoriteCtx(type, id) {
        this.api('toggle_favorite', { type, id }).then(r => {
            if (r.success) this.toast(r.favorited ? 'Adicionado aos favoritos' : 'Removido dos favoritos', 'success');
        });
    },

    async extractZip(id) {
        this.toast('Extraindo...', 'info');
        const result = await this.api('extract_zip', { id, folder_id: this.state.currentFolder || '' });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) this.refresh();
    },

    // ==================== MOVE/COPY MODAL ====================
    async showMoveModal(items, mode = 'move') {
        const data = await this.api('list', { folder_id: '', sort: 'name', order: 'asc' });
        let html = `<p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Selecione a pasta de destino:</p>`;
        html += '<div class="folder-tree">';
        html += `<div class="folder-tree-item selected" data-target="" onclick="CV.selectMoveTarget(this,'')">
            <i class="bi bi-house-door"></i> Raiz (Meus Arquivos)
        </div>`;
        if (data.success && data.folders) {
            data.folders.forEach(f => {
                html += `<div class="folder-tree-item" data-target="${f.id}" onclick="CV.selectMoveTarget(this,${f.id})">
                    <i class="bi bi-folder-fill"></i> ${this.esc(f.name)}
                </div>`;
            });
        }
        html += '</div>';
        
        this._moveItems = items;
        this._moveMode = mode;
        this._moveTarget = null;

        this.openModal(mode === 'move' ? 'Mover para...' : 'Copiar para...', html,
            `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
             <button class="btn btn-primary" onclick="CV.doMoveOrCopy()">${mode === 'move' ? 'Mover' : 'Copiar'}</button>`);
    },

    selectMoveTarget(el, id) {
        document.querySelectorAll('.folder-tree-item').forEach(e => e.classList.remove('selected'));
        el.classList.add('selected');
        this._moveTarget = id;
    },

    async doMoveOrCopy() {
        const action = this._moveMode === 'move' ? 'move' : 'copy';
        const result = await this.api(action, {
            items: JSON.stringify(this._moveItems),
            target_folder_id: this._moveTarget ?? ''
        });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) { this.closeModal(); this.clearSelection(); this.refresh(); }
    },

    // Batch operations
    batchMove() { this.showMoveModal(this.state.selectedItems, 'move'); },
    batchCopy() { this.showMoveModal(this.state.selectedItems, 'copy'); },
    batchTrash() { this.trashItems(this.state.selectedItems); },
    async batchDownload() {
        const fileIds = this.state.selectedItems.filter(i => i.type === 'file').map(i => i.id);
        const folderIds = this.state.selectedItems.filter(i => i.type === 'folder').map(i => i.id);
        if (!fileIds.length && !folderIds.length) return;
        this.toast('Criando ZIP...', 'info');
        const result = await this.api('download_zip', {
            file_ids: JSON.stringify(fileIds),
            folder_ids: JSON.stringify(folderIds)
        });
        if (result.success) {
            window.open(`api/download.php?zip_token=${result.zip_token}`, '_blank');
        }
    },

    // ==================== SHARE MODAL ====================
    showShareModal(type, id) {
        this.openModal('Compartilhar', `
            <div class="form-group">
                <label>Permissão</label>
                <select class="form-control" id="sharePermission">
                    <option value="view">Apenas visualizar</option>
                    <option value="download">Visualizar e baixar</option>
                    <option value="edit">Editar</option>
                </select>
            </div>
            <div class="form-group">
                <label>Expiração (horas, deixe vazio para nunca)</label>
                <input type="number" class="form-control" id="shareExpires" placeholder="Ex: 24">
            </div>
            <div class="form-group">
                <label>Senha de proteção (opcional)</label>
                <input type="text" class="form-control" id="sharePassword" placeholder="Senha">
            </div>
            <div id="shareResult" style="display:none;margin-top:12px;padding:12px;background:var(--bg-tertiary);border-radius:var(--radius);word-break:break-all;font-size:13px"></div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Fechar</button>
            <button class="btn btn-primary" id="shareBtn" onclick="CV.doShare('${type}',${id})"><i class="bi bi-link-45deg"></i> Gerar Link</button>`);
    },

    async doShare(type, id) {
        const result = await this.api('create_share', {
            type, id,
            permission: document.getElementById('sharePermission').value,
            expires_hours: document.getElementById('shareExpires').value || '',
            password: document.getElementById('sharePassword').value
        });
        if (result.success) {
            const div = document.getElementById('shareResult');
            div.style.display = 'block';
            div.innerHTML = `<strong>Link:</strong> <a href="${result.link}" target="_blank">${result.link}</a>
                <button class="btn btn-sm btn-secondary" style="margin-top:8px" onclick="navigator.clipboard.writeText('${result.link}');CV.toast('Link copiado!','success')">
                    <i class="bi bi-clipboard"></i> Copiar
                </button>`;
            document.getElementById('shareBtn').style.display = 'none';
        } else {
            this.toast(result.message, 'error');
        }
    },

    // ==================== UPLOAD ====================
    triggerUpload() {
        document.getElementById('fileInput').click();
    },

    triggerFolderUpload() {
        document.getElementById('folderInput').click();
    },

    handleFileSelect(files) {
        if (!files.length) return;
        // Collect unique folder paths from webkitRelativePath (for folder input)
        const emptyDirCheck = new Set();
        const fileList = [];
        for (let i = 0; i < files.length; i++) {
            fileList.push(files[i]);
            const rp = files[i].webkitRelativePath || '';
            if (rp) {
                // Add all parent folder paths
                const parts = rp.split('/');
                parts.pop(); // remove filename
                let path = '';
                for (const p of parts) {
                    path += (path ? '/' : '') + p;
                    emptyDirCheck.add(path);
                }
            }
        }
        this._startUploadQueue(fileList, Array.from(emptyDirCheck));
        document.getElementById('fileInput').value = '';
        document.getElementById('folderInput').value = '';
    },

    // ==================== UPLOAD MANAGER ====================
    _uq: { paused: false, cancelled: false, running: false, xhr: null, expanded: true,
            completed: 0, errors: 0, total: 0, startTime: 0, bytesTotal: 0, bytesDone: 0 },

    toggleUploadExpand() {
        this._uq.expanded = !this._uq.expanded;
        document.getElementById('uploadPanelBody').style.display = this._uq.expanded ? '' : 'none';
        const icon = document.querySelector('#uqExpandBtn i');
        if (icon) icon.className = 'bi bi-chevron-' + (this._uq.expanded ? 'down' : 'up');
    },

    closeUploadPanel() {
        if (this._uq.running) {
            Swal.fire({ title:'Upload em andamento', text:'Cancele o upload antes de fechar.', icon:'warning',
                confirmButtonText:'OK', background:'var(--bg-modal)', color:'var(--text-primary)',
                customClass:{popup:'swal-custom-popup'} });
            return;
        }
        document.getElementById('uploadPanel').classList.remove('show');
    },

    toggleUploadPause() {
        this._uq.paused = !this._uq.paused;
        const btn = document.getElementById('uqPauseBtn');
        const panel = document.getElementById('uploadPanel');
        if (this._uq.paused) {
            if (btn) btn.innerHTML = '<i class="bi bi-play-fill" style="color:var(--success)"></i>';
            if (btn) btn.title = 'Retomar';
            panel.classList.add('uq-paused');
            document.getElementById('uqHeaderTitle').textContent = 'Pausado';
        } else {
            if (btn) btn.innerHTML = '<i class="bi bi-pause-fill"></i>';
            if (btn) btn.title = 'Pausar';
            panel.classList.remove('uq-paused');
        }
    },

    async cancelUpload() {
        const ok = await CV.confirm('Cancelar upload', 'Deseja cancelar todos os uploads pendentes?', 'Cancelar', true);
        if (!ok) return;
        this._uq.cancelled = true;
        if (this._uq.xhr) { try { this._uq.xhr.abort(); } catch(e){} }
    },

    _uqUpdateHeader() {
        const u = this._uq;
        const pct = u.total ? Math.round((u.completed / u.total) * 100) : 0;
        const bar = document.getElementById('uqHeaderBar');
        const pctEl = document.getElementById('uqHeaderPct');
        const title = document.getElementById('uqHeaderTitle');
        if (bar) bar.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        // Speed
        const elapsed = (Date.now() - u.startTime) / 1000;
        let speed = '';
        if (elapsed > 2 && u.bytesDone > 0) {
            const bps = u.bytesDone / elapsed;
            speed = ' \u2014 ' + this.formatSize(Math.round(bps)) + '/s';
        }
        if (title && !u.paused) title.textContent = u.completed.toLocaleString() + ' / ' + u.total.toLocaleString() + speed;
        // Stats
        const stats = document.getElementById('uqStatsText');
        if (stats) stats.textContent = u.completed + ' enviado(s), ' + u.errors + ' erro(s), ' + (u.total - u.completed - u.errors) + ' pendente(s)';
    },

    async _waitWhilePaused() {
        while (this._uq.paused && !this._uq.cancelled) {
            await new Promise(r => setTimeout(r, 500));
        }
    },

    async _startUploadQueue(files, folderPaths) {
        const panel = document.getElementById('uploadPanel');
        panel.classList.add('show');
        const u = this._uq;
        u.paused = false; u.cancelled = false; u.running = true;
        u.completed = 0; u.errors = 0; u.startTime = Date.now();
        u.bytesTotal = 0; u.bytesDone = 0;

        // Show controls
        document.getElementById('uqPauseBtn').style.display = '';
        document.getElementById('uqCancelBtn').style.display = '';
        document.getElementById('uqStats').style.display = 'flex';
        document.getElementById('uploadPanelBody').style.display = '';
        document.getElementById('uqIcon').className = 'bi bi-cloud-arrow-up';
        document.getElementById('uqIcon').style.color = 'var(--accent)';
        panel.classList.remove('uq-paused');
        const fileList = document.getElementById('uqFileList');
        fileList.innerHTML = '';

        const BATCH = 100;
        const CHUNK_SIZE = 50 * 1024 * 1024;

        // ── Step 1: Create folders ──
        if (folderPaths && folderPaths.length) {
            const paths = [...new Set(folderPaths)].sort();
            document.getElementById('uqHeaderTitle').textContent = 'Criando ' + paths.length + ' pasta(s)...';
            document.getElementById('uqHeaderPct').textContent = '';
            for (let i = 0; i < paths.length; i++) {
                if (u.cancelled) break;
                await this._waitWhilePaused();
                await this.api('create_folder_path', { path: paths[i], folder_id: this.state.currentFolder || '' });
                const bar = document.getElementById('uqHeaderBar');
                if (bar) bar.style.width = Math.round(((i+1)/paths.length)*100) + '%';
            }
            this.refresh();
            if (u.cancelled) { this._uqFinish('Cancelado'); return; }
        }

        // ── Step 2: Upload files ──
        if (!files.length) { this._uqFinish('Conclu\u00eddo'); return; }

        u.total = files.length;
        for (const f of files) u.bytesTotal += f.size || 0;
        this._uqUpdateHeader();

        for (let i = 0; i < files.length; i++) {
            if (u.cancelled) break;
            await this._waitWhilePaused();
            if (u.cancelled) break;

            const file = files[i];
            const rp = file.webkitRelativePath || '';
            const name = rp || file.name;
            const itemId = 'uqf_' + i;

            // Add to visible list (keep max 30 items)
            fileList.insertAdjacentHTML('afterbegin',
                '<div class="uq-file-item" id="' + itemId + '">' +
                '<i class="bi bi-file-earmark" style="font-size:16px;color:var(--text-muted)"></i>' +
                '<div class="uq-info"><div class="uq-name">' + this.esc(name) + '</div>' +
                '<div class="upload-progress"><div class="upload-progress-bar" id="' + itemId + '_bar" style="width:0%"></div></div>' +
                '<div class="uq-detail" id="' + itemId + '_detail">' + this.formatSize(file.size) + '</div></div>' +
                '<span class="uq-pct" id="' + itemId + '_pct">0%</span></div>');
            while (fileList.children.length > 30) fileList.removeChild(fileList.lastChild);

            try {
                if (file.size > CHUNK_SIZE) {
                    await this._uploadChunked(file, itemId, CHUNK_SIZE);
                } else {
                    await this._uploadNormal(file, itemId);
                }
                u.completed++;
                u.bytesDone += file.size || 0;
                const el = document.getElementById(itemId);
                if (el) { el.classList.add('complete'); }
                const pctEl = document.getElementById(itemId + '_pct');
                if (pctEl) pctEl.textContent = '\u2714';
            } catch (e) {
                u.errors++;
                const el = document.getElementById(itemId);
                if (el) el.classList.add('error');
                const pctEl = document.getElementById(itemId + '_pct');
                if (pctEl) pctEl.textContent = '\u2718';
            }

            this._uqUpdateHeader();
            if (i > 0 && i % BATCH === 0) this.refresh();
        }

        this.refresh();
        this._uqFinish(u.cancelled ? 'Cancelado' : 'Conclu\u00eddo');
    },

    _uqFinish(status) {
        const u = this._uq;
        u.running = false;
        document.getElementById('uqPauseBtn').style.display = 'none';
        document.getElementById('uqCancelBtn').style.display = 'none';
        const bar = document.getElementById('uqHeaderBar');
        const pctEl = document.getElementById('uqHeaderPct');
        const title = document.getElementById('uqHeaderTitle');
        const icon = document.getElementById('uqIcon');
        if (bar) bar.style.width = '100%';
        if (u.cancelled) {
            if (bar) bar.style.background = 'var(--warning)';
            if (icon) { icon.className = 'bi bi-exclamation-circle'; icon.style.color = 'var(--warning)'; }
            if (pctEl) pctEl.textContent = '';
            if (title) title.textContent = 'Upload cancelado';
        } else {
            if (bar) bar.style.background = 'var(--success)';
            if (icon) { icon.className = 'bi bi-check-circle-fill'; icon.style.color = 'var(--success)'; }
            if (pctEl) pctEl.textContent = '100%';
            if (title) title.textContent = u.completed.toLocaleString() + ' arquivo(s) enviado(s)' + (u.errors ? ' \u2014 ' + u.errors + ' erro(s)' : '');
        }
        const stats = document.getElementById('uqStatsText');
        const elapsed = Math.round((Date.now() - u.startTime) / 1000);
        const min = Math.floor(elapsed / 60), sec = elapsed % 60;
        if (stats) stats.textContent = 'Tempo: ' + (min ? min + 'min ' : '') + sec + 's \u2014 ' + this.formatSize(u.bytesDone) + ' transferido(s)';
    },

    // Normal upload (single request) with pause/cancel
    async _uploadNormal(file, itemId) {
        if (this._uq.cancelled) throw new Error('Cancelado');
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('file', file);
        fd.append('folder_id', this.state.currentFolder || '');
        fd.append('conflict', 'rename');
        if (file.webkitRelativePath) fd.append('relative_path', file.webkitRelativePath);

        const self = this;
        const xhr = new XMLHttpRequest();
        this._uq.xhr = xhr;

        await new Promise((resolve, reject) => {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round(e.loaded / e.total * 100);
                    const bar = document.getElementById(itemId + '_bar');
                    const pctEl = document.getElementById(itemId + '_pct');
                    const detail = document.getElementById(itemId + '_detail');
                    if (bar) bar.style.width = pct + '%';
                    if (pctEl) pctEl.textContent = pct + '%';
                    if (detail) detail.textContent = self.formatSize(e.loaded) + ' / ' + self.formatSize(e.total);
                }
            });
            xhr.addEventListener('load', () => {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (!res.success) {
                        const detail = document.getElementById(itemId + '_detail');
                        if (detail) detail.textContent = res.results?.[0]?.message || 'Erro';
                    }
                } catch(e) {}
                resolve();
            });
            xhr.addEventListener('error', () => reject(new Error('Rede')));
            xhr.addEventListener('abort', () => reject(new Error('Cancelado')));
            xhr.open('POST', 'api/index.php');
            xhr.setRequestHeader('X-CSRF-TOKEN', this.state.csrfToken);
            xhr.send(fd);
        });
        this._uq.xhr = null;
    },

    // Chunked upload with pause/cancel
    async _uploadChunked(file, itemId, chunkSize) {
        if (this._uq.cancelled) throw new Error('Cancelado');
        const totalChunks = Math.ceil(file.size / chunkSize);
        const uploadId = Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 8);

        for (let c = 0; c < totalChunks; c++) {
            if (this._uq.cancelled) throw new Error('Cancelado');
            await this._waitWhilePaused();
            if (this._uq.cancelled) throw new Error('Cancelado');

            const start = c * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);
            const fd = new FormData();
            fd.append('action', 'upload_chunk');
            fd.append('chunk', chunk, 'chunk');
            fd.append('upload_id', uploadId);
            fd.append('chunk_index', c);
            fd.append('total_chunks', totalChunks);

            const self = this;
            const xhr = new XMLHttpRequest();
            this._uq.xhr = xhr;

            await new Promise((resolve, reject) => {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const chunkPct = e.loaded / e.total;
                        const totalPct = Math.round(((c + chunkPct) / totalChunks) * 100);
                        const bar = document.getElementById(itemId + '_bar');
                        const pctEl = document.getElementById(itemId + '_pct');
                        const detail = document.getElementById(itemId + '_detail');
                        if (bar) bar.style.width = totalPct + '%';
                        if (pctEl) pctEl.textContent = totalPct + '%';
                        const done = start + e.loaded;
                        if (detail) detail.textContent = self.formatSize(done) + ' / ' + self.formatSize(file.size) + ' (chunk ' + (c+1) + '/' + totalChunks + ')';
                    }
                });
                xhr.addEventListener('load', () => {
                    try { const res = JSON.parse(xhr.responseText); if (!res.success) { reject(new Error(res.message)); return; } } catch(e){}
                    resolve();
                });
                xhr.addEventListener('error', () => reject(new Error('Rede')));
                xhr.addEventListener('abort', () => reject(new Error('Cancelado')));
                xhr.open('POST', 'api/index.php');
                xhr.setRequestHeader('X-CSRF-TOKEN', self.state.csrfToken);
                xhr.send(fd);
            });
            this._uq.xhr = null;
        }

        // Merge
        const detail = document.getElementById(itemId + '_detail');
        if (detail) detail.textContent = 'Finalizando...';
        const mergeResult = await this.api('upload_merge', {
            upload_id: uploadId, file_name: file.name, total_chunks: totalChunks,
            folder_id: this.state.currentFolder || '', conflict: 'rename',
            relative_path: file.webkitRelativePath || ''
        });
        if (!mergeResult.success) {
            if (detail) detail.textContent = mergeResult.message || 'Erro no merge';
            throw new Error(mergeResult.message);
        }
        if (detail) detail.textContent = this.formatSize(file.size) + ' \u2014 Conclu\u00eddo';
    },

    // Drag & Drop upload (files + folders)
    initDragDrop() {
        const area = document.getElementById('fileArea');
        let counter = 0;
        area.addEventListener('dragenter', (e) => { e.preventDefault(); counter++; area.classList.add('drag-over'); });
        area.addEventListener('dragleave', (e) => { counter--; if (counter <= 0) { area.classList.remove('drag-over'); counter = 0; } });
        area.addEventListener('dragover', (e) => e.preventDefault());
        area.addEventListener('drop', async (e) => {
            e.preventDefault();
            area.classList.remove('drag-over');
            counter = 0;
            const items = e.dataTransfer.items;
            if (items && items.length) {
                const entries = [];
                for (let i = 0; i < items.length; i++) {
                    const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                    if (entry) entries.push(entry);
                }
                if (entries.length && entries.some(en => en.isDirectory)) {
                    const result = await this._readEntries(entries, '');
                    this._startUploadQueue(result.files, result.dirs);
                    return;
                }
            }
            if (e.dataTransfer.files.length) {
                this._startUploadQueue(Array.from(e.dataTransfer.files), []);
            }
        });
    },

    // Recursively read all files AND empty folder paths from drag-and-drop
    async _readEntries(entries, basePath) {
        const files = [];
        const dirs = [];
        for (const entry of entries) {
            if (entry.isFile) {
                const file = await new Promise(r => entry.file(r));
                Object.defineProperty(file, 'webkitRelativePath', { value: basePath + file.name });
                files.push(file);
            } else if (entry.isDirectory) {
                const dirPath = basePath + entry.name;
                dirs.push(dirPath);
                const dirReader = entry.createReader();
                const children = await new Promise((resolve) => {
                    const all = [];
                    const readBatch = () => {
                        dirReader.readEntries((results) => {
                            if (results.length) { all.push(...results); readBatch(); }
                            else resolve(all);
                        });
                    };
                    readBatch();
                });
                if (children.length === 0) {
                    // Empty folder — just add path, no recursion needed
                    continue;
                }
                const sub = await this._readEntries(children, dirPath + '/');
                files.push(...sub.files);
                dirs.push(...sub.dirs);
            }
        }
        return { files, dirs };
    },

    // ==================== DRAG & DROP (mover) ====================
    onDragStart(e, type, id) {
        e.dataTransfer.setData('text/plain', JSON.stringify({ type, id }));
        e.dataTransfer.effectAllowed = 'move';
    },
    onDragOver(e) {
        e.preventDefault();
        e.currentTarget.style.background = 'var(--accent-bg)';
    },
    async onDrop(e, targetFolderId) {
        e.preventDefault();
        e.stopPropagation();
        e.currentTarget.style.background = '';
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            if (data.type === 'folder' && data.id === targetFolderId) return;
            const result = await this.api('move', {
                items: JSON.stringify([data]),
                target_folder_id: targetFolderId
            });
            this.toast(result.message, result.success ? 'success' : 'error');
            if (result.success) this.refresh();
        } catch (ex) {}
    },

    // ==================== PREVIEW ====================
    async previewFile(id) {
        const data = await this.api('get_file', { id });
        if (!data.success) return this.toast(data.message, 'error');
        const file = data.file;
        this.state.previewFileId = id;

        const ext = (file.extension || '').toLowerCase();
        const overlay = document.getElementById('previewOverlay');
        const container = document.getElementById('previewContainer');
        document.getElementById('previewTitle').textContent = file.original_name;

        const editBtn = document.getElementById('previewEditBtn');
        editBtn.style.display = this.isEditable(ext) ? '' : 'none';

        let html = '';
        if (['jpg','jpeg','png','gif','webp','svg','bmp'].includes(ext)) {
            html = `<img src="api/download.php?type=preview&id=${id}" alt="${this.esc(file.original_name)}">`;
        } else if (ext === 'pdf') {
            html = `<iframe src="api/download.php?type=preview&id=${id}" style="width:100%;height:100%"></iframe>`;
        } else if (['mp4','webm','ogg'].includes(ext)) {
            html = `<video controls autoplay style="max-width:100%"><source src="api/download.php?type=preview&id=${id}" type="${file.mime_type}"></video>`;
        } else if (['mp3','wav','ogg','flac','aac'].includes(ext)) {
            html = `<div style="text-align:center;padding:40px"><i class="bi bi-music-note-beamed" style="font-size:64px;color:var(--accent);margin-bottom:20px;display:block"></i>
                <audio controls autoplay><source src="api/download.php?type=preview&id=${id}" type="${file.mime_type}"></audio></div>`;
        } else if (['doc','docx','xls','xlsx','ppt','pptx'].includes(ext)) {
            // Documentos Office - mostrar info e opções
            html = `<div style="text-align:center;padding:40px">
                <i class="bi ${ext.includes('doc') ? 'bi-file-word' : ext.includes('xls') ? 'bi-file-excel' : 'bi-file-ppt'}" 
                   style="font-size:64px;color:var(--accent);display:block;margin-bottom:20px"></i>
                <h3 style="margin-bottom:8px">${this.esc(file.original_name)}</h3>
                <p style="color:var(--text-secondary);margin-bottom:24px">${this.formatSize(file.size)}</p>
                <div style="background:var(--bg-tertiary);padding:20px;border-radius:var(--radius);margin-bottom:20px;max-width:500px;display:inline-block;text-align:left">
                    <h4 style="margin-bottom:8px"><i class="bi bi-info-circle"></i> Editor de Documentos Office</h4>
                    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
                        Para edição completa de documentos Office diretamente no navegador, configure o 
                        <strong>OnlyOffice Document Server</strong> nas configurações do sistema.</p>
                    <p style="font-size:12px;color:var(--text-muted)">Administração → Configurações → Integrações</p>
                </div><br>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                    <a href="api/download.php?type=file&id=${id}" class="btn btn-primary btn-lg"><i class="bi bi-download"></i> Baixar</a>
                </div>
            </div>`;
        } else if (this.isEditable(ext) || ['txt','log','ini','env'].includes(ext)) {
            const content = await this.api('read_content', { id });
            if (content.success) {
                html = `<pre>${this.esc(content.content)}</pre>`;
            }
        } else {
            html = `<div class="empty-state"><i class="bi bi-file-earmark"></i><h3>Pré-visualização não disponível</h3><p>Baixe o arquivo para visualizá-lo.</p></div>`;
        }

        container.innerHTML = html;
        overlay.classList.add('show');
    },

    closePreview() {
        document.getElementById('previewOverlay').classList.remove('show');
        document.getElementById('previewContainer').innerHTML = '';
    },

    downloadFile(id) {
        window.open(`api/download.php?type=file&id=${id}`, '_blank');
    },

    downloadPreviewFile() {
        if (this.state.previewFileId) this.downloadFile(this.state.previewFileId);
    },

    editFromPreview() {
        const id = this.state.previewFileId;
        this.closePreview();
        this.openEditor(id);
    },

    // ==================== EDITOR ====================
    async openEditor(fileId) {
        const data = await this.api('read_content', { id: fileId });
        if (!data.success) return this.toast(data.message || 'Erro ao abrir arquivo.', 'error');

        this.state.editorFileId = fileId;
        document.getElementById('editorTitle').textContent = data.file.original_name;
        document.getElementById('editorStatus').textContent = 'v' + data.file.version + ' — ' + this.formatSize(data.file.size);
        document.getElementById('editorOverlay').classList.add('show');

        // Inicializar Monaco
        if (!this.state.monacoEditor) {
            require.config({ paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs' } });
            await new Promise(resolve => {
                require(['vs/editor/editor.main'], resolve);
            });
        }

        const el = document.getElementById('code-editor');
        el.innerHTML = '';
        const theme = this.state.theme === 'dark' ? 'vs-dark' : 'vs';
        
        this.state.monacoEditor = monaco.editor.create(el, {
            value: data.content,
            language: data.mode === 'plaintext' ? 'plaintext' : data.mode,
            theme: theme,
            fontSize: 14,
            fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
            minimap: { enabled: true },
            wordWrap: 'on',
            automaticLayout: true,
            scrollBeyondLastLine: false,
            padding: { top: 12 },
            lineNumbers: 'on',
            renderWhitespace: 'selection',
            bracketPairColorization: { enabled: true }
        });

        // Ctrl+S para salvar
        this.state.monacoEditor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
            this.editorSave();
        });
    },

    async editorSave() {
        if (!this.state.monacoEditor || !this.state.editorFileId) return;
        const content = this.state.monacoEditor.getValue();
        const result = await this.api('save_content', { id: this.state.editorFileId, content });
        if (result.success) {
            this.toast('Arquivo salvo!', 'success');
            document.getElementById('editorStatus').textContent = this.formatSize(result.size) + ' — Salvo!';
        } else {
            this.toast(result.message, 'error');
        }
    },

    async editorSaveAs() {
        const name = await this.prompt('Salvar como', 'novo-arquivo.txt');
        if (!name) return;
        const content = this.state.monacoEditor.getValue();
        const result = await this.api('create_file', { name, content, folder_id: this.state.currentFolder || '' });
        this.toast(result.message, result.success ? 'success' : 'error');
    },

    closeEditor() {
        document.getElementById('editorOverlay').classList.remove('show');
        if (this.state.monacoEditor) {
            this.state.monacoEditor.dispose();
            this.state.monacoEditor = null;
        }
        this.state.editorFileId = null;
        this.refresh();
    },

    // ==================== PROPERTIES ====================
    async showProperties(type, id) {
        let data;
        if (type === 'file') {
            data = await this.api('get_file', { id });
            if (!data.success) return;
            const f = data.file;
            this.openModal('Propriedades', `
                <div style="text-align:center;margin-bottom:20px">
                    <div class="file-icon" style="width:56px;height:56px;font-size:24px;margin:0 auto;background:${this.getFileColor(f.extension)}18;color:${this.getFileColor(f.extension)};border-radius:var(--radius)">
                        <i class="bi ${this.getFileIcon(f.extension)}"></i>
                    </div>
                    <h4 style="margin-top:8px">${this.esc(f.original_name)}</h4>
                </div>
                <div class="prop-row"><span class="prop-label">Tipo</span><span class="prop-value">${(f.extension||'').toUpperCase()} — ${this.esc(f.mime_type||'')}</span></div>
                <div class="prop-row"><span class="prop-label">Tamanho</span><span class="prop-value">${this.formatSize(f.size)}</span></div>
                <div class="prop-row"><span class="prop-label">Criado</span><span class="prop-value">${f.created_at}</span></div>
                <div class="prop-row"><span class="prop-label">Modificado</span><span class="prop-value">${f.updated_at}</span></div>
                <div class="prop-row"><span class="prop-label">Versão</span><span class="prop-value">${f.version}</span></div>
                <div class="prop-row"><span class="prop-label">Downloads</span><span class="prop-value">${f.download_count}</span></div>
                <div class="prop-row"><span class="prop-label">Hash SHA256</span><span class="prop-value" style="font-size:10px;font-family:var(--font-mono)">${f.hash_sha256||'—'}</span></div>
            `, '<button class="btn btn-secondary" onclick="CV.closeModal()">Fechar</button>');
        }
    },

    // ==================== ADMIN ====================
    async loadAdminDashboard() {
        this.showLoading();
        this.setBreadcrumbText('Dashboard');
        document.getElementById('toolbar').style.display = 'none';
        const data = await this.api('admin_dashboard');
        if (!data.success) return;

        let html = '<div class="admin-grid">';
        html += this.statCard('bi-people', 'var(--accent)', 'Usuários ativos', data.total_users);
        html += this.statCard('bi-file-earmark', 'var(--success)', 'Total de arquivos', data.total_files);
        html += this.statCard('bi-hdd', 'var(--warning)', 'Armazenamento', this.formatSize(data.total_storage || 0));
        html += '</div>';

        html += '<h4 style="margin-bottom:12px">Atividade Recente</h4>';
        html += '<table class="data-table"><thead><tr><th>Usuário</th><th>Ação</th><th>Item</th><th>Data</th></tr></thead><tbody>';
        (data.recent_logs || []).forEach(l => {
            html += `<tr><td>${this.esc(l.username||'Sistema')}</td><td><span class="status-badge active">${l.action}</span></td>
                <td>${this.esc(l.entity_name||'—')}</td><td>${this.timeAgo(l.created_at)}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('fileContent').innerHTML = html;
    },

    statCard(icon, color, label, value) {
        return `<div class="stat-card">
            <div class="stat-icon" style="background:${color}18;color:${color}"><i class="bi ${icon}"></i></div>
            <div class="stat-value">${value}</div>
            <div class="stat-label">${label}</div>
        </div>`;
    },

    async loadAdminUsers() {
        this.showLoading();
        this.setBreadcrumbText('Gerenciar Usuários');
        document.getElementById('toolbar').style.display = 'none';
        const data = await this.api('admin_list_users');
        const roles = await this.api('admin_roles');
        if (!data.success) return;

        let html = '<div style="margin-bottom:16px"><button class="btn btn-primary btn-sm" onclick="CV.showUserModal()"><i class="bi bi-person-plus"></i> Novo Usuário</button></div>';
        html += '<table class="data-table"><thead><tr><th>Usuário</th><th>E-mail</th><th>Papel</th><th>Arquivos</th><th>Armazenamento</th><th>Status</th><th>Ações</th></tr></thead><tbody>';
        (data.users || []).forEach(u => {
            html += `<tr>
                <td><strong>${this.esc(u.full_name)}</strong><br><small style="color:var(--text-muted)">${this.esc(u.username)}</small></td>
                <td>${this.esc(u.email)}</td>
                <td>${this.esc(u.role_name)}</td>
                <td>${u.file_count}</td>
                <td>${this.formatSize(u.storage_used)} / ${this.formatSize(u.storage_quota)}</td>
                <td><span class="status-badge ${u.status}">${u.status}</span></td>
                <td>
                    <button class="btn btn-ghost btn-sm" onclick="CV.showUserModal(${u.id})"><i class="bi bi-pencil"></i></button>
                    ${u.id != CV.state.user.id ? `<button class="btn btn-ghost btn-sm" onclick="CV.deactivateUser(${u.id})"><i class="bi bi-person-x"></i></button>` : ''}
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        this._roles = roles.success ? roles.roles : [];
        document.getElementById('fileContent').innerHTML = html;
    },

    showUserModal(userId = null) {
        const user = userId ? null : null; // Para edição, faríamos um fetch
        const rolesOpts = (this._roles || []).map(r => `<option value="${r.id}">${this.esc(r.name)}</option>`).join('');
        this.openModal(userId ? 'Editar Usuário' : 'Novo Usuário', `
            <input type="hidden" id="userEditId" value="${userId || ''}">
            <div class="form-group"><label>Nome completo</label><input type="text" class="form-control" id="userFullName"></div>
            <div class="form-group"><label>Usuário</label><input type="text" class="form-control" id="userUsername"></div>
            <div class="form-group"><label>E-mail</label><input type="email" class="form-control" id="userEmail"></div>
            <div class="form-group"><label>Senha ${userId ? '(deixe vazio para manter)' : ''}</label><input type="password" class="form-control" id="userPassword"></div>
            <div class="form-group"><label>Papel</label><select class="form-control" id="userRole">${rolesOpts}</select></div>
            <div class="form-group"><label>Quota (bytes)</label><input type="number" class="form-control" id="userQuota" value="1073741824"></div>
            <div class="form-group"><label>Status</label><select class="form-control" id="userStatus">
                <option value="active">Ativo</option><option value="inactive">Inativo</option><option value="suspended">Suspenso</option>
            </select></div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="CV.saveUser()">Salvar</button>`);
    },

    async saveUser() {
        const result = await this.api('admin_save_user', {
            id: document.getElementById('userEditId').value,
            full_name: document.getElementById('userFullName').value,
            username: document.getElementById('userUsername').value,
            email: document.getElementById('userEmail').value,
            password: document.getElementById('userPassword').value,
            role_id: document.getElementById('userRole').value,
            storage_quota: document.getElementById('userQuota').value,
            status: document.getElementById('userStatus').value
        });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) { this.closeModal(); this.loadAdminUsers(); }
    },

    async deactivateUser(id) {
        const ok = await this.confirm('Desativar usuário', 'Este usuário perderá o acesso ao sistema.', 'Desativar', true);
        if (!ok) return;
        const result = await this.api('admin_delete_user', { id });
        this.toast(result.message, result.success ? 'success' : 'error');
        if (result.success) this.loadAdminUsers();
    },

    async loadAdminLogs() {
        this.showLoading();
        this.setBreadcrumbText('Logs de Auditoria');
        document.getElementById('toolbar').style.display = 'none';
        const data = await this.api('admin_logs', { limit: 100 });
        if (!data.success) return;

        let html = '<table class="data-table"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Item</th><th>IP</th></tr></thead><tbody>';
        (data.data || []).forEach(l => {
            html += `<tr>
                <td style="white-space:nowrap;font-size:12px">${l.created_at}</td>
                <td>${this.esc(l.username||'Sistema')}</td>
                <td><span class="status-badge active">${l.action}</span></td>
                <td>${this.esc(l.entity_name||'—')} <small style="color:var(--text-muted)">${l.entity_type||''}</small></td>
                <td style="font-family:var(--font-mono);font-size:12px">${l.ip_address||''}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('fileContent').innerHTML = html;
    },

    async loadAdminSettings() {
        this.showLoading();
        this.setBreadcrumbText('Configurações do Sistema');
        document.getElementById('toolbar').style.display = 'none';
        const data = await this.api('admin_settings');
        if (!data.success) return;

        const grouped = {};
        (data.settings || []).forEach(s => {
            if (!grouped[s.category]) grouped[s.category] = [];
            grouped[s.category].push(s);
        });

        const catNames = { general: 'Geral', uploads: 'Uploads', storage: 'Armazenamento', features: 'Funcionalidades', integrations: 'Integrações' };
        let html = '<div style="max-width:700px">';
        
        for (const [cat, settings] of Object.entries(grouped)) {
            html += `<div style="margin-bottom:24px">`;
            html += `<h4 style="margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border)">${catNames[cat] || cat}</h4>`;
            settings.forEach(s => {
                const id = 'setting_' + s.setting_key;
                html += `<div class="form-group">`;
                html += `<label>${this.esc(s.description || s.setting_key)}</label>`;
                if (s.setting_type === 'bool') {
                    html += `<select class="form-control setting-input" id="${id}" data-key="${s.setting_key}">
                        <option value="1" ${s.setting_value === '1' ? 'selected' : ''}>Sim</option>
                        <option value="0" ${s.setting_value === '0' ? 'selected' : ''}>Não</option>
                    </select>`;
                } else if (s.setting_key.includes('secret')) {
                    html += `<input type="password" class="form-control setting-input" id="${id}" data-key="${s.setting_key}" value="${this.esc(s.setting_value || '')}" placeholder="••••••">`;
                } else {
                    html += `<input type="text" class="form-control setting-input" id="${id}" data-key="${s.setting_key}" value="${this.esc(s.setting_value || '')}">`;
                }
                html += `</div>`;
            });
            html += `</div>`;
        }
        
        html += `<button class="btn btn-primary" onclick="CV.saveAdminSettings()"><i class="bi bi-check-lg"></i> Salvar Configurações</button>`;
        html += '</div>';
        document.getElementById('fileContent').innerHTML = html;
    },

    async saveAdminSettings() {
        const settings = {};
        document.querySelectorAll('.setting-input').forEach(el => {
            settings[el.dataset.key] = el.value;
        });
        const result = await this.api('admin_settings', { settings: JSON.stringify(settings) });
        this.toast(result.message, result.success ? 'success' : 'error');
    },

    // ==================== SETTINGS ====================
    showSettingsModal() {
        this.openModal('Configurações', `
            <div class="form-group">
                <label>Tema</label>
                <select class="form-control" id="settTheme">
                    <option value="dark" ${this.state.theme==='dark'?'selected':''}>Escuro</option>
                    <option value="light" ${this.state.theme==='light'?'selected':''}>Claro</option>
                </select>
            </div>
            <div class="form-group">
                <label>Visualização padrão</label>
                <select class="form-control" id="settView">
                    <option value="list" ${this.state.currentView==='list'?'selected':''}>Lista</option>
                    <option value="grid" ${this.state.currentView==='grid'?'selected':''}>Grade</option>
                </select>
            </div>
            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                <button class="btn btn-danger btn-sm" onclick="CV.logout()"><i class="bi bi-box-arrow-right"></i> Sair da conta</button>
            </div>
        `, `<button class="btn btn-secondary" onclick="CV.closeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="CV.saveSettings()">Salvar</button>`);
    },

    async saveSettings() {
        const theme = document.getElementById('settTheme').value;
        const viewMode = document.getElementById('settView').value;
        this.state.theme = theme;
        this.state.currentView = viewMode;
        document.documentElement.setAttribute('data-theme', theme);
        this.updateThemeIcon();
        this.setView(viewMode);
        await this.api('save_preferences', { theme, view_mode: viewMode });
        this.closeModal();
        this.toast('Preferências salvas!', 'success');
    },

    // ==================== UTILIDADES ====================
    toggleTheme() {
        this.state.theme = this.state.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', this.state.theme);
        this.updateThemeIcon();
        this.api('save_preferences', { theme: this.state.theme, view_mode: this.state.currentView });
    },

    updateThemeIcon() {
        const icon = document.querySelector('#themeToggle i');
        icon.className = this.state.theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    },

    setView(mode) {
        this.state.currentView = mode;
        document.getElementById('viewList').classList.toggle('active', mode === 'list');
        document.getElementById('viewGrid').classList.toggle('active', mode === 'grid');
        if (this.state.currentNav === 'files') this.loadFolder(this.state.currentFolder);
    },

    changeSort(val) {
        const [sort, order] = val.split('-');
        this.state.sort = sort;
        this.state.order = order;
        this.refresh();
    },

    refresh() {
        switch (this.state.currentNav) {
            case 'files': this.loadFolder(this.state.currentFolder); break;
            case 'recent': this.loadRecent(); break;
            case 'favorites': this.loadFavorites(); break;
            case 'trash': this.loadTrash(); break;
            case 'shared': this.loadShared(); break;
            case 'admin-dashboard': this.loadAdminDashboard(); break;
            case 'admin-users': this.loadAdminUsers(); break;
            case 'admin-logs': this.loadAdminLogs(); break;
            case 'admin-settings': this.loadAdminSettings(); break;
        }
    },

    debounceSearch(value) {
        clearTimeout(this.state.searchTimeout);
        if (value.length < 2) {
            if (this.state.currentNav === 'files') this.loadFolder(this.state.currentFolder);
            return;
        }
        this.state.searchTimeout = setTimeout(async () => {
            const data = await this.api('search', { query: value });
            if (data.success) {
                this.renderFiles(data.folders, data.files);
            }
        }, 300);
    },

    updateStats(stats) {
        if (!stats) return;
        const pct = stats.storage_percent || 0;
        document.getElementById('storageBar').style.width = pct + '%';
        document.getElementById('storageText').textContent = `${this.formatSize(stats.storage_used)} de ${this.formatSize(stats.storage_quota)}`;
        
        const trashBadge = document.getElementById('trashBadge');
        if (stats.trash_count > 0) {
            trashBadge.textContent = stats.trash_count;
            trashBadge.style.display = '';
        } else {
            trashBadge.style.display = 'none';
        }
    },

    async logout() {
        await this.api('logout');
        window.location.href = 'login.php';
    },

    // Sidebar
    toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    },
    closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    },

    // Keyboard
    initKeyboard() {
        document.addEventListener('keydown', (e) => {
            // Não interceptar teclas quando o editor de documentos está aberto
            const docEditorOpen = document.getElementById('docEditorOverlay')?.classList.contains('show');
            if (docEditorOpen) return;

            // Não interceptar quando foco está em input/textarea/select
            const tag = document.activeElement?.tagName?.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (document.activeElement?.isContentEditable) return;

            if (e.key === 'Delete' && this.state.selectedItems.length) {
                e.preventDefault();
                this.batchTrash();
            }
            if (e.key === 'Escape') {
                this.closeModal();
                this.closePreview();
                this.closeEditor();
                this.hideContextMenu();
            }
            if (e.key === 'F2' && this.state.selectedItems.length === 1) {
                e.preventDefault();
                const s = this.state.selectedItems[0];
                this.showRenameModal(s.type, s.id);
            }
        });
        document.getElementById('sidebarOverlay').addEventListener('click', () => this.closeSidebar());
    },

    // ==================== LASSO SELECT (drag to select) ====================
    initLassoSelect() {
        const area = document.getElementById('fileArea');
        const rect = document.getElementById('lassoRect');
        let active = false, startX = 0, startY = 0, addMode = false;

        area.addEventListener('mousedown', (e) => {
            // Only start lasso on empty space (not on items, buttons, toolbar, scrollbar)
            if (e.button !== 0) return;
            if (e.target.closest('.file-item, .file-grid-item, .btn, .checkbox, .grid-checkbox, .toolbar, .file-list-header, .sort-select, .view-toggle, a, button, input, select')) return;
            // Don't activate if clicking on scrollbar
            if (e.offsetX > area.clientWidth) return;

            active = true;
            addMode = e.ctrlKey || e.metaKey;
            const areaRect = area.getBoundingClientRect();
            startX = e.clientX - areaRect.left + area.scrollLeft;
            startY = e.clientY - areaRect.top + area.scrollTop;

            if (!addMode) this.clearSelection();

            rect.style.left = startX + 'px';
            rect.style.top = startY + 'px';
            rect.style.width = '0';
            rect.style.height = '0';
            rect.style.display = 'block';
            area.style.userSelect = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!active) return;
            const areaRect = area.getBoundingClientRect();
            const curX = e.clientX - areaRect.left + area.scrollLeft;
            const curY = e.clientY - areaRect.top + area.scrollTop;

            const x = Math.min(startX, curX);
            const y = Math.min(startY, curY);
            const w = Math.abs(curX - startX);
            const h = Math.abs(curY - startY);

            rect.style.left = x + 'px';
            rect.style.top = y + 'px';
            rect.style.width = w + 'px';
            rect.style.height = h + 'px';

            // Check intersections with file items
            if (w > 5 || h > 5) {
                const selRect = { left: x, top: y, right: x + w, bottom: y + h };
                document.querySelectorAll('.file-item, .file-grid-item').forEach(el => {
                    const elRect = el.getBoundingClientRect();
                    const itemRect = {
                        left: elRect.left - areaRect.left + area.scrollLeft,
                        top: elRect.top - areaRect.top + area.scrollTop,
                        right: elRect.right - areaRect.left + area.scrollLeft,
                        bottom: elRect.bottom - areaRect.top + area.scrollTop
                    };
                    const intersects = !(selRect.right < itemRect.left || selRect.left > itemRect.right ||
                                         selRect.bottom < itemRect.top || selRect.top > itemRect.bottom);
                    const id = parseInt(el.dataset.id);
                    const type = el.dataset.type;
                    const isSelected = this.state.selectedItems.some(s => s.id === id && s.type === type);

                    if (intersects && !isSelected) {
                        this.state.selectedItems.push({ type, id });
                    } else if (!intersects && !addMode && isSelected) {
                        this.state.selectedItems = this.state.selectedItems.filter(s => !(s.id === id && s.type === type));
                    }
                });
                this.updateSelectionUI();
            }
        });

        document.addEventListener('mouseup', () => {
            if (!active) return;
            active = false;
            rect.style.display = 'none';
            area.style.userSelect = '';
        });
    },

    // Modal
    openModal(title, body, footer = '') {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = body;
        document.getElementById('modalFooter').innerHTML = footer;
        document.getElementById('modalOverlay').classList.add('show');
    },
    closeModal() {
        document.getElementById('modalOverlay').classList.remove('show');
    },

    // Toast
    toast(message, type = 'info') {
        const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            },
            customClass: { popup: 'swal-toast-custom' }
        });
        Toast.fire({ icon: iconMap[type] || 'info', title: message });
    },

    // Confirm dialog usando SweetAlert2
    async confirm(title, text, confirmText = 'Sim', isDanger = false) {
        const result = await Swal.fire({
            title: title,
            text: text,
            icon: isDanger ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancelar',
            confirmButtonColor: isDanger ? 'var(--danger)' : 'var(--accent)',
            cancelButtonColor: 'var(--bg-tertiary)',
            background: 'var(--bg-modal)',
            color: 'var(--text-primary)',
            customClass: {
                popup: 'swal-custom-popup',
                confirmButton: 'swal-confirm-btn',
                cancelButton: 'swal-cancel-btn'
            }
        });
        return result.isConfirmed;
    },

    // Prompt dialog usando SweetAlert2
    async prompt(title, defaultValue = '', placeholder = '') {
        const result = await Swal.fire({
            title: title,
            input: 'text',
            inputValue: defaultValue,
            inputPlaceholder: placeholder,
            showCancelButton: true,
            confirmButtonText: 'Confirmar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: 'var(--accent)',
            cancelButtonColor: 'var(--bg-tertiary)',
            background: 'var(--bg-modal)',
            color: 'var(--text-primary)',
            customClass: {
                popup: 'swal-custom-popup',
                input: 'swal-custom-input',
                confirmButton: 'swal-confirm-btn',
                cancelButton: 'swal-cancel-btn'
            },
            inputValidator: (value) => {
                if (!value || !value.trim()) return 'Por favor, preencha o campo.';
            }
        });
        return result.isConfirmed ? result.value : null;
    },

    showLoading() {
        let html = '';
        for (let i = 0; i < 8; i++) {
            html += `<div class="file-item"><div></div><div class="file-name-cell"><div class="skeleton" style="width:32px;height:32px"></div><div class="skeleton" style="width:${120 + Math.random()*100}px;height:16px"></div></div>
                <div class="skeleton" style="width:60px;height:14px"></div><div class="skeleton" style="width:40px;height:14px"></div><div class="skeleton" style="width:80px;height:14px"></div><div></div></div>`;
        }
        document.getElementById('fileContent').innerHTML = html;
    },

    // Helpers
    formatSize(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const units = ['B','KB','MB','GB','TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    },

    timeAgo(datetime) {
        const diff = (Date.now() - new Date(datetime).getTime()) / 1000;
        if (diff < 60) return 'agora';
        if (diff < 3600) return Math.floor(diff / 60) + 'min';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd';
        return new Date(datetime).toLocaleDateString('pt-BR');
    },

    esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    getFileIcon(ext) {
        const map = {
            jpg:'bi-file-image',jpeg:'bi-file-image',png:'bi-file-image',gif:'bi-file-image',webp:'bi-file-image',svg:'bi-file-image',
            pdf:'bi-file-pdf',doc:'bi-file-word',docx:'bi-file-word',xls:'bi-file-excel',xlsx:'bi-file-excel',csv:'bi-file-excel',
            ppt:'bi-file-ppt',pptx:'bi-file-ppt',txt:'bi-file-text',log:'bi-file-text',md:'bi-file-text',
            html:'bi-file-code',css:'bi-file-code',js:'bi-file-code',json:'bi-file-code',xml:'bi-file-code',
            zip:'bi-file-zip',rar:'bi-file-zip','7z':'bi-file-zip',tar:'bi-file-zip',
            mp4:'bi-file-play',avi:'bi-file-play',mkv:'bi-file-play',webm:'bi-file-play',
            mp3:'bi-file-music',wav:'bi-file-music',ogg:'bi-file-music',
        };
        return map[(ext||'').toLowerCase()] || 'bi-file-earmark';
    },

    getFileColor(ext) {
        const map = {
            jpg:'#e74c3c',jpeg:'#e74c3c',png:'#e74c3c',gif:'#e67e22',webp:'#e74c3c',svg:'#9b59b6',
            pdf:'#c0392b',doc:'#2980b9',docx:'#2980b9',xls:'#27ae60',xlsx:'#27ae60',csv:'#27ae60',
            ppt:'#d35400',pptx:'#d35400',txt:'#95a5a6',html:'#e67e22',css:'#3498db',js:'#f39c12',json:'#f39c12',
            zip:'#8e44ad',mp4:'#e74c3c',mp3:'#1abc9c',md:'#7f8c8d',
        };
        return map[(ext||'').toLowerCase()] || '#6c757d';
    },

    isEditable(ext) {
        return ['txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml','svg','env'].includes((ext||'').toLowerCase());
    },

    getItemExt(id) {
        const file = (this.state.items.files || []).find(f => f.id == id);
        return file ? (file.extension || '') : '';
    },

    findItem(type, id) {
        if (type === 'folder') return (this.state.items.folders || []).find(f => f.id == id);
        return (this.state.items.files || []).find(f => f.id == id);
    }
};

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    CV.init();
    
    // Handle Google OAuth return
    var params = new URLSearchParams(window.location.search);
    if (params.get('google_auth') === 'success') {
        CV.toast('Conta Google conectada!', 'success');
        var fileToOpen = params.get('google_open_file');
        if (fileToOpen) {
            setTimeout(function() { DocEditor.open(parseInt(fileToOpen)); }, 500);
        }
        // Clean URL
        history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('google_error')) {
        CV.toast(decodeURIComponent(params.get('google_error')), 'error');
        history.replaceState({}, '', window.location.pathname);
    }
});

// Enter nos modais
document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && document.getElementById('modalOverlay').classList.contains('show')) {
        const btn = document.querySelector('#modalFooter .btn-primary');
        if (btn && document.activeElement?.tagName !== 'TEXTAREA') btn.click();
    }
});
</script>
</body>
</html>
