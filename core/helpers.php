<?php
/**
 * TCloud - Funções Auxiliares
 */

/**
 * Resposta JSON padronizada
 */
function jsonResponse(array $data, int $code = 200): void {
    // Limpar QUALQUER output (warnings, notices, HTML, etc.)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Garantir que nenhum header HTML foi enviado
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/**
 * Formata tamanho de arquivo para exibição
 */
function formatSize(int $bytes): string {
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Retorna ícone baseado na extensão do arquivo
 */
function getFileIcon(string $extension): string {
    $icons = [
        'jpg'=>'bi-file-image','jpeg'=>'bi-file-image','png'=>'bi-file-image','gif'=>'bi-file-image',
        'webp'=>'bi-file-image','svg'=>'bi-file-image','bmp'=>'bi-file-image',
        'pdf'=>'bi-file-pdf','doc'=>'bi-file-word','docx'=>'bi-file-word',
        'xls'=>'bi-file-excel','xlsx'=>'bi-file-excel','csv'=>'bi-file-excel',
        'ppt'=>'bi-file-ppt','pptx'=>'bi-file-ppt',
        'txt'=>'bi-file-text','log'=>'bi-file-text','md'=>'bi-file-text',
        'html'=>'bi-file-code','css'=>'bi-file-code','js'=>'bi-file-code',
        'json'=>'bi-file-code','xml'=>'bi-file-code','yml'=>'bi-file-code','yaml'=>'bi-file-code',
        'zip'=>'bi-file-zip','rar'=>'bi-file-zip','7z'=>'bi-file-zip','tar'=>'bi-file-zip','gz'=>'bi-file-zip',
        'mp4'=>'bi-file-play','avi'=>'bi-file-play','mkv'=>'bi-file-play','mov'=>'bi-file-play','webm'=>'bi-file-play',
        'mp3'=>'bi-file-music','wav'=>'bi-file-music','ogg'=>'bi-file-music','flac'=>'bi-file-music',
        'php'=>'bi-file-code','py'=>'bi-file-code','java'=>'bi-file-code','c'=>'bi-file-code','cpp'=>'bi-file-code',
    ];
    return $icons[strtolower($extension)] ?? 'bi-file-earmark';
}

/**
 * Retorna cor baseada na extensão
 */
function getFileColor(string $extension): string {
    $colors = [
        'jpg'=>'#e74c3c','jpeg'=>'#e74c3c','png'=>'#e74c3c','gif'=>'#e67e22','webp'=>'#e74c3c','svg'=>'#9b59b6',
        'pdf'=>'#c0392b','doc'=>'#2980b9','docx'=>'#2980b9','xls'=>'#27ae60','xlsx'=>'#27ae60','csv'=>'#27ae60',
        'ppt'=>'#d35400','pptx'=>'#d35400',
        'txt'=>'#95a5a6','html'=>'#e67e22','css'=>'#3498db','js'=>'#f39c12','json'=>'#f39c12',
        'zip'=>'#8e44ad','mp4'=>'#e74c3c','mp3'=>'#1abc9c','md'=>'#7f8c8d',
    ];
    return $colors[strtolower($extension)] ?? '#6c757d';
}

/**
 * Verifica se é tipo visualizável
 */
function isPreviewable(string $ext): bool {
    $previewable = ['jpg','jpeg','png','gif','webp','svg','pdf','txt','html','css','js','json','xml','csv','md',
                     'log','mp4','webm','mp3','wav','ogg','doc','docx','xls','xlsx'];
    return in_array(strtolower($ext), $previewable);
}

/**
 * Verifica se é editável no editor de código
 */
function isEditable(string $ext): bool {
    return in_array(strtolower($ext), ['txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml','svg','htaccess','env']);
}

/**
 * Retorna o modo do editor baseado na extensão
 */
function getEditorMode(string $ext): string {
    $modes = [
        'html'=>'html','css'=>'css','js'=>'javascript','json'=>'json','xml'=>'xml',
        'md'=>'markdown','yaml'=>'yaml','yml'=>'yaml','svg'=>'xml',
        'csv'=>'plaintext','txt'=>'plaintext','log'=>'plaintext','ini'=>'ini','env'=>'plaintext'
    ];
    return $modes[strtolower($ext)] ?? 'plaintext';
}

/**
 * Tempo relativo
 */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff / 60) . 'min atrás';
    if ($diff < 86400) return floor($diff / 3600) . 'h atrás';
    if ($diff < 2592000) return floor($diff / 86400) . 'd atrás';
    return date('d/m/Y', strtotime($datetime));
}

/**
 * Input seguro (anti-XSS)
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Obtém input POST/GET sanitizado
 */
function input(string $key, $default = null) {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_string($value)) {
        return trim($value);
    }
    return $value;
}
