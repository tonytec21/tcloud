<?php
/**
 * TCloud - Editor de Documentos
 * 
 * Gerencia abertura, conversão, edição e salvamento de documentos
 * Suporta: docx, xlsx, pdf, txt e arquivos de código
 */

class DocumentEditor {
    private PDO $db;
    private int $userId;
    private FileManager $fm;

    public function __construct(int $userId) {
        $this->db = Database::getInstance();
        $this->userId = $userId;
        $this->fm = new FileManager($userId);
    }

    /**
     * Abre um documento para edição/visualização
     * Retorna os dados necessários para o frontend renderizar o editor correto
     */
    public function openDocument(int $fileId): array {
        $file = $this->fm->getFile($fileId);
        if (!$file) {
            return ['success' => false, 'message' => 'Arquivo não encontrado.'];
        }

        $ext = strtolower($file['extension'] ?? '');
        $path = STORAGE_PATH . '/' . $file['storage_path'];

        if (!file_exists($path)) {
            return ['success' => false, 'message' => 'Arquivo não encontrado no disco.'];
        }

        // Registrar acesso
        $this->fm->addRecent($fileId);
        AuditLog::log('doc_open', 'file', $fileId, $file['original_name']);

        $result = [
            'success' => true,
            'file' => $file,
            'editor_type' => $this->getEditorType($ext),
            'can_edit' => Auth::can('files.edit'),
            'can_save' => Auth::can('files.edit'),
        ];

        // Dados específicos por tipo
        switch ($this->getEditorType($ext)) {
            case 'document':
                // DOCX/DOC → extrair HTML para edição
                $result['html_content'] = $this->docxToHtml($path, $ext);
                $result['original_ext'] = $ext;
                break;

            case 'spreadsheet':
                // XLSX/XLS → extrair dados para editor de planilha
                $result['sheet_data'] = $this->xlsxToJson($path, $ext);
                $result['original_ext'] = $ext;
                break;

            case 'pdf':
                // PDF → URL para PDF.js
                $result['preview_url'] = 'api/download.php?type=preview&id=' . $fileId;
                break;

            case 'code':
                // Texto/código → conteúdo direto
                $content = file_get_contents($path);
                $result['content'] = $content;
                $result['language'] = $this->getMonacoLanguage($ext);
                break;

            default:
                $result['preview_url'] = 'api/download.php?type=preview&id=' . $fileId;
                break;
        }

        return $result;
    }

    /**
     * Salva conteúdo de um documento editado
     */
    public function saveDocument(int $fileId, string $content, string $contentType = 'html'): array {
        $file = $this->fm->getFile($fileId);
        if (!$file) {
            return ['success' => false, 'message' => 'Arquivo não encontrado.'];
        }

        $ext = strtolower($file['extension'] ?? '');
        $path = STORAGE_PATH . '/' . $file['storage_path'];

        // Salvar versão anterior como backup
        $this->saveVersion($file);

        $saved = false;
        $newSize = 0;

        switch ($this->getEditorType($ext)) {
            case 'document':
                // HTML → DOCX
                if (in_array($ext, ['docx', 'doc'])) {
                    $saved = $this->htmlToDocx($content, $path, $file['original_name']);
                    $newSize = filesize($path);
                } else {
                    file_put_contents($path, $content);
                    $saved = true;
                    $newSize = strlen($content);
                }
                break;

            case 'spreadsheet':
                // JSON → XLSX
                if (in_array($ext, ['xlsx', 'xls'])) {
                    $data = json_decode($content, true);
                    if ($data === null) {
                        return ['success' => false, 'message' => 'Dados da planilha inválidos.'];
                    }
                    $saved = $this->jsonToXlsx($data, $path);
                    $newSize = filesize($path);
                } else {
                    file_put_contents($path, $content);
                    $saved = true;
                    $newSize = strlen($content);
                }
                break;

            case 'code':
                file_put_contents($path, $content);
                $saved = true;
                $newSize = strlen($content);
                break;

            default:
                return ['success' => false, 'message' => 'Tipo de arquivo não suporta edição.'];
        }

        if (!$saved) {
            return ['success' => false, 'message' => 'Erro ao salvar documento.'];
        }

        // Atualizar metadados
        $newHash = hash_file('sha256', $path);
        $oldSize = (int)$file['size'];
        $this->db->prepare("
            UPDATE files SET size = ?, hash_sha256 = ?, version = version + 1, updated_at = NOW() WHERE id = ?
        ")->execute([$newSize, $newHash, $fileId]);

        // Atualizar espaço
        $delta = $newSize - $oldSize;
        $this->db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) + ?) WHERE id = ?")
            ->execute([$delta, $this->userId]);

        AuditLog::log('doc_save', 'file', $fileId, $file['original_name'], [
            'old_size' => $oldSize,
            'new_size' => $newSize,
            'content_type' => $contentType
        ]);

