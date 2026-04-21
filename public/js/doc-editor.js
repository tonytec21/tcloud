/**
 * TCloud Document Editor v4.0
 * Complete WYSIWYG editor integrated with TCloud
 *
 * Features: Rich editing, A4 pages with ABNT margins, ruler with margin handles,
 * first-line indent, manual page breaks (visual gaps), headers/footers,
 * images, tables, keyboard shortcuts, undo/redo, autosave, version history,
 * PDF export, spellcheck, spreadsheet/PDF/code editors
 */

var DocEditor = {

    // ═══════════════════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════════════════

    _s: {
        fileId:null, fileName:'', fileExt:'', editorType:null,
        isDirty:false, canEdit:false, isFullscreen:false,
        tinymce:null, spreadsheet:null, monacoEditor:null,
        autosaveTimer:null, onlyoffice:null,
    },

    _heartbeatTimer: null,

    // ABNT margins (px at 96dpi)
    _m: { T:113, B:76, L:113, R:76, PW:794, PH:1123 },

    // Ruler
    _ruler: { ml:113, mr:76, fi:0, dragging:null },

    // PDF viewer
    _pdf: { doc:null, pg:1, sc:1.5, tot:1 },


    // ═══════════════════════════════════════════════════════
    // OPEN
    // ═══════════════════════════════════════════════════════

    open: async function(fileId) {
        // Lock file before opening
        var lockResult = await CV.api('lock_file', {id: fileId});
        if (!lockResult.success && lockResult.locked) {
            var r = await Swal.fire({
                title:'Arquivo em uso',
                html:'<b>' + CV.esc(lockResult.locked_by) + '</b> está editando este arquivo.',
                icon:'warning',
                showCancelButton:true,
                confirmButtonText:'Abrir mesmo assim',
                cancelButtonText:'Cancelar',
                confirmButtonColor:'var(--accent)',
                background:'var(--bg-modal)',color:'var(--text-primary)',
                customClass:{popup:'swal-custom-popup'}
            });
            if (!r.isConfirmed) return;
        }

        var d = await CV.api('doc_open',{id:fileId});
        if (!d.success) { CV.toast(d.message||'Erro ao abrir.','error'); return; }

        var f=d.file, ext=(f.extension||'').toLowerCase(), s=this._s;
        s.fileId=fileId; s.fileName=f.original_name; s.fileExt=ext;
        s.editorType=d.editor_type; s.canEdit=d.can_edit; s.isDirty=false;
        s.onlyoffice=null; // ONLYOFFICE editor instance

        document.getElementById('docEditorOverlay').classList.add('show','doc-fullscreen');
        this._s.isFullscreen=true;
        var fsBtn=document.getElementById('docEditorFullscreenBtn');
        if(fsBtn)fsBtn.innerHTML='<i class="bi bi-fullscreen-exit"></i>';
        document.getElementById('docEditorIcon').innerHTML='<i class="bi '+CV.getFileIcon(ext)+'" style="color:'+CV.getFileColor(ext)+'"></i>';
        document.getElementById('docEditorTitle').textContent=f.original_name;
        this._status('v'+f.version+' \u2014 '+CV.formatSize(f.size));

        var body=document.getElementById('docEditorBody');
        body.innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted)"><i class="bi bi-arrow-repeat spin" style="font-size:24px;margin-right:12px"></i> Carregando editor...</div>';

        // Check if ONLYOFFICE or Google Workspace is available for Office files
        var officeExts = ['doc','docx','docm','dotx','odt','rtf','xls','xlsx','xlsm','xltx','ods','csv','ppt','pptx','pptm','potx','odp'];
        var isOffice = officeExts.indexOf(ext) !== -1;

        if (isOffice) {
            // Priority 1: Try Google Workspace
            try {
                var gwCheck = await CV.api('gw_check', {id: fileId});
                if (gwCheck.enabled) {
                    document.getElementById('docEditorSaveBtn').style.display = 'none';
                    document.getElementById('docEditorSaveAsBtn').style.display = 'none';
                    document.getElementById('docEditorPdfBtn').style.display = 'none';

                    if (!gwCheck.has_token) {
                        // Precisa autorizar — abrir popup OAuth
                        this._showGoogleAuth(body, gwCheck.auth_url, fileId);
                        return;
                    }
                    // Já autorizado — abrir no Google
                    await this._initGoogleEditor(body, fileId, f.original_name);
                    this._startHeartbeat();
                    return;
                }
            } catch(e) {
                console.warn('Google Workspace not available:', e);
            }

            // Priority 2: Try ONLYOFFICE
            try {
                var ooData = await CV.api('office_editor', {id: fileId, mode: d.can_edit ? 'edit' : 'view'});
                if (ooData.success && ooData.available && ooData.config) {
                    document.getElementById('docEditorSaveBtn').style.display = 'none';
                    document.getElementById('docEditorSaveAsBtn').style.display = 'none';
                    document.getElementById('docEditorPdfBtn').style.display = 'none';
                    await this._initOnlyOffice(body, ooData);
                    this._startHeartbeat();
                    return;
                }
            } catch(e) {
                console.warn('OnlyOffice not available:', e);
            }
        }

        // Fallback: use built-in editors (TinyMCE, x-spreadsheet, pdf.js, Monaco)
        var isDoc = d.editor_type==='document';
        document.getElementById('docEditorSaveBtn').style.display = d.can_edit&&d.editor_type!=='pdf'?'':'none';
        document.getElementById('docEditorSaveAsBtn').style.display = d.editor_type!=='pdf'?'':'none';
        document.getElementById('docEditorPdfBtn').style.display = isDoc?'':'none';

        try {
            switch(d.editor_type) {
                case 'document':    await this._initDoc(body,d.html_content||'<p></p>',d.can_edit); break;
                case 'spreadsheet': await this._initSheet(body,d.sheet_data,d.can_edit); break;
                case 'pdf':         this._initPdfViewer(body,d.preview_url); break;
                case 'code':        await this._initCode(body,d.content||'',d.language,d.can_edit); break;
                default: body.innerHTML='<div class="empty-state"><i class="bi bi-file-earmark"></i><h3>Formato n\u00e3o suportado</h3></div>';
            }
        } catch(err) {
            console.error('Editor error:',err);
            body.innerHTML='<div class="empty-state"><i class="bi bi-exclamation-circle"></i><h3>Erro</h3><p>'+(err.message||'')+'</p></div>';
        }

        // Start heartbeat to keep the lock alive
        this._startHeartbeat();
    },

    _startHeartbeat: function() {
        this._stopHeartbeat();
        this._heartbeatTimer = setInterval(function() {
            CV.api('heartbeat').catch(function(){});
        }, 30000); // every 30s
    },

    _stopHeartbeat: function() {
        if (this._heartbeatTimer) { clearInterval(this._heartbeatTimer); this._heartbeatTimer = null; }
    },


    // ═══════════════════════════════════════════════════════
    // ONLYOFFICE DOCUMENT SERVER INTEGRATION
    // ═══════════════════════════════════════════════════════

    _initOnlyOffice: async function(container, ooData) {
        var self = this;
        var apiUrl = ooData.apiUrl;
        var config = ooData.config;

        // Container for the editor
        container.innerHTML = '<div id="oo-editor-frame" style="flex:1;width:100%;height:100%"></div>';

        // Load ONLYOFFICE JS API
        if (typeof DocsAPI === 'undefined') {
            await this._loadJs(apiUrl);
        }

        if (typeof DocsAPI === 'undefined') {
            container.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle"></i><h3>Erro ao carregar OnlyOffice</h3><p>Verifique se o Document Server est\u00e1 acess\u00edvel.</p></div>';
            return;
        }

        // Add event handlers to config
        config.events = {
            onDocumentReady: function() {
                self._status(self._s.fileName + ' \u2014 Pronto');
            },
            onDocumentStateChange: function(e) {
                if (e.data) {
                    self._s.isDirty = true;
                    self._status('Editando...');
                } else {
                    self._s.isDirty = false;
                    self._status('Salvo');
                }
            },
            onError: function(e) {
                console.error('OnlyOffice error:', e);
                CV.toast('Erro no editor: ' + (e.data || ''), 'error');
            },
            onRequestClose: function() {
                self._forceClose();
            },
        };

        // Create the editor
        try {
            this._s.onlyoffice = new DocsAPI.DocEditor('oo-editor-frame', config);
            this._status('Conectando ao OnlyOffice...');
        } catch(e) {
            console.error('OnlyOffice init error:', e);
            container.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle"></i><h3>Erro ao inicializar editor</h3><p>' + e.message + '</p></div>';
        }
    },


    // ═══════════════════════════════════════════════════════
    // GOOGLE WORKSPACE INTEGRATION (iframe + auto-save on close)
    // ═══════════════════════════════════════════════════════

    _gwGoogleFileId: null,
    _gwSaving: false,

    /**
     * Show OAuth authorization screen
     */
    _showGoogleAuth: function(container, authUrl, fileId) {
        container.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:20px;padding:40px">' +
            '<div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#4285f4,#34a853,#fbbc05,#ea4335);display:flex;align-items:center;justify-content:center">' +
            '<i class="bi bi-google" style="font-size:28px;color:#fff"></i></div>' +
            '<h3 style="margin:0">Conectar ao Google Workspace</h3>' +
            '<p style="color:var(--text-muted);text-align:center;max-width:450px">' +
            'Para editar documentos com o <strong>Google Docs</strong>, <strong>Sheets</strong> ou <strong>Slides</strong>, ' +
            'autorize o acesso ao Google Drive. O arquivo ser\u00e1 enviado temporariamente e apagado ap\u00f3s a edi\u00e7\u00e3o.</p>' +
            '<button class="btn btn-primary btn-lg" onclick="window.location.href=\'' + authUrl.replace(/'/g,"\\'") + '\'">' +
            '<i class="bi bi-box-arrow-in-right"></i> Autorizar com Google</button>' +
            '<p style="font-size:11px;color:var(--text-muted)">Voc\u00ea ser\u00e1 redirecionado para a tela de consentimento do Google.</p>' +
            '</div>';
    },

    /**
     * Open file in Google Editor via IFRAME (embedded in TCloud)
     */
    _initGoogleEditor: async function(container, fileId, fileName) {
        var self = this;

        this._status('');
        container.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:center;height:100%">' +
            '<i class="bi bi-arrow-repeat spin" style="font-size:32px;color:var(--accent)"></i></div>';

        var result = await CV.api('gw_open', { id: fileId });

        if (!result.success) {
            if (result.needs_auth && result.auth_url) {
                window.location.href = result.auth_url;
                return;
            }
            container.innerHTML =
                '<div class="empty-state"><i class="bi bi-exclamation-circle"></i>' +
                '<h3>Erro</h3><p>' + (result.message || 'Erro desconhecido') + '</p></div>';
            return;
        }

        this._gwGoogleFileId = result.google_file_id;
        this._status('');

        // Just the iframe — no info bar, no messages
        container.innerHTML =
            '<iframe id="gwEditorFrame" src="' + result.editor_url + '" ' +
                'style="flex:1;width:100%;height:100%;border:none" ' +
                'allow="clipboard-read; clipboard-write"></iframe>';
    },

    /**
     * Auto-save from Google Drive + cleanup + close (called on editor close)
     */
    _gwCloseAndSave: async function() {
        var fileId = this._s.fileId;
        if (!fileId || !this._gwGoogleFileId || this._gwSaving) return;
        this._gwSaving = true;

        // Overlay on top of iframe (keep iframe alive so Google finishes saving)
        var body = document.getElementById('docEditorBody');
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:absolute;inset:0;z-index:999;display:flex;align-items:center;justify-content:center;background:var(--bg-primary)';
        overlay.innerHTML = '<i class="bi bi-arrow-repeat spin" style="font-size:32px;color:var(--accent)"></i>';
        body.style.position = 'relative';
        body.appendChild(overlay);

        // Wait 3s for Google to finish auto-saving the latest edits
        await new Promise(function(r){ setTimeout(r, 3000); });

        // Export from Google Drive → save to TCloud
        var result = await CV.api('gw_save', { id: fileId });
        if (result.success) {
            CV.toast('Documento salvo! v' + result.version, 'success');
        } else {
            CV.toast(result.message || 'Erro ao salvar.', 'error');
        }

        // Delete temp file from Google Drive
        await CV.api('gw_discard', { id: fileId });

        // Close
        this._stopHeartbeat();
        CV.api('unlock_file',{id:fileId}).catch(function(){});
        this._gwGoogleFileId = null;
        this._gwSaving = false;
        this._stopAutosave();
        if(this._pagTimer){clearTimeout(this._pagTimer);this._pagTimer=null;}
        this._pagBusy=false;this._pagLastCount=0;
        if(this._s.onlyoffice){try{this._s.onlyoffice.destroyEditor();}catch(e){}this._s.onlyoffice=null;}
        try{tinymce?.get('mce-ed')?.remove();}catch(e){}
        this._s.tinymce=null;
        if(this._s.monacoEditor){this._s.monacoEditor.dispose();this._s.monacoEditor=null;}
        this._s.spreadsheet=null;this._s.isDirty=false;this._s.isFullscreen=false;this._s.fileId=null;
        this._pdf.doc=null;this._ruler.dragging=null;
        document.getElementById('docEditorOverlay').classList.remove('show','doc-fullscreen');
        document.getElementById('docEditorBody').innerHTML='';
        CV.refresh();
    },

    /**
     * Detect doc type from editor URL
     */
    _gwDocType: function(url) {
        if (url.indexOf('/document/') !== -1) return 'Docs';
        if (url.indexOf('/spreadsheets/') !== -1) return 'Sheets';
        if (url.indexOf('/presentation/') !== -1) return 'Slides';
        return 'Drive';
    },


    // ═══════════════════════════════════════════════════════
    // DOCUMENT EDITOR (TinyMCE 6)
    // ═══════════════════════════════════════════════════════

    _initDoc: async function(container, html, canEdit) {
        container.innerHTML='<div id="mce-wrap" style="flex:1;display:flex;flex-direction:column;overflow:hidden"><textarea id="mce-ed"></textarea></div>';
        document.getElementById('mce-ed').value = html;

        if (typeof tinymce==='undefined') await this._loadJs('https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js');
        try { tinymce.get('mce-ed')?.remove(); } catch(e){}

        var dk=document.documentElement.getAttribute('data-theme')==='dark';
        var self=this, M=this._m;

        // Theme colors
        var bgP=dk?'#1e2229':'#fff',       bgA=dk?'#0d0f12':'#dadce0';
        var txt=dk?'#e8eaed':'#1a1d24',    brd=dk?'#2a2d35':'#c8ccd4';
        var tbr=dk?'#444':'#bbb',           shd=dk?'0.5':'0.12';
        var thB=dk?'#252930':'#f5f5f5',     bqC=dk?'#444':'#ddd';
        var bqT=dk?'#999':'#666',           lnk=dk?'#6d8cff':'#1a73e8';

        // Page break height: bottom-margin + gap + top-margin
        var pbH = M.B + 28 + M.T; // 76+28+113 = 217
        var GAP = 28;
        // Content height per page (usable area)
        var CONTENT_H = M.PH - M.T - M.B; // 1123-113-76 = 934

        // CSS for iframe content
        var css = [
            'html{background:'+bgA+';height:auto}',
            // Body: single white sheet, grows with content
            'body{font-family:Calibri,Arial,sans-serif;font-size:12pt;line-height:1.5;color:'+txt+';',
            'background:'+bgP+';width:'+M.PW+'px;min-height:'+M.PH+'px;margin:20px auto;',
            'padding:'+M.T+'px '+M.R+'px '+M.B+'px '+M.L+'px;box-sizing:border-box;',
            'border:1px solid '+brd+';',
            'box-shadow:0 1px 3px rgba(0,0,0,'+shd+'),0 4px 14px rgba(0,0,0,'+shd+')}',
            // Metadata (hidden)
            '#cv-meta{display:none!important;height:0;overflow:hidden;font-size:0;line-height:0}',
            // Typography
            'p{margin:0 0 6pt}',
            'h1{font-size:18pt;margin:14pt 0 6pt}h2{font-size:16pt;margin:12pt 0 5pt}h3{font-size:14pt;margin:10pt 0 4pt}',
            'table{border-collapse:collapse;width:100%;margin:8pt 0}',
            'td,th{border:1px solid '+tbr+';padding:6px 8px}th{font-weight:700;background:'+thB+'}',
            'img{max-width:100%;height:auto}',
            'hr{border:0;border-top:1px solid '+tbr+';margin:12pt 0}',
            'blockquote{margin:8pt 0 8pt 20pt;padding-left:12pt;border-left:3px solid '+bqC+';color:'+bqT+'}',
            'ul,ol{margin:4pt 0 4pt 20pt}',
            'a{color:'+lnk+'}',
            // AUTO page break (inserted by pagination engine) — sits IN document flow
            '.cv-apb{display:block;height:'+pbH+'px;',
            'margin:0 -'+M.R+'px 0 -'+M.L+'px;width:calc(100% + '+M.L+'px + '+M.R+'px);',
            'background:linear-gradient(to bottom,',
            bgP+' 0,'+bgP+' '+M.B+'px,',
            bgA+' '+M.B+'px,'+bgA+' '+(M.B+GAP)+'px,',
            bgP+' '+(M.B+GAP)+'px,'+bgP+' '+pbH+'px);',
            'position:relative;user-select:none;pointer-events:none;clear:both;font-size:0;line-height:0;overflow:hidden}',
            // Shadow inside the gap
            '.cv-apb::before{content:"";position:absolute;left:0;right:0;top:'+M.B+'px;height:'+GAP+'px;',
            'box-shadow:inset 0 2px 5px rgba(0,0,0,'+(dk?'0.4':'0.08')+'),inset 0 -2px 5px rgba(0,0,0,'+(dk?'0.4':'0.08')+')}',
            // Border lines at page edges
            '.cv-apb::after{content:"";position:absolute;left:0;right:0;top:'+M.B+'px;height:'+GAP+'px;',
            'border-top:1px solid '+(dk?'#333':'#ccc')+';border-bottom:1px solid '+(dk?'#333':'#ccc')+'}',
            // MANUAL page break (user-inserted via toolbar) — same visual
            '.cv-pb{display:block;height:'+pbH+'px;',
            'margin:0 -'+M.R+'px 0 -'+M.L+'px;width:calc(100% + '+M.L+'px + '+M.R+'px);',
            'background:linear-gradient(to bottom,'+bgP+' 0,'+bgP+' '+M.B+'px,'+bgA+' '+M.B+'px,'+bgA+' '+(M.B+GAP)+'px,'+bgP+' '+(M.B+GAP)+'px,'+bgP+' '+pbH+'px);',
            'position:relative;user-select:none;pointer-events:none;clear:both;font-size:0;line-height:0;overflow:hidden}',
            '.cv-pb::before{content:"";position:absolute;left:0;right:0;top:'+M.B+'px;height:'+GAP+'px;',
            'box-shadow:inset 0 2px 5px rgba(0,0,0,'+(dk?'0.4':'0.08')+'),inset 0 -2px 5px rgba(0,0,0,'+(dk?'0.4':'0.08')+')}',
            '.cv-pb::after{content:"";position:absolute;left:0;right:0;top:'+M.B+'px;height:'+GAP+'px;',
            'border-top:1px solid '+(dk?'#333':'#ccc')+';border-bottom:1px solid '+(dk?'#333':'#ccc')+'}',
            // Header/footer regions
            '.cv-hdr{margin:-'+M.T+'px -'+M.R+'px 16px -'+M.L+'px;padding:24px '+M.R+'px 8px '+M.L+'px;',
            'border-bottom:1px dashed '+(dk?'#333':'#ddd')+';color:'+(dk?'#666':'#999')+';font-size:10pt}',
            '.cv-ftr{margin:16px -'+M.R+'px -'+M.B+'px -'+M.L+'px;padding:8px '+M.R+'px 20px '+M.L+'px;',
            'border-top:1px dashed '+(dk?'#333':'#ddd')+';color:'+(dk?'#666':'#999')+';font-size:10pt}',
            '@media(max-width:860px){body{width:auto;margin:8px;padding:24px;min-height:auto}.cv-apb,.cv-pb,.cv-hdr,.cv-ftr{display:none}}',
        ].join('\n');

        // Store content height for pagination engine
        self._pageContentH = CONTENT_H;

        return new Promise(function(resolve, reject) {
            tinymce.init({
                selector:'#mce-ed',
                base_url:'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2',
                suffix:'.min',
                language:'pt_BR',
                language_url:'public/js/langs/pt_BR.js',
                height:'100%', resize:false,
                promotion:false, branding:false,
                browser_spellcheck:true, contextmenu:false,
                iframe_attrs:{spellcheck:'true'},
                skin:dk?'oxide-dark':'oxide',
                content_css:false, content_style:css,
                noneditable_class:'cv-pb',
                // cv-apb elements use contenteditable=false + data-mce-bogus=all
                readonly:!canEdit,

                menubar:canEdit?'file edit view insert format table':false,
                plugins:'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime table wordcount pagebreak',

                toolbar:canEdit?[
                    'undo redo','blocks fontfamily fontsize',
                    'bold italic underline strikethrough','forecolor backcolor','removeformat',
                    'alignleft aligncenter alignright alignjustify','lineheight',
                    'bullist numlist outdent indent','blockquote',
                    'table image link charmap','insertdatetime cvpagebreak cvheaderfooter',
                    'searchreplace code fullscreen'
                ].join(' | '):'searchreplace | fullscreen',

                toolbar_sticky:true, toolbar_mode:'wrap',

                font_family_formats:'Calibri=Calibri,sans-serif;Arial=Arial,Helvetica,sans-serif;Times New Roman=Times New Roman,serif;Courier New=Courier New,monospace;Georgia=Georgia,serif;Verdana=Verdana,sans-serif;Tahoma=Tahoma,sans-serif',
                font_size_formats:'8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 20pt 24pt 28pt 32pt 36pt 48pt 72pt',
                line_height_formats:'1 1.15 1.5 2 2.5 3',

                images_upload_handler:function(blobInfo){
                    return new Promise(function(ok,fail){
                        var r=new FileReader();
                        r.onload=function(){ok(r.result);};
                        r.onerror=function(){fail('Erro');};
                        r.readAsDataURL(blobInfo.blob());
                    });
                },
                automatic_uploads:true,
                file_picker_types:'image',
                insertdatetime_formats:['%d/%m/%Y','%H:%M','%d/%m/%Y %H:%M'],

                setup:function(editor) {
                    self._s.tinymce = editor;

                    editor.on('change input keyup',function(){
                        self._s.isDirty=true;
                        self._status('Altera\u00e7\u00f5es n\u00e3o salvas');
                    });

                    editor.addShortcut('ctrl+s','Salvar',function(){self.save();});
                    editor.addShortcut('ctrl+p','PDF',function(){self.exportPdf();});

                    // Custom: Page Break button
                    editor.ui.registry.addButton('cvpagebreak',{
                        icon:'page-break',
                        tooltip:'Quebra de p\u00e1gina',
                        onAction:function(){
                            editor.insertContent('<div class="cv-pb" contenteditable="false">\u200B</div><p></p>');
                        }
                    });

                    // Custom: Header/Footer menu
                    editor.ui.registry.addMenuButton('cvheaderfooter',{
                        icon:'document-properties',
                        tooltip:'Cabe\u00e7alho e Rodap\u00e9',
                        fetch:function(cb){
                            cb([
                                {type:'menuitem',text:'Inserir Cabe\u00e7alho',onAction:function(){
                                    var b=editor.getBody();
                                    if(!b.querySelector('.cv-hdr')){
                                        var d=editor.dom.create('div',{'class':'cv-hdr'},
                                            '<p style="text-align:center;color:inherit">Cabe\u00e7alho \u2014 clique para editar</p>');
                                        b.insertBefore(d,b.firstChild);
                                    }
                                }},
                                {type:'menuitem',text:'Inserir Rodap\u00e9',onAction:function(){
                                    var b=editor.getBody();
                                    if(!b.querySelector('.cv-ftr')){
                                        editor.dom.add(b,'div',{'class':'cv-ftr'},
                                            '<p style="text-align:center;color:inherit">Rodap\u00e9 \u2014 clique para editar</p>');
                                    }
                                }},
                                {type:'menuitem',text:'Remover Cabe\u00e7alho',onAction:function(){
                                    var h=editor.getBody().querySelector('.cv-hdr');if(h)h.remove();
                                }},
                                {type:'menuitem',text:'Remover Rodap\u00e9',onAction:function(){
                                    var f=editor.getBody().querySelector('.cv-ftr');if(f)f.remove();
                                }}
                            ]);
                        }
                    });

                    // Prevent cursor inside page breaks (auto or manual)
                    editor.on('NodeChange',function(e){
                        var n=e.element;
                        if(n&&n.classList&&(n.classList.contains('cv-pb')||n.classList.contains('cv-apb'))){
                            var nx=n.nextElementSibling;
                            if(nx) editor.selection.setCursorLocation(nx,0);
                        }
                    });
                },

                init_instance_callback:function(editor) {
                    // Style TinyMCE container
                    var tox=document.querySelector('#mce-wrap .tox-tinymce');
                    if(tox) tox.style.cssText='flex:1;border:none;border-radius:0;display:flex;flex-direction:column';

                    // Spellcheck
                    try {
                        if(editor.iframeElement) editor.iframeElement.spellcheck=true;
                        var d=editor.getDoc();
                        if(d){d.documentElement.lang='pt-BR';d.documentElement.spellcheck=true;}
                        var b=editor.getBody();
                        if(b){b.lang='pt-BR';b.spellcheck=true;}
                    } catch(e){}

                    // Ruler
                    self._buildRuler(editor);

                    // Restore ruler settings from saved metadata
                    self._restoreRulerMeta(editor);

                    // Auto-pagination engine
                    self._startPagination(editor);

                    // Autosave
                    self._startAutosave();

                    resolve(editor);
                }
            }).catch(reject);
        });
    },


    // ═══════════════════════════════════════════════════════
    // RULER
    // ═══════════════════════════════════════════════════════

    _buildRuler: function(editor) {
        var header = document.querySelector('#mce-wrap .tox-editor-header');
        if (!header) return;

        var M=this._m, R=this._ruler, self=this;
        R.ml=M.L; R.mr=M.R; R.fi=0;

        var dk=document.documentElement.getAttribute('data-theme')==='dark';
        var bgR=dk?'#1a1d24':'#f0f2f5', brC=dk?'#2a2d35':'#d0d4dc';
        var mzC=dk?'rgba(255,255,255,0.05)':'rgba(0,0,0,0.06)';
        var tkC=dk?'#555':'#aaa', numC=dk?'#666':'#999', acC=dk?'#6d8cff':'#4f6ef7';

        // Create ruler element
        var el=document.createElement('div');
        el.id='cv-ruler';
        el.style.cssText='height:26px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:'+bgR+';border-bottom:1px solid '+brC+';user-select:none;overflow:hidden;position:relative;z-index:5';

        var trk=document.createElement('div');
        trk.id='rv-trk';
        trk.style.cssText='position:relative;width:'+M.PW+'px;height:100%';
        el.appendChild(trk);

        // Margin zones
        var zL=document.createElement('div');
        zL.id='rv-zl';zL.style.cssText='position:absolute;top:0;left:0;height:100%;width:'+R.ml+'px;background:'+mzC;
        trk.appendChild(zL);
        var zR=document.createElement('div');
        zR.id='rv-zr';zR.style.cssText='position:absolute;top:0;right:0;height:100%;width:'+R.mr+'px;background:'+mzC;
        trk.appendChild(zR);

        // Tick marks
        for(var cm=0;cm<=21;cm++){
            var x=Math.round(cm*37.8);
            var t=document.createElement('div');
            t.style.cssText='position:absolute;bottom:0;left:'+x+'px;width:1px;height:10px;background:'+tkC;
            trk.appendChild(t);
            var lb=document.createElement('span');
            lb.textContent=cm;
            lb.style.cssText='position:absolute;bottom:11px;left:'+x+'px;transform:translateX(-50%);font-size:8px;color:'+numC+';font-family:monospace;pointer-events:none';
            trk.appendChild(lb);
            if(cm<21){
                var h=document.createElement('div');
                h.style.cssText='position:absolute;bottom:0;left:'+Math.round((cm+0.5)*37.8)+'px;width:1px;height:6px;background:'+tkC+';opacity:0.5';
                trk.appendChild(h);
            }
        }

        // Handles
        var hfi=this._mkH(trk,'rv-fi',R.ml,'top:1px',acC,'0,0 10,0 5,7','Recuo 1\u00aa linha');
        var hml=this._mkH(trk,'rv-ml',R.ml,'bottom:1px',acC,'5,0 10,7 0,7','Margem esquerda');
        var hmr=this._mkH(trk,'rv-mr',M.PW-R.mr,'bottom:1px',acC,'5,0 10,7 0,7','Margem direita');

        // Tooltip
        var tip=document.createElement('div');
        tip.id='rv-tip';
        tip.style.cssText='position:absolute;top:-20px;transform:translateX(-50%);padding:1px 6px;font-size:9px;font-family:monospace;background:'+(dk?'#252930':'#fff')+';color:'+(dk?'#ccc':'#333')+';border:1px solid '+brC+';border-radius:3px;display:none;pointer-events:none;z-index:10;white-space:nowrap';
        trk.appendChild(tip);

        // Insert after TinyMCE header (below menus + toolbars)
        try { header.insertAdjacentElement('afterend',el); }
        catch(e) { console.warn('Ruler placement fallback'); }

        // Drag logic
        function startDrag(e,type){
            e.preventDefault();e.stopPropagation();
            R.dragging=type;
            document.body.style.cursor='col-resize';
            document.body.style.userSelect='none';
            tip.style.display='block';
        }
        hfi.addEventListener('mousedown',function(e){startDrag(e,'fi');});
        hml.addEventListener('mousedown',function(e){startDrag(e,'ml');});
        hmr.addEventListener('mousedown',function(e){startDrag(e,'mr');});

        document.addEventListener('mousemove',function(e){
            if(!R.dragging) return;
            var rect=trk.getBoundingClientRect();
            var x=Math.max(0,Math.min(M.PW,e.clientX-rect.left));
            tip.textContent=(x/37.8).toFixed(1)+' cm';
            tip.style.left=x+'px';
            var body=editor.getBody();

            if(R.dragging==='fi'){
                R.fi=Math.max(-200,Math.min(400,Math.round(x-R.ml)));
                hfi.style.left=(R.ml+R.fi-5)+'px';
                self._setIndent(editor);
            } else if(R.dragging==='ml'){
                R.ml=Math.max(20,Math.min(M.PW-R.mr-100,Math.round(x)));
                hml.style.left=(R.ml-5)+'px';
                hfi.style.left=(R.ml+R.fi-5)+'px';
                zL.style.width=R.ml+'px';
                if(body)body.style.paddingLeft=R.ml+'px';
            } else if(R.dragging==='mr'){
                R.mr=Math.max(20,Math.min(M.PW-R.ml-100,Math.round(M.PW-x)));
                hmr.style.left=(M.PW-R.mr-5)+'px';
                zR.style.width=R.mr+'px';
                if(body)body.style.paddingRight=R.mr+'px';
            }
        });

        document.addEventListener('mouseup',function(){
            if(!R.dragging) return;
            R.dragging=null;
            document.body.style.cursor='';document.body.style.userSelect='';
            tip.style.display='none';
            self._setIndent(editor);
            self._s.isDirty=true;
            self._status('Altera\u00e7\u00f5es n\u00e3o salvas');
        });
    },

    _mkH: function(par,id,left,posCSS,color,pts,title) {
        var h=document.createElement('div');
        h.id=id; h.title=title;
        h.style.cssText='position:absolute;'+posCSS+';left:'+(left-5)+'px;width:10px;height:10px;cursor:col-resize;z-index:6;display:flex;align-items:center;justify-content:center';
        h.innerHTML='<svg width="10" height="7" viewBox="0 0 10 7"><polygon points="'+pts+'" fill="'+color+'"/></svg>';
        h.onmouseenter=function(){h.style.filter='brightness(1.4) drop-shadow(0 0 3px '+color+')';};
        h.onmouseleave=function(){h.style.filter='';};
        par.appendChild(h);
        return h;
    },

    _setIndent: function(editor) {
        var doc=editor.getDoc();if(!doc)return;
        var st=doc.getElementById('cv-indent');
        if(!st){st=doc.createElement('style');st.id='cv-indent';doc.head.appendChild(st);}
        st.textContent='p{text-indent:'+this._ruler.fi+'px}';
    },

    /**
     * Restore ruler settings from hidden metadata in document content
     */
    _restoreRulerMeta: function(editor) {
        var body = editor.getBody();
        if (!body) return;

        var meta = body.querySelector('#cv-meta');
        if (!meta) return;

        var R = this._ruler, M = this._m;
        var ml = parseInt(meta.getAttribute('data-ml'))||M.L;
        var mr = parseInt(meta.getAttribute('data-mr'))||M.R;
        var fi = parseInt(meta.getAttribute('data-fi'))||0;

        R.ml = ml; R.mr = mr; R.fi = fi;

        // Apply to body padding
        body.style.paddingLeft = ml+'px';
        body.style.paddingRight = mr+'px';

        // Apply text-indent
        this._setIndent(editor);

        // Update ruler handles
        var hfi=document.getElementById('rv-fi');
        var hml=document.getElementById('rv-ml');
        var hmr=document.getElementById('rv-mr');
        var zL=document.getElementById('rv-zl');
        var zR=document.getElementById('rv-zr');

        if(hfi) hfi.style.left=(ml+fi-5)+'px';
        if(hml) hml.style.left=(ml-5)+'px';
        if(hmr) hmr.style.left=(M.PW-mr-5)+'px';
        if(zL) zL.style.width=ml+'px';
        if(zR) zR.style.width=mr+'px';

        // Remove meta from visible DOM (it stays hidden but we keep it clean)
        // Don't remove — it's display:none and we'll update it on save
    },

    /**
     * Inject ruler metadata into document content before saving
     */
    _injectRulerMeta: function(html) {
        var R = this._ruler;
        var metaTag = '<div id="cv-meta" data-ml="'+R.ml+'" data-mr="'+R.mr+'" data-fi="'+R.fi+'" style="display:none">\u200B</div>';

        // Remove existing meta
        html = html.replace(/<div id="cv-meta"[^>]*>[\s\S]*?<\/div>/gi, '');

        // Append at the end
        return html + metaTag;
    },


    // ═══════════════════════════════════════════════════════
    // AUTO-PAGINATION ENGINE
    // ═══════════════════════════════════════════════════════

    _pageContentH: 934, // set dynamically by _initDoc
    _pagTimer: null,
    _pagBusy: false,
    _pagLastCount: 0,

    _startPagination: function(editor) {
        var self = this;
        function schedule() {
            if (self._pagTimer) clearTimeout(self._pagTimer);
            self._pagTimer = setTimeout(function() { self._paginate(editor); }, 300);
        }
        // Only TinyMCE events — NO MutationObserver (avoids infinite loops)
        editor.on('input keyup SetContent Undo Redo paste', schedule);
        // Initial pagination after content loads
        setTimeout(function() { self._paginate(editor); }, 800);
    },

    _paginate: function(editor) {
        if (this._pagBusy) return;
        this._pagBusy = true;

        try {
            var body = editor.getBody();
            if (!body) { this._pagBusy = false; return; }

            var CH = this._pageContentH; // usable height per page (934px)

            // Step 1: Remove ALL auto page breaks (keep manual .cv-pb intact)
            var old = body.querySelectorAll('.cv-apb');
            for (var i = old.length - 1; i >= 0; i--) old[i].parentNode.removeChild(old[i]);

            // Step 2: Collect top-level content elements
            var items = [];
            for (var i = 0; i < body.children.length; i++) {
                var el = body.children[i];
                if (el.id === 'cv-meta') continue;
                if (el.classList.contains('cv-hdr') || el.classList.contains('cv-ftr')) continue;
                var isMB = el.classList.contains('cv-pb');
                items.push({ el: el, manual: isMB });
            }

            // Step 3: Walk elements, track height per page, find overflow points
            var accumH = 0, pageNum = 1, toInsert = [];

            for (var i = 0; i < items.length; i++) {
                if (items[i].manual) { accumH = 0; pageNum++; continue; }
                var h = items[i].el.offsetHeight || 0;
                if (accumH + h > CH && accumH > 0) {
                    toInsert.push(items[i].el);
                    accumH = h;
                    pageNum++;
                } else {
                    accumH += h;
                }
            }

            // Step 4: Insert auto page breaks (reverse order to keep indices stable)
            var doc = editor.getDoc();
            for (var i = toInsert.length - 1; i >= 0; i--) {
                var apb = doc.createElement('div');
                apb.className = 'cv-apb';
                apb.setAttribute('contenteditable', 'false');
                apb.setAttribute('data-mce-bogus', 'all');
                apb.innerHTML = '\u200B';
                body.insertBefore(apb, toInsert[i]);
            }

            // Step 5: Update page count in status bar
            var total = pageNum;
            if (total !== this._pagLastCount) {
                this._pagLastCount = total;
                var st = document.getElementById('docEditorStatus');
                if (st) {
                    var txt = st.textContent.replace(/\s*·\s*\d+ página[s]?/g, '');
                    st.textContent = txt + ' · ' + total + (total > 1 ? ' páginas' : ' página');
                }
            }

        } catch(e) { console.error('Pagination:', e); }

        this._pagBusy = false;
    },


    // ═══════════════════════════════════════════════════════
    // AUTOSAVE
    // ═══════════════════════════════════════════════════════

    _startAutosave: function(){
        this._stopAutosave(); var self=this;
        this._s.autosaveTimer=setInterval(function(){
            if(self._s.isDirty&&self._s.fileId&&self._s.canEdit)self._autoSave();
        },60000);
    },
    _stopAutosave: function(){if(this._s.autosaveTimer){clearInterval(this._s.autosaveTimer);this._s.autosaveTimer=null;}},

    _autoSave: async function(){
        var c=this._getContent();if(c===null)return;
        try{
            var r=await CV.api('doc_save',{id:this._s.fileId,content:c,content_type:this._s.editorType==='spreadsheet'?'json':'html'});
            if(r.success){this._s.isDirty=false;this._status('v'+r.version+' \u2014 '+CV.formatSize(r.size)+' \u2014 Salvo automaticamente');}
        }catch(e){}
    },


    // ═══════════════════════════════════════════════════════
    // SAVE / SAVE AS / EXPORT
    // ═══════════════════════════════════════════════════════

    save: async function(){
        if(!this._s.fileId||!this._s.canEdit)return;
        var c=this._getContent();if(c===null)return;
        this._status('Salvando...');
        var r=await CV.api('doc_save',{id:this._s.fileId,content:c,content_type:this._s.editorType==='spreadsheet'?'json':'html'});
        if(r.success){this._s.isDirty=false;this._status('v'+r.version+' \u2014 '+CV.formatSize(r.size));CV.toast('Documento salvo!','success');}
        else{this._status('Erro ao salvar');CV.toast(r.message||'Erro.','error');}
    },

    saveAs: async function(){
        var def=this._s.fileName.replace(/\.[^.]+$/,'')+' - C\u00f3pia.'+this._s.fileExt;
        var name=await CV.prompt('Salvar como',def);if(!name)return;
        var c=this._getContent();if(c===null)return;
        var r=await CV.api('doc_save_as',{id:this._s.fileId,name:name,content:c,content_type:this._s.editorType==='spreadsheet'?'json':'html'});
        if(r.success){CV.toast('Salvo como "'+(r.name||name)+'"!','success');if(r.id){this._s.fileId=r.id;this._s.fileName=r.name||name;this._s.isDirty=false;document.getElementById('docEditorTitle').textContent=this._s.fileName;}}
        else CV.toast(r.message||'Erro.','error');
    },

    exportPdf: function(){
        if(!this._s.tinymce)return;
        var html=this._s.tinymce.getContent();
        // Strip auto page breaks and metadata
        html=html.replace(/<div class="cv-apb"[^>]*>[\s\S]*?<\/div>/gi,'');
        html=html.replace(/<div id="cv-meta"[^>]*>[\s\S]*?<\/div>/gi,'');
        var w=window.open('','_blank','width=900,height=700');
        if(!w){CV.toast('Permita pop-ups.','warning');return;}
        w.document.write('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>'+this._s.fileName+'</title>');
        w.document.write('<style>@page{size:A4;margin:3cm 2cm 2cm 3cm}body{font-family:Calibri,Arial,sans-serif;font-size:12pt;line-height:1.5;margin:0;padding:0}');
        w.document.write('h1{font-size:18pt}h2{font-size:16pt}h3{font-size:14pt}');
        w.document.write('table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:6px 8px}th{background:#f0f0f0}');
        w.document.write('img{max-width:100%}.cv-pb{page-break-after:always;height:0;overflow:hidden;border:0}');
        w.document.write('.cv-hdr{border-bottom:1px solid #ccc;padding-bottom:8px;margin-bottom:16px;color:#666;font-size:10pt}');
        w.document.write('.cv-ftr{border-top:1px solid #ccc;padding-top:8px;margin-top:16px;color:#666;font-size:10pt}');
        w.document.write('</style></head><body>');
        w.document.write(html);
        w.document.write('</body></html>');
        w.document.close();w.focus();
        setTimeout(function(){w.print();},500);
    },

    _getContent: function(){
        switch(this._s.editorType){
            case 'document':
                if(!this._s.tinymce) return null;
                var html = this._s.tinymce.getContent();
                // Remove AUTO page breaks (they get re-created on open)
                html = html.replace(/<div class="cv-apb"[^>]*>[\s\S]*?<\/div>/gi, '');
                // Keep manual .cv-pb breaks — they're user-inserted
                // Inject ruler settings as hidden metadata
                html = this._injectRulerMeta(html);
                return html;
            case 'spreadsheet': return this._s.spreadsheet?JSON.stringify(this._s.spreadsheet.getData()):'[]';
            case 'code': return this._s.monacoEditor?this._s.monacoEditor.getValue():null;
            default: return null;
        }
    },


    // ═══════════════════════════════════════════════════════
    // VERSIONS
    // ═══════════════════════════════════════════════════════

    showVersions: async function(fileId){
        var fid = fileId || this._s.fileId;
        if(!fid){CV.toast('Nenhum arquivo selecionado.','warning');return;}
        var d=await CV.api('doc_versions',{id:fid});
        if(!d.success||!d.versions||!d.versions.length){CV.toast('Nenhuma vers\u00e3o anterior.','info');return;}
        var h='<div style="max-height:50vh;overflow-y:auto;text-align:left">';
        d.versions.forEach(function(v){
            h+='<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--border)">';
            h+='<div><strong>v'+v.version_number+'</strong><br><small style="color:var(--text-muted)">'+(v.user_name||'')+' \u2014 '+CV.formatSize(v.size)+' \u2014 '+v.created_at+'</small></div>';
            h+='<button class="btn btn-secondary btn-sm" onclick="DocEditor.restoreVersion('+v.id+','+fid+')"><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button>';
            h+='</div>';
        });
        h+='</div>';
        Swal.fire({
            title:'Hist\u00f3rico de Vers\u00f5es',
            html:h,
            showConfirmButton:false,
            showCloseButton:true,
            width:550,
            background:'var(--bg-modal)',
            color:'var(--text-primary)',
            customClass:{popup:'swal-custom-popup'}
        });
    },

    restoreVersion: async function(vid,fileId){
        var fid=fileId||this._s.fileId;
        Swal.close();
        var ok=await CV.confirm('Restaurar vers\u00e3o','A vers\u00e3o atual ser\u00e1 salva como backup.','Restaurar');
        if(!ok)return;
        var r=await CV.api('doc_restore_version',{id:fid,version_id:vid});
        CV.toast(r.message,r.success?'success':'error');
        if(r.success){
            if(this._s.fileId==fid){this._forceClose();setTimeout(function(){DocEditor.open(fid);},300);}
            else{CV.refresh();}
        }
    },


    // ═══════════════════════════════════════════════════════
    // FULLSCREEN / CLOSE
    // ═══════════════════════════════════════════════════════

    toggleFullscreen: function(){
        this._s.isFullscreen=!this._s.isFullscreen;
        document.getElementById('docEditorOverlay').classList.toggle('doc-fullscreen',this._s.isFullscreen);
        var b=document.getElementById('docEditorFullscreenBtn');
        if(b)b.innerHTML=this._s.isFullscreen?'<i class="bi bi-fullscreen-exit"></i>':'<i class="bi bi-arrows-fullscreen"></i>';
        setTimeout(function(){if(DocEditor._s.monacoEditor)DocEditor._s.monacoEditor.layout();window.dispatchEvent(new Event('resize'));},50);
    },

    close: async function(){
        // ONLYOFFICE handles saving via callbacks — just close
        if(this._s.onlyoffice) {
            this._forceClose();
            return;
        }
        // Google Workspace — auto-save and bring back on close
        if(this._gwGoogleFileId) {
            this._gwCloseAndSave();
            return;
        }
        // TinyMCE/Monaco — check for unsaved changes
        if(this._s.isDirty){
            var r=await Swal.fire({
                title:'Salvar altera\u00e7\u00f5es?',text:'O documento foi modificado.',icon:'question',
                showDenyButton:true,showCancelButton:true,
                confirmButtonText:'Salvar',denyButtonText:'N\u00e3o salvar',cancelButtonText:'Cancelar',
                confirmButtonColor:'var(--accent)',denyButtonColor:'var(--danger)',
                background:'var(--bg-modal)',color:'var(--text-primary)',
                customClass:{popup:'swal-custom-popup',confirmButton:'swal-confirm-btn',denyButton:'swal-confirm-btn',cancelButton:'swal-cancel-btn'}
            });
            if(r.isConfirmed){await this.save();this._forceClose();}
            else if(r.isDenied)this._forceClose();
            return;
        }
        this._forceClose();
    },

    _forceClose: function(){
        this._stopHeartbeat();
        if(this._s.fileId) CV.api('unlock_file',{id:this._s.fileId}).catch(function(){});
        this._stopAutosave();
        if(this._pagTimer){clearTimeout(this._pagTimer);this._pagTimer=null;}
        this._pagBusy=false;this._pagLastCount=0;
        // Cleanup Google Drive temp file (discard only, no save)
        if(this._gwGoogleFileId&&this._s.fileId){
            CV.api('gw_discard',{id:this._s.fileId}).catch(function(){});
            this._gwGoogleFileId=null;
        }
        this._gwSaving=false;
        // Destroy ONLYOFFICE editor
        if(this._s.onlyoffice){try{this._s.onlyoffice.destroyEditor();}catch(e){}this._s.onlyoffice=null;}
        // Destroy TinyMCE
        try{tinymce?.get('mce-ed')?.remove();}catch(e){}
        this._s.tinymce=null;
        if(this._s.monacoEditor){this._s.monacoEditor.dispose();this._s.monacoEditor=null;}
        this._s.spreadsheet=null;this._s.isDirty=false;this._s.isFullscreen=false;this._s.fileId=null;
        this._pdf.doc=null;this._ruler.dragging=null;
        document.getElementById('docEditorOverlay').classList.remove('show','doc-fullscreen');
        document.getElementById('docEditorBody').innerHTML='';
        CV.refresh();
    },


    // ═══════════════════════════════════════════════════════
    // SPREADSHEET (x-spreadsheet)
    // ═══════════════════════════════════════════════════════

    _initSheet: async function(container,sheetData,canEdit){
        container.innerHTML='<div id="xs-box" style="flex:1;overflow:hidden"></div>';
        if(typeof x_spreadsheet==='undefined'){
            await this._loadCss('https://unpkg.com/x-data-spreadsheet@1.1.9/dist/xspreadsheet.css');
            await this._loadJs('https://unpkg.com/x-data-spreadsheet@1.1.9/dist/xspreadsheet.js');
        }
        var data=Array.isArray(sheetData)?sheetData:[sheetData||{name:'Planilha1',rows:{}}];
        var xsD=data.map(function(s){
            var rows={};if(s.rows)Object.keys(s.rows).forEach(function(ri){
                var r=s.rows[ri];if(r.cells){var cells={};Object.keys(r.cells).forEach(function(ci){cells[ci]={text:r.cells[ci].text||''};});rows[ri]={cells:cells};}
            });return{name:s.name||'Planilha1',rows:rows};
        });
        var el=document.getElementById('xs-box');
        var dk=document.documentElement.getAttribute('data-theme')==='dark';
        this._s.spreadsheet=x_spreadsheet(el,{
            mode:canEdit?'edit':'read',showToolbar:canEdit,showGrid:true,showContextmenu:canEdit,showBottomBar:true,
            view:{height:function(){return el.clientHeight;},width:function(){return el.clientWidth;}},
            row:{len:200,height:25},col:{len:26,width:120,indexWidth:60,minWidth:60},
            style:{bgcolor:dk?'#1a1d24':'#fff',color:dk?'#e8eaed':'#1a1d24',font:{name:'Calibri',size:11}}
        });
        this._s.spreadsheet.loadData(xsD);
        this._s.spreadsheet.change(function(){DocEditor._s.isDirty=true;DocEditor._status('Altera\u00e7\u00f5es n\u00e3o salvas');});
    },


    // ═══════════════════════════════════════════════════════
    // PDF VIEWER (pdf.js — continuous scroll, all pages)
    // ═══════════════════════════════════════════════════════

    _initPdfViewer: function(container,url){
        var fid=this._s.fileId;
        container.innerHTML=
            '<div style="flex:1;display:flex;flex-direction:column;overflow:hidden">'+
            '<div id="pdfToolbar" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border);background:var(--bg-tertiary);flex-shrink:0">'+
            '<span id="pdfI" style="font-size:13px;min-width:100px;color:var(--text-secondary)">Carregando...</span>'+
            '<span style="width:1px;height:20px;background:var(--border)"></span>'+
            '<button class="btn btn-ghost btn-sm" onclick="DocEditor.pdfZm(-0.25)"><i class="bi bi-zoom-out"></i></button>'+
            '<span id="pdfZ" style="font-size:12px;min-width:50px;text-align:center;color:var(--text-muted)">100%</span>'+
            '<button class="btn btn-ghost btn-sm" onclick="DocEditor.pdfZm(0.25)"><i class="bi bi-zoom-in"></i></button>'+
            '<button class="btn btn-ghost btn-sm" onclick="DocEditor.pdfFitWidth()" title="Ajustar largura"><i class="bi bi-arrows"></i></button>'+
            '<span style="flex:1"></span>'+
            '<button class="btn btn-secondary btn-sm" onclick="CV.downloadFile('+fid+')"><i class="bi bi-download"></i> Baixar</button>'+
            '</div>'+
            '<div id="pdfScr" style="flex:1;overflow:auto;padding:16px;background:#525659;display:flex;flex-direction:column;align-items:center;gap:12px">'+
            '</div></div>';
        this._pdf.sc=1.0;
        this._loadPdf(url);
    },

    _loadPdf: async function(url){
        if(typeof pdfjsLib==='undefined'){
            await this._loadJs('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
            pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
        try{
            this._pdf.doc=await pdfjsLib.getDocument(url).promise;
            this._pdf.tot=this._pdf.doc.numPages;
            var i=document.getElementById('pdfI');
            if(i)i.textContent=this._pdf.tot+' p\u00e1gina'+(this._pdf.tot>1?'s':'');

            // Auto fit-width on first load
            var scr=document.getElementById('pdfScr');
            if(scr){
                var firstPage=await this._pdf.doc.getPage(1);
                var vp=firstPage.getViewport({scale:1});
                var availW=scr.clientWidth-40;
                this._pdf.sc=Math.min(availW/vp.width,2.5);
                var z=document.getElementById('pdfZ');if(z)z.textContent=Math.round(this._pdf.sc*100)+'%';
            }

            this._renderAllPages();
        }catch(e){
            var el=document.getElementById('pdfScr');
            if(el)el.innerHTML='<div class="empty-state" style="color:#fff"><i class="bi bi-file-pdf"></i><h3>Erro ao abrir PDF</h3><p>'+(e.message||'')+'</p></div>';
        }
    },

    _renderAllPages: async function(){
        var scr=document.getElementById('pdfScr');
        if(!scr||!this._pdf.doc)return;
        scr.innerHTML='';
        for(var p=1;p<=this._pdf.tot;p++){
            var pg=await this._pdf.doc.getPage(p);
            var vp=pg.getViewport({scale:this._pdf.sc});
            var wrap=document.createElement('div');
            wrap.style.cssText='position:relative;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,0.3);border-radius:2px;flex-shrink:0';
            var c=document.createElement('canvas');
            c.width=vp.width;c.height=vp.height;
            c.style.cssText='display:block;width:'+vp.width+'px;height:'+vp.height+'px';
            wrap.appendChild(c);
            // Page number label
            var lbl=document.createElement('div');
            lbl.style.cssText='position:absolute;bottom:4px;right:8px;font-size:10px;color:#999;pointer-events:none';
            lbl.textContent=p+' / '+this._pdf.tot;
            wrap.appendChild(lbl);
            scr.appendChild(wrap);
            await pg.render({canvasContext:c.getContext('2d'),viewport:vp}).promise;
        }
    },

    pdfZm:function(d){
        this._pdf.sc=Math.max(0.5,Math.min(4,this._pdf.sc+d));
        var z=document.getElementById('pdfZ');if(z)z.textContent=Math.round(this._pdf.sc*100)+'%';
        this._renderAllPages();
    },
    pdfFitWidth:function(){
        var scr=document.getElementById('pdfScr');
        if(!scr||!this._pdf.doc)return;
        var self=this;
        this._pdf.doc.getPage(1).then(function(pg){
            var vp=pg.getViewport({scale:1});
            self._pdf.sc=Math.min((scr.clientWidth-40)/vp.width,3);
            var z=document.getElementById('pdfZ');if(z)z.textContent=Math.round(self._pdf.sc*100)+'%';
            self._renderAllPages();
        });
    },


    // ═══════════════════════════════════════════════════════
    // CODE EDITOR (Monaco)
    // ═══════════════════════════════════════════════════════

    _initCode: async function(container,content,lang,canEdit){
        container.innerHTML='<div id="mc-wrap" style="flex:1;overflow:hidden"></div>';
        if(typeof monaco==='undefined'){
            require.config({paths:{vs:'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs'}});
            await new Promise(function(r){require(['vs/editor/editor.main'],r);});
        }
        var dk=document.documentElement.getAttribute('data-theme')==='dark',self=this;
        this._s.monacoEditor=monaco.editor.create(document.getElementById('mc-wrap'),{
            value:content,language:lang||'plaintext',theme:dk?'vs-dark':'vs',
            fontSize:14,fontFamily:"'JetBrains Mono','Fira Code',monospace",
            minimap:{enabled:true},wordWrap:'on',automaticLayout:true,
            scrollBeyondLastLine:false,readOnly:!canEdit,padding:{top:12},
            lineNumbers:'on',bracketPairColorization:{enabled:true}
        });
        this._s.monacoEditor.addCommand(monaco.KeyMod.CtrlCmd|monaco.KeyCode.KeyS,function(){self.save();});
        this._s.monacoEditor.onDidChangeModelContent(function(){self._s.isDirty=true;self._status('Altera\u00e7\u00f5es n\u00e3o salvas');});
    },


    // ═══════════════════════════════════════════════════════
    // UTILS
    // ═══════════════════════════════════════════════════════

    _status:function(t){var e=document.getElementById('docEditorStatus');if(e)e.textContent=t;},

    _loadJs:function(src){
        return new Promise(function(ok,fail){
            if(document.querySelector('script[src="'+src+'"]')){ok();return;}
            var s=document.createElement('script');s.src=src;s.referrerPolicy='origin';
            s.onload=function(){setTimeout(ok,150);};s.onerror=function(){fail(new Error('Load: '+src));};
            document.head.appendChild(s);
        });
    },

    _loadCss:function(href){
        return new Promise(function(ok){
            if(document.querySelector('link[href="'+href+'"]')){ok();return;}
            var l=document.createElement('link');l.rel='stylesheet';l.href=href;
            l.onload=ok;document.head.appendChild(l);
        });
    }
};

window.addEventListener('beforeunload',function(e){
    // Unlock file if editing
    if(DocEditor._s.fileId){
        var fd=new FormData();fd.append('action','unlock_file');fd.append('id',DocEditor._s.fileId);
        navigator.sendBeacon('api/index.php',fd);
    }
    // Cleanup Google Drive temp file if still open
    if(DocEditor._gwGoogleFileId&&DocEditor._s.fileId){
        var fd2=new FormData();fd2.append('action','gw_discard');fd2.append('id',DocEditor._s.fileId);
        navigator.sendBeacon('api/index.php',fd2);
    }
    if(DocEditor._s.isDirty){e.preventDefault();e.returnValue='';}
});