        return [
            'success' => true,
            'message' => 'Documento salvo com sucesso!',
            'size' => $newSize,
            'version' => $file['version'] + 1
        ];
    }

    /**
     * Salva como novo arquivo
     */
    public function saveAs(int $originalFileId, string $newName, string $content, string $contentType, ?int $folderId = null): array {
        $original = $this->fm->getFile($originalFileId);
        if (!$original) {
            return ['success' => false, 'message' => 'Arquivo original não encontrado.'];
        }

        $ext = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
        if (empty($ext)) {
            $ext = $original['extension'];
            $newName .= '.' . $ext;
        }

        // Criar arquivo temporário
        $tempPath = TEMP_PATH . '/' . uniqid('saveas_') . '.' . $ext;

        switch ($this->getEditorType($ext)) {
            case 'document':
                if (in_array($ext, ['docx', 'doc'])) {
                    $this->htmlToDocx($content, $tempPath, $newName);
                } else {
                    file_put_contents($tempPath, $content);
                }
                break;
            case 'spreadsheet':
                if (in_array($ext, ['xlsx', 'xls'])) {
                    $data = json_decode($content, true) ?: [];
                    $this->jsonToXlsx($data, $tempPath);
                } else {
                    file_put_contents($tempPath, $content);
                }
                break;
            default:
                file_put_contents($tempPath, $content);
        }

        if (!file_exists($tempPath)) {
            return ['success' => false, 'message' => 'Erro ao gerar arquivo.'];
        }

        // Upload como novo arquivo
        $fileData = [
            'name'     => $newName,
            'type'     => mime_content_type($tempPath) ?: 'application/octet-stream',
            'tmp_name' => $tempPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tempPath),
        ];
        $targetFolder = $folderId !== null ? $folderId : $original['folder_id'];
        $result = $this->fm->uploadFile($fileData, $targetFolder, 'rename');
        @unlink($tempPath);

        if ($result['success']) {
            AuditLog::log('doc_save_as', 'file', $result['id'] ?? 0, $newName);
        }

        return $result;
    }

    /**
     * Obtém versões de um arquivo
     */
    public function getVersions(int $fileId): array {
        $stmt = $this->db->prepare("
            SELECT fv.*, u.full_name as user_name
            FROM file_versions fv
            JOIN users u ON fv.user_id = u.id
            WHERE fv.file_id = ? 
            ORDER BY fv.version_number DESC
            LIMIT 20
        ");
        $stmt->execute([$fileId]);
        return $stmt->fetchAll();
    }

    /**
     * Restaura uma versão anterior
     */
    public function restoreVersion(int $fileId, int $versionId): array {
        $file = $this->fm->getFile($fileId);
        if (!$file) return ['success' => false, 'message' => 'Arquivo não encontrado.'];

        $stmt = $this->db->prepare("SELECT * FROM file_versions WHERE id = ? AND file_id = ?");
        $stmt->execute([$versionId, $fileId]);
        $version = $stmt->fetch();
        if (!$version) return ['success' => false, 'message' => 'Versão não encontrada.'];

        $currentPath = STORAGE_PATH . '/' . $file['storage_path'];
        $versionPath = STORAGE_PATH . '/' . $version['storage_path'];

        if (!file_exists($versionPath)) {
            return ['success' => false, 'message' => 'Arquivo da versão não encontrado no disco.'];
        }

        // Salvar versão atual como backup antes de restaurar
        $this->saveVersion($file);

        // Copiar versão sobre o arquivo atual
        copy($versionPath, $currentPath);
        $newSize = filesize($currentPath);
        $newHash = hash_file('sha256', $currentPath);

        $this->db->prepare("UPDATE files SET size = ?, hash_sha256 = ?, version = version + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$newSize, $newHash, $fileId]);

        AuditLog::log('doc_restore_version', 'file', $fileId, $file['original_name'], [
            'restored_version' => $version['version_number']
        ]);

        return ['success' => true, 'message' => 'Versão restaurada com sucesso!'];
    }

    // ================================================================
    // CONVERSÃO DOCX ↔ HTML
    // ================================================================

    /**
     * Extrai conteúdo HTML de um arquivo DOCX
     * Usado como fallback quando OnlyOffice não está disponível
     */
    private function docxToHtml(string $filePath, string $ext): string {
        if ($ext === 'doc') {
            return $this->extractDocText($filePath);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '<p>Erro ao abrir documento.</p>';
        }

        $docXml = $zip->getFromName('word/document.xml');
        if (!$docXml) {
            $zip->close();
            return '<p>Documento vazio.</p>';
        }

        // 1. Load relationships (rId → image path mapping)
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels') ?: '';
        $imageMap = []; // rId => base64 data URI
        if (preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]*media\/[^"]*)"/', $relsXml, $relMatches, PREG_SET_ORDER)) {
            foreach ($relMatches as $rm) {
                $rId = $rm[1];
                $target = $rm[2];
                $imgPath = 'word/' . ltrim($target, '/');
                $imgData = $zip->getFromName($imgPath);
                if ($imgData) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($imgData) ?: 'image/png';
                    $imageMap[$rId] = 'data:' . $mime . ';base64,' . base64_encode($imgData);
                }
            }
        }

        // 1b. Read ruler/editor settings from custom properties
        $metaML = 113; $metaMR = 76; $metaFI = 0;
        $customXml = $zip->getFromName('docProps/custom.xml');
        if ($customXml) {
            if (preg_match('/name="cv_margin_left"[^>]*>\s*<vt:i4>(\d+)</', $customXml, $cm)) $metaML = (int)$cm[1];
            if (preg_match('/name="cv_margin_right"[^>]*>\s*<vt:i4>(\d+)</', $customXml, $cm)) $metaMR = (int)$cm[1];
            if (preg_match('/name="cv_first_indent"[^>]*>\s*<vt:i4>(-?\d+)</', $customXml, $cm)) $metaFI = (int)$cm[1];
        }

        $zip->close();

        // 2. Extract body content between <w:body> and </w:body>
        $bodyContent = '';
        if (preg_match('/<w:body[^>]*>(.*)<\/w:body>/s', $docXml, $bm)) {
            $bodyContent = $bm[1];
        }
        if (empty($bodyContent)) {
            return $this->extractTextFromXml($docXml);
        }

        $html = '';

        // 3. Split into top-level elements: paragraphs and tables
        // We process tables and paragraphs separately
        $pos = 0;
        $len = strlen($bodyContent);

        while ($pos < $len) {
            // Find next <w:tbl> or <w:p>
            $nextTbl = strpos($bodyContent, '<w:tbl>', $pos);
            $nextP = strpos($bodyContent, '<w:p ', $pos);
            if ($nextP === false) $nextP = strpos($bodyContent, '<w:p>', $pos);

            if ($nextTbl !== false && ($nextP === false || $nextTbl < $nextP)) {
                // Table comes first
                $tblEnd = strpos($bodyContent, '</w:tbl>', $nextTbl);
                if ($tblEnd === false) break;
                $tblEnd += strlen('</w:tbl>');
                $tblXml = substr($bodyContent, $nextTbl, $tblEnd - $nextTbl);
                $html .= $this->parseTableRegex($tblXml, $imageMap);
                $pos = $tblEnd;
            } elseif ($nextP !== false) {
                // Paragraph comes first
                $pEnd = strpos($bodyContent, '</w:p>', $nextP);
                if ($pEnd === false) break;
                $pEnd += strlen('</w:p>');
                $pXml = substr($bodyContent, $nextP, $pEnd - $nextP);
                $html .= $this->parseParagraphRegex($pXml, $imageMap);
                $pos = $pEnd;
            } else {
                break;
            }
        }

        // Append ruler metadata so the frontend can restore settings
        $html .= '<div id="cv-meta" data-ml="' . $metaML . '" data-mr="' . $metaMR . '" data-fi="' . $metaFI . '" style="display:none">&zwj;</div>';

        return $html ?: '<p></p>';
    }

    /**
     * Parse a paragraph using regex (robust against namespace issues)
     */
    private function parseParagraphRegex(string $pXml, array $imageMap): string {
        $text = '';
        $alignment = '';
        $tag = 'p';

        // Detect heading style
        if (preg_match('/w:val="Heading(\d)"/', $pXml, $hm) ||
            preg_match('/w:val="Ttulo(\d)"/', $pXml, $hm) ||
            preg_match('/w:val="Title"/', $pXml)) {
            if (isset($hm[1])) {
                $tag = 'h' . min((int)$hm[1], 6);
            } else {
                $tag = 'h1';
            }
        }

        // Detect alignment
        if (preg_match('/<w:jc\s[^>]*w:val="(\w+)"/', $pXml, $am)) {
            $alignMap = ['center'=>'center','right'=>'right','both'=>'justify','left'=>'left'];
            $alignment = $alignMap[$am[1]] ?? '';
        }

        // Extract images from drawings (inline and anchor)
        // Find drawing blocks containing image references
        if (preg_match_all('/<w:drawing>(.*?)<\/w:drawing>/s', $pXml, $drawings)) {
            foreach ($drawings[1] as $drawXml) {
                // Get the rId
                if (preg_match('/r:embed="(rId\d+)"/', $drawXml, $ridMatch)) {
                    $rId = $ridMatch[1];
                    if (isset($imageMap[$rId])) {
                        // Read dimensions from wp:extent cx/cy (EMU units, 1px = 9525 EMU)
                        $imgStyle = 'max-width:100%;height:auto';
                        if (preg_match('/<wp:extent\s[^>]*cx="(\d+)"[^>]*cy="(\d+)"/', $drawXml, $extMatch)) {
                            $wPx = max(1, round((int)$extMatch[1] / 9525));
                            $hPx = max(1, round((int)$extMatch[2] / 9525));
                            $imgStyle = 'width:' . $wPx . 'px;height:' . $hPx . 'px;max-width:100%';
                        }
                        $text .= '<img src="' . $imageMap[$rId] . '" style="' . $imgStyle . '" />';
                    }
                }
            }
        }
        // Also check mc:AlternateContent for images
        if (preg_match_all('/<mc:AlternateContent>(.*?)<\/mc:AlternateContent>/s', $pXml, $altBlocks)) {
            foreach ($altBlocks[1] as $altXml) {
                if (preg_match('/r:embed="(rId\d+)"/', $altXml, $ridMatch)) {
                    $rId = $ridMatch[1];
                    if (isset($imageMap[$rId]) && strpos($text, $imageMap[$rId]) === false) {
                        $imgStyle = 'max-width:100%;height:auto';
                        if (preg_match('/cx="(\d+)"[^>]*cy="(\d+)"/', $altXml, $extMatch)) {
                            $wPx = max(1, round((int)$extMatch[1] / 9525));
                            $hPx = max(1, round((int)$extMatch[2] / 9525));
                            $imgStyle = 'width:' . $wPx . 'px;height:' . $hPx . 'px;max-width:100%';
                        }
                        $text .= '<img src="' . $imageMap[$rId] . '" style="' . $imgStyle . '" />';
                    }
                }
            }
        }

        // Extract text runs: find all <w:r>...</w:r> blocks
        if (preg_match_all('/<w:r[ >](.*?)<\/w:r>/s', $pXml, $runs)) {
            foreach ($runs[1] as $runXml) {
                // Skip runs that contain drawings (already handled above)
                if (strpos($runXml, '<w:drawing>') !== false) continue;
                if (strpos($runXml, '<mc:AlternateContent>') !== false) continue;

                $runText = '';
                // Extract text from <w:t> elements
                if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $runXml, $tMatches)) {
                    $runText = implode('', $tMatches[1]);
                }

                // Check for breaks
                if (strpos($runXml, '<w:br') !== false) {
                    $runText .= '<br>';
                }
                // Check for tabs
                if (strpos($runXml, '<w:tab') !== false) {
                    $runText = '&emsp;' . $runText;
                }

                if ($runText === '' && strpos($runXml, '<w:br') === false) continue;

                // Extract formatting
                $styles = [];
                if (preg_match('/<w:rPr>(.*?)<\/w:rPr>/s', $runXml, $rpr)) {
                    $fmt = $rpr[1];
                    if (strpos($fmt, '<w:b/>') !== false || strpos($fmt, '<w:b ') !== false) $styles[] = 'font-weight:bold';
                    if (strpos($fmt, '<w:i/>') !== false || strpos($fmt, '<w:i ') !== false) $styles[] = 'font-style:italic';
                    if (strpos($fmt, '<w:u ') !== false) $styles[] = 'text-decoration:underline';
                    if (preg_match('/w:val="(\d+)"/', $fmt, $szm) && strpos($fmt, '<w:sz') !== false) {
                        // Only get sz, not szCs
                        if (preg_match('/<w:sz\s[^>]*w:val="(\d+)"/', $fmt, $szm)) {
                            $styles[] = 'font-size:' . round((int)$szm[1] / 2) . 'pt';
                        }
                    }
                    if (preg_match('/<w:color\s[^>]*w:val="([A-Fa-f0-9]{6})"/', $fmt, $cm)) {
                        if ($cm[1] !== '000000') $styles[] = 'color:#' . $cm[1];
                    }
                    if (preg_match('/<w:rFonts\s[^>]*w:ascii="([^"]+)"/', $fmt, $fm)) {
                        $styles[] = 'font-family:' . htmlspecialchars($fm[1]);
                    }
                }

                $escapedText = htmlspecialchars($runText);
                // Preserve line breaks
                $escapedText = str_replace('&lt;br&gt;', '<br>', $escapedText);

                if (!empty($styles)) {
                    $text .= '<span style="' . implode(';', $styles) . '">' . $escapedText . '</span>';
                } else {
                    $text .= $escapedText;
                }
            }
        }

        // Extract hyperlink text
        if (preg_match_all('/<w:hyperlink[^>]*>(.*?)<\/w:hyperlink>/s', $pXml, $hlinks)) {
            foreach ($hlinks[1] as $hlXml) {
                if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $hlXml, $htMatches)) {
                    $linkText = implode('', $htMatches[1]);
                    if (!empty($linkText) && strpos($text, htmlspecialchars($linkText)) === false) {
                        $text .= htmlspecialchars($linkText);
                    }
                }
            }
        }

        // Skip empty paragraphs that only had drawings (already output)
        if (empty(trim(strip_tags($text, '<img><br>')))) {
            if (strpos($text, '<img') !== false) {
                // Image-only paragraph
                return '<p>' . $text . '</p>' . "\n";
            }
            // Truly empty — still output for spacing
            return "<p></p>\n";
        }

        $styleAttr = $alignment ? ' style="text-align:' . $alignment . '"' : '';
        return "<{$tag}{$styleAttr}>{$text}</{$tag}>\n";
    }

    /**
     * Parse a table using regex
     */
    private function parseTableRegex(string $tblXml, array $imageMap): string {
        $html = '<table border="1" style="border-collapse:collapse;width:100%">';

        // Extract rows
        if (preg_match_all('/<w:tr[ >](.*?)<\/w:tr>/s', $tblXml, $rows)) {
            foreach ($rows[1] as $rowXml) {
                $html .= '<tr>';
                // Extract cells
                if (preg_match_all('/<w:tc[ >](.*?)<\/w:tc>/s', $rowXml, $cells)) {
                    foreach ($cells[1] as $cellXml) {
                        $cellContent = '';
                        // Extract paragraphs within cell
                        if (preg_match_all('/<w:p[ >](.*?)<\/w:p>/s', $cellXml, $cellPs)) {
                            foreach ($cellPs[0] as $cpXml) {
                                $pHtml = $this->parseParagraphRegex($cpXml, $imageMap);
                                $cellContent .= strip_tags($pHtml, '<span><strong><em><br><img>');
                            }
                        }
                        // Check for merged cells
                        $colspan = '';
                        if (preg_match('/<w:gridSpan\s[^>]*w:val="(\d+)"/', $cellXml, $gs)) {
                            $colspan = ' colspan="' . $gs[1] . '"';
                        }
                        $html .= '<td style="padding:6px 8px;border:1px solid #ccc"' . $colspan . '>' . ($cellContent ?: '&nbsp;') . '</td>';
                    }
                }
                $html .= '</tr>';
            }
        }

        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Fallback: extract text from raw XML
     */
    private function extractTextFromXml(string $xmlContent): string {
        $html = '';
        $paragraphs = preg_split('/<\/w:p>/', $xmlContent);
        foreach ($paragraphs as $para) {
            if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/u', $para, $pMatches)) {
                $text = implode('', $pMatches[1]);
                $text = trim($text);
                if (!empty($text)) {
                    $html .= '<p>' . htmlspecialchars($text) . '</p>' . "\n";
                }
            }
        }
        return $html ?: '<p></p>';
    }

    /**
     * Extrai texto de .doc (formato antigo, binário)
     */
    private function extractDocText(string $filePath): string {
        $content = file_get_contents($filePath);
        // Tentar extrair texto entre markers do .doc
        $text = '';
        if (preg_match_all('/[\x20-\x7E\xA0-\xFF]{4,}/', $content, $matches)) {
            $text = implode(' ', $matches[0]);
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        if (empty($text)) {
            return '<p><em>Este é um arquivo .doc (formato antigo). Para edição completa, recomendamos converter para .docx.</em></p>';
        }
        
        // Quebrar em parágrafos
        $paragraphs = preg_split('/[.!?]\s+/', $text);
        $html = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (!empty($p)) {
                $html .= '<p>' . htmlspecialchars($p) . '.</p>';
            }
        }
        return $html ?: '<p></p>';
    }

    /**
     * Converte HTML editado de volta para DOCX
     */
    private $_docxImages = []; // Temporary storage for images during conversion

    private function htmlToDocx(string $htmlContent, string $outputPath, string $title = ''): bool {
        $this->_docxImages = [];

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $titleEsc = htmlspecialchars(pathinfo($title, PATHINFO_FILENAME), ENT_XML1, 'UTF-8');
        $now = date('Y-m-d\TH:i:s\Z');

        // 0. Extract ruler/editor metadata from HTML (cv-meta div)
        $metaML = 113; $metaMR = 76; $metaFI = 0;
        if (preg_match('/<div id="cv-meta"[^>]*data-ml="(\d+)"[^>]*data-mr="(\d+)"[^>]*data-fi="(-?\d+)"/', $htmlContent, $metaM)) {
            $metaML = (int)$metaM[1];
            $metaMR = (int)$metaM[2];
            $metaFI = (int)$metaM[3];
        }
        // Remove cv-meta from HTML before processing
        $htmlContent = preg_replace('/<div id="cv-meta"[^>]*>[\s\S]*?<\/div>/i', '', $htmlContent);

        // 1. Extract base64 images from HTML and prepare for embedding
        $htmlContent = $this->extractImagesForDocx($htmlContent, $zip);

        // 2. Convert HTML to Word XML
        $wordContent = $this->htmlToWordXml($htmlContent);

        // 3. Build image content types (deduplicate extensions!)
        $imgExtSeen = [];
        $imgContentTypes = '';
        $imgRels = '';
        foreach ($this->_docxImages as $idx => $img) {
            $ext = $img['ext'];
            $rId = $img['rId'];
            if (!isset($imgExtSeen[$ext])) {
                $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                $imgContentTypes .= '    <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
                $imgExtSeen[$ext] = true;
            }
            $imgRels .= '    <Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $img['filename'] . '"/>' . "\n";
        }

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    ' . $imgContentTypes . '
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
    <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/>
</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties" Target="docProps/custom.xml"/>
</Relationships>');

        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
    ' . $imgRels . '
</Relationships>');

        // Document
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
    xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
    <w:body>
' . $wordContent . '
        <w:sectPr>
            <w:pgSz w:w="11906" w:h="16838"/>
            <w:pgMar w:top="1701" w:right="1134" w:bottom="1134" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>
        </w:sectPr>
    </w:body>
</w:document>');

        // Styles
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:docDefaults>
        <w:rPrDefault><w:rPr>
            <w:rFonts w:ascii="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri" w:cs="Times New Roman"/>
            <w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="pt-BR"/>
        </w:rPr></w:rPrDefault>
        <w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault>
    </w:docDefaults>
    <w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
    <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr></w:style>
    <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>
    <w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>
</w:styles>');

        // Numbering
        $zip->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="0">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/>
        <w:lvlText w:val="&#x2022;"/><w:lvlJc w:val="left"/>
        <w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>
    </w:abstractNum>
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/>
        <w:lvlText w:val="%1."/><w:lvlJc w:val="left"/>
        <w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
    <w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>');

        // Core properties
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
    xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>' . $titleEsc . '</dc:title>
    <dc:creator>TCloud</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>
</cp:coreProperties>');

        // Custom properties (ruler settings)
        $zip->addFromString('docProps/custom.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"
    xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="2" name="cv_margin_left">
        <vt:i4>' . $metaML . '</vt:i4>
    </property>
    <property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="3" name="cv_margin_right">
        <vt:i4>' . $metaMR . '</vt:i4>
    </property>
    <property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="4" name="cv_first_indent">
        <vt:i4>' . $metaFI . '</vt:i4>
    </property>
</Properties>');

        $zip->close();
        $this->_docxImages = [];
        return file_exists($outputPath);
    }

    /**
     * Extract base64 images from HTML, save to DOCX zip, replace with placeholders
     */
    private function extractImagesForDocx(string $html, ZipArchive $zip): string {
        $images = &$this->_docxImages;
        $counter = 0;
        $result = '';
        $pos = 0;

        // Find each <img...src="data:image/...;base64,..." /> without regex on the base64 part
        while (($imgStart = stripos($html, '<img', $pos)) !== false) {
            // Add everything before this img tag
            $result .= substr($html, $pos, $imgStart - $pos);

            // Find end of img tag
            $imgEnd = strpos($html, '>', $imgStart);
            if ($imgEnd === false) { $result .= substr($html, $imgStart); break; }
            $imgEnd++; // Include the >
            $imgTag = substr($html, $imgStart, $imgEnd - $imgStart);

            // Check if it has a base64 data URI
            $dataPos = strpos($imgTag, 'src="data:image/');
            if ($dataPos === false) {
                $result .= $imgTag;
                $pos = $imgEnd;
                continue;
            }

            // Extract the full src value
            $srcStart = strpos($imgTag, '"', $dataPos + 4) + 1; // after src="
            // Find end of src value - but the base64 data might be huge, so find closing quote from the data URI
            $base64Marker = strpos($imgTag, ';base64,', $dataPos);
            if ($base64Marker === false) {
                $result .= $imgTag;
                $pos = $imgEnd;
                continue;
            }

            // Actually, we need to find the closing " of the src attribute in the FULL HTML 
            // because the img tag might have been cut short if the base64 is huge
            $srcQuoteStart = strpos($html, 'src="data:image/', $imgStart);
            if ($srcQuoteStart === false) { $result .= $imgTag; $pos = $imgEnd; continue; }
            $srcValueStart = strpos($html, '"', $srcQuoteStart + 4) + 1;
            $srcValueEnd = strpos($html, '"', $srcValueStart);
            if ($srcValueEnd === false) { $result .= $imgTag; $pos = $imgEnd; continue; }
            
            $dataUri = substr($html, $srcValueStart, $srcValueEnd - $srcValueStart);
            $realImgEnd = strpos($html, '>', $srcValueEnd);
            if ($realImgEnd === false) { $result .= $imgTag; $pos = $imgEnd; continue; }
            $realImgEnd++;
            $fullImgTag = substr($html, $imgStart, $realImgEnd - $imgStart);

            // Parse extension from data URI
            $extMatch = [];
            if (!preg_match('/^data:image\/([a-z]+)/i', $dataUri, $extMatch)) {
                $result .= $fullImgTag;
                $pos = $realImgEnd;
                continue;
            }
            $ext = strtolower($extMatch[1]);
            if ($ext === 'jpeg') $ext = 'jpg';

            // Extract base64 data
            $b64Start = strpos($dataUri, 'base64,');
            if ($b64Start === false) { $result .= $fullImgTag; $pos = $realImgEnd; continue; }
            $b64Data = substr($dataUri, $b64Start + 7);
            $data = @base64_decode($b64Data);
            if (!$data || strlen($data) < 10) { $result .= $fullImgTag; $pos = $realImgEnd; continue; }

            $counter++;
            $filename = 'image' . $counter . '.' . $ext;
            $rId = 'rId' . (10 + $counter - 1);
            $zip->addFromString('word/media/' . $filename, $data);

            // Get dimensions
            $size = @getimagesizefromstring($data);
            $origW = $size ? $size[0] : 400;
            $origH = $size ? $size[1] : 300;

            // Check for user-set dimensions in attributes
            $userW = 0; $userH = 0;
            if (preg_match('/\bwidth[=:]\s*"?(\d+)/', $fullImgTag, $wm)) $userW = (int)$wm[1];
            if (preg_match('/\bheight[=:]\s*"?(\d+)/', $fullImgTag, $hm)) $userH = (int)$hm[1];
            if (preg_match('/width:\s*(\d+)px/', $fullImgTag, $wm)) $userW = (int)$wm[1];
            if (preg_match('/height:\s*(\d+)px/', $fullImgTag, $hm)) $userH = (int)$hm[1];

            $width = $userW > 0 ? $userW : $origW;
            $height = $userH > 0 ? $userH : $origH;
            if ($userW > 0 && $userH === 0 && $origW > 0) $height = (int)($origH * ($userW / $origW));

            $wEmu = $width * 9525;
            $hEmu = $height * 9525;
            if ($wEmu > 5400000) { $r = 5400000/$wEmu; $wEmu=5400000; $hEmu=(int)($hEmu*$r); }

            $images[] = ['ext' => $ext, 'filename' => $filename, 'rId' => $rId];
            $result .= '<img data-docx-rid="' . $rId . '" data-docx-w="' . $wEmu . '" data-docx-h="' . $hEmu . '" />';
            $pos = $realImgEnd;
        }

        // Add remaining content after last img
        $result .= substr($html, $pos);
        return $result;
    }

    /**
     * Converte HTML para Word XML paragraphs
     */
    private function htmlToWordXml(string $html): string {
        // Clean HTML and ensure UTF-8
        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Wrap with UTF-8 meta to ensure proper encoding
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div>' . $html . '</div></body></html>';
        @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

        $wordXml = '';
        // Find the wrapping div
        $divs = $dom->getElementsByTagName('div');
        $body = $divs->length > 0 ? $divs->item(0) : null;

        if (!$body) {
            return '<w:p><w:r><w:t></w:t></w:r></w:p>';
        }

        foreach ($body->childNodes as $node) {
            $wordXml .= $this->domNodeToWordXml($node);
        }

        return $wordXml ?: '<w:p><w:r><w:t></w:t></w:r></w:p>';
    }

    /**
     * Converte um nó DOM para Word XML
     */
    private function domNodeToWordXml(\DOMNode $node): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->textContent;
            if (trim($text) === '') return '';
            return $this->makeWordRun($text);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        $tag = strtolower($node->nodeName);

        // Skip hidden metadata
        if ($node instanceof \DOMElement && $node->getAttribute('id') === 'cv-meta') return '';
        // Skip page break divs
        if ($node instanceof \DOMElement && (
            strpos($node->getAttribute('class'), 'cv-pb') !== false ||
            strpos($node->getAttribute('class'), 'cv-apb') !== false
        )) return '';

        // Images (with docx-rid from our extraction)
        if ($tag === 'img' && $node instanceof \DOMElement) {
            $rId = $node->getAttribute('data-docx-rid');
            if ($rId) {
                static $imgCounter = 1;
                $imgId = $imgCounter++;
                $w = (int)$node->getAttribute('data-docx-w') ?: 5400000;
                $h = (int)$node->getAttribute('data-docx-h') ?: 3600000;
                return '<w:p><w:r><w:rPr><w:noProof/></w:rPr><w:drawing>' .
'<wp:inline distT="0" distB="0" distL="0" distR="0">' .
'<wp:extent cx="' . $w . '" cy="' . $h . '"/>' .
'<wp:effectExtent l="0" t="0" r="0" b="0"/>' .
'<wp:docPr id="' . $imgId . '" name="Imagem ' . $imgId . '"/>' .
'<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>' .
'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' .
'<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' .
'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' .
'<pic:nvPicPr><pic:cNvPr id="' . $imgId . '" name="Imagem ' . $imgId . '"/><pic:cNvPicPr/></pic:nvPicPr>' .
'<pic:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>' .
'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>' .
'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>' .
'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>' . "\n";
            }
            return '';
        }

        // Headings
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $level = $m[1];
            $styleId = 'Heading' . $level;
            $innerXml = $this->processChildNodes($node, true);
            return '<w:p><w:pPr><w:pStyle w:val="' . $styleId . '"/></w:pPr>' . $innerXml . '</w:p>' . "\n";
        }

        // Paragraph / div
        if (in_array($tag, ['p', 'div'])) {
            $pPr = '';
            $style = $node instanceof \DOMElement ? $node->getAttribute('style') : '';
            if (strpos($style, 'text-align:center') !== false || strpos($style, 'text-align: center') !== false) {
                $pPr = '<w:pPr><w:jc w:val="center"/></w:pPr>';
            } elseif (strpos($style, 'text-align:right') !== false || strpos($style, 'text-align: right') !== false) {
                $pPr = '<w:pPr><w:jc w:val="right"/></w:pPr>';
            } elseif (strpos($style, 'text-align:justify') !== false || strpos($style, 'text-align: justify') !== false) {
                $pPr = '<w:pPr><w:jc w:val="both"/></w:pPr>';
            }
            $innerXml = $this->processChildNodes($node, false);
            if (empty(trim($innerXml))) {
                $innerXml = '<w:r><w:t></w:t></w:r>';
            }
            return '<w:p>' . $pPr . $innerXml . '</w:p>' . "\n";
        }

        // Line break
        if ($tag === 'br') {
            return '<w:r><w:br/></w:r>';
        }

        // HR
        if ($tag === 'hr') {
            return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>' . "\n";
        }

        // Lists
        if (in_array($tag, ['ul', 'ol'])) {
            $numId = $tag === 'ul' ? '1' : '2';
            $listXml = '';
            foreach ($node->childNodes as $li) {
                if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->nodeName) === 'li') {
                    $innerXml = $this->processChildNodes($li, false);
                    $listXml .= '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr></w:pPr>' . $innerXml . '</w:p>' . "\n";
                }
            }
            return $listXml;
        }

        // Table
        if ($tag === 'table') {
            return $this->tableToWordXml($node);
        }

        // Inline elements
        if (in_array($tag, ['span', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'sub', 'sup'])) {
            return $this->processChildNodes($node, false, $tag);
        }

        // Blockquote
        if ($tag === 'blockquote') {
            $innerXml = $this->processChildNodes($node, false);
            return '<w:p><w:pPr><w:ind w:left="720"/></w:pPr>' . $innerXml . '</w:p>' . "\n";
        }

        // Default: recurse
        $result = '';
        foreach ($node->childNodes as $child) {
            $result .= $this->domNodeToWordXml($child);
        }
        return $result;
    }

    private function processChildNodes(\DOMNode $node, bool $forceBold = false, string $wrapTag = ''): string {
        $xml = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->textContent;
                $styles = [];
                if ($forceBold || in_array($wrapTag, ['strong', 'b'])) $styles[] = '<w:b/>';
                if (in_array($wrapTag, ['em', 'i'])) $styles[] = '<w:i/>';
                if ($wrapTag === 'u') $styles[] = '<w:u w:val="single"/>';
                if ($wrapTag === 's') $styles[] = '<w:strike/>';

                // Parse inline styles from parent span
                if ($wrapTag === 'span' && $node instanceof \DOMElement) {
                    $css = $node->getAttribute('style');
                    if (strpos($css, 'font-weight:bold') !== false || strpos($css, 'font-weight: bold') !== false) $styles[] = '<w:b/>';
                    if (strpos($css, 'font-style:italic') !== false || strpos($css, 'font-style: italic') !== false) $styles[] = '<w:i/>';
                    if (strpos($css, 'text-decoration:underline') !== false || strpos($css, 'text-decoration: underline') !== false) $styles[] = '<w:u w:val="single"/>';
                    if (preg_match('/font-size:\s*(\d+)pt/', $css, $szm)) {
                        $val = (int)$szm[1] * 2;
                        $styles[] = '<w:sz w:val="' . $val . '"/><w:szCs w:val="' . $val . '"/>';
                    }
                    if (preg_match('/color:\s*#([A-Fa-f0-9]{6})/', $css, $cm)) {
                        $styles[] = '<w:color w:val="' . $cm[1] . '"/>';
                    }
                    if (preg_match('/font-family:\s*([^;]+)/', $css, $fm)) {
                        $font = trim($fm[1], "' \"");
                        $styles[] = '<w:rFonts w:ascii="' . htmlspecialchars($font, ENT_XML1) . '" w:hAnsi="' . htmlspecialchars($font, ENT_XML1) . '"/>';
                    }
                    if (preg_match('/background-color:\s*#([A-Fa-f0-9]{6})/', $css, $hm)) {
                        $styles[] = '<w:highlight w:val="yellow"/>'; // Simplified highlight
                    }
                }

                $rPr = !empty($styles) ? '<w:rPr>' . implode('', array_unique($styles)) . '</w:rPr>' : '';
                $xml .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r>';
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $childTag = strtolower($child->nodeName);
                if ($childTag === 'br') {
                    $xml .= '<w:r><w:br/></w:r>';
                } elseif ($childTag === 'img') {
                    // Handle inline images
                    $xml .= $this->domNodeToWordXml($child);
                } elseif (in_array($childTag, ['strong','b','em','i','u','s','span','a'])) {
                    $xml .= $this->processChildNodes($child, $forceBold, $childTag);
                } else {
                    $xml .= $this->domNodeToWordXml($child);
                }
            }
        }
        return $xml;
    }

    private function tableToWordXml(\DOMNode $table): string {
        $xml = '<w:tbl><w:tblPr><w:tblBorders>
            <w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>
            <w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>
            <w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>
            <w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>
            <w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>
            <w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>
        </w:tblBorders><w:tblW w:w="5000" w:type="pct"/></w:tblPr>';

        $rows = [];
        // Coletar linhas de thead e tbody
        foreach ($table->childNodes as $section) {
            if ($section->nodeType !== XML_ELEMENT_NODE) continue;
            $sTag = strtolower($section->nodeName);
            if (in_array($sTag, ['thead', 'tbody', 'tfoot'])) {
                foreach ($section->childNodes as $row) {
                    if ($row->nodeType === XML_ELEMENT_NODE && strtolower($row->nodeName) === 'tr') {
                        $rows[] = $row;
                    }
                }
            } elseif ($sTag === 'tr') {
                $rows[] = $section;
            }
        }

        foreach ($rows as $row) {
            $xml .= '<w:tr>';
            foreach ($row->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                $cellTag = strtolower($cell->nodeName);
                if (!in_array($cellTag, ['td', 'th'])) continue;

                $xml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>';
                $cellContent = $this->processChildNodes($cell, $cellTag === 'th');
                $xml .= '<w:p>' . (empty(trim($cellContent)) ? '<w:r><w:t></w:t></w:r>' : $cellContent) . '</w:p>';
                $xml .= '</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        $xml .= '</w:tbl>';
        return $xml;
    }

    private function makeWordRun(string $text, array $styles = []): string {
        $rPr = '';
        if (!empty($styles)) {
            $rPr = '<w:rPr>' . implode('', $styles) . '</w:rPr>';
        }
        return '<w:r>' . $rPr . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r>';
    }

    // ================================================================
    // CONVERSÃO XLSX ↔ JSON
    // ================================================================

    /**
     * Extrai dados de um XLSX para formato JSON (para x-spreadsheet)
     */
    private function xlsxToJson(string $filePath, string $ext): array {
        if ($ext === 'xls') {
            return [['name' => 'Planilha1', 'rows' => [['cells' => [['text' => 'Arquivo .xls (formato antigo). Salve como .xlsx para edição completa.']]]]]];
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [['name' => 'Planilha1', 'rows' => []]];
        }

        // Ler shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = @simplexml_load_string($ssXml);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Ler dados da planilha
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            return [['name' => 'Planilha1', 'rows' => []]];
        }

        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet) {
            return [['name' => 'Planilha1', 'rows' => []]];
        }

        $rows = [];
        if (isset($sheet->sheetData->row)) {
            foreach ($sheet->sheetData->row as $row) {
                $rowNum = (int)$row['r'] - 1;
                $cells = [];

                foreach ($row->c as $cell) {
                    $ref = (string)$cell['r'];
                    $colIdx = $this->colLetterToIndex($ref);
                    $type = (string)($cell['t'] ?? '');
                    $value = '';

                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's' && isset($sharedStrings[(int)$value])) {
                            $value = $sharedStrings[(int)$value];
                        }
                    } elseif (isset($cell->is->t)) {
                        $value = (string)$cell->is->t;
                    }

                    $cells[$colIdx] = ['text' => $value];
                }

                $rows[$rowNum] = ['cells' => $cells];
            }
        }

        return [['name' => 'Planilha1', 'rows' => $rows]];
    }

    /**
     * Converte dados JSON de volta para XLSX
     */
    private function jsonToXlsx(array $data, string $outputPath): bool {
        $rows = [];
        if (!empty($data) && isset($data[0]['rows'])) {
            foreach ($data[0]['rows'] as $rowIdx => $row) {
                $rowData = [];
                if (isset($row['cells'])) {
                    foreach ($row['cells'] as $colIdx => $cell) {
                        $rowData[$colIdx] = $cell['text'] ?? '';
                    }
                }
                if (!empty($rowData)) {
                    $rows[$rowIdx] = $rowData;
                }
            }
        }

        return OfficeGenerator::createXlsx($outputPath, 'Planilha', $this->convertRowsForXlsx($rows));
    }

    private function convertRowsForXlsx(array $rows): array {
        $result = [];
        foreach ($rows as $rowIdx => $cells) {
            $row = [];
            ksort($cells);
            $maxCol = empty($cells) ? 0 : max(array_keys($cells));
            for ($c = 0; $c <= $maxCol; $c++) {
                $row[] = $cells[$c] ?? '';
            }
            $result[$rowIdx] = $row;
        }
        // Preencher linhas vazias intermediárias
        if (!empty($result)) {
            $maxRow = max(array_keys($result));
            for ($r = 0; $r <= $maxRow; $r++) {
                if (!isset($result[$r])) {
                    $result[$r] = [''];
                }
            }
            ksort($result);
        }
        return array_values($result);
    }

    /**
     * Converte referência de coluna (A, B, ..., AA) para índice numérico
     */
    private function colLetterToIndex(string $cellRef): int {
        preg_match('/^([A-Z]+)/', $cellRef, $m);
        $letters = $m[1] ?? 'A';
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A'));
        }
        return $index;
    }

    // ================================================================
    // UTILITÁRIOS
    // ================================================================

    /**
     * Determina qual tipo de editor usar
     */
    private function getEditorType(string $ext): string {
        $map = [
            'doc' => 'document', 'docx' => 'document', 'odt' => 'document', 'rtf' => 'document',
            'xls' => 'spreadsheet', 'xlsx' => 'spreadsheet', 'ods' => 'spreadsheet',
            'pdf' => 'pdf',
            'txt' => 'code', 'html' => 'code', 'css' => 'code', 'js' => 'code',
            'json' => 'code', 'xml' => 'code', 'csv' => 'code', 'md' => 'code',
            'log' => 'code', 'ini' => 'code', 'yaml' => 'code', 'yml' => 'code', 'svg' => 'code',
        ];
        return $map[strtolower($ext)] ?? 'preview';
    }

    /**
     * Retorna o language ID do Monaco Editor
     */
    private function getMonacoLanguage(string $ext): string {
        $map = [
            'html' => 'html', 'css' => 'css', 'js' => 'javascript', 'json' => 'json',
            'xml' => 'xml', 'md' => 'markdown', 'yaml' => 'yaml', 'yml' => 'yaml',
            'svg' => 'xml', 'csv' => 'plaintext', 'txt' => 'plaintext', 'log' => 'plaintext',
            'ini' => 'ini',
        ];
        return $map[strtolower($ext)] ?? 'plaintext';
    }

    /**
     * Salva versão do arquivo (backup)
     */
    private function saveVersion(array $file): void {
        try {
            $src = STORAGE_PATH . '/' . $file['storage_path'];
            if (!file_exists($src)) return;

            $vDir = STORAGE_PATH . '/' . $this->userId . '/versions';
            if (!is_dir($vDir)) mkdir($vDir, 0755, true);

            $versionName = 'v' . $file['version'] . '_' . $file['stored_name'];
            copy($src, $vDir . '/' . $versionName);

            $this->db->prepare("
                INSERT INTO file_versions (file_id, version_number, stored_name, storage_path, size, hash_sha256, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $file['id'], $file['version'], $versionName,
                $this->userId . '/versions/' . $versionName,
                $file['size'], $file['hash_sha256'], $this->userId
            ]);
        } catch (\Exception $e) {
            error_log("saveVersion error: " . $e->getMessage());
        }
    }
}
