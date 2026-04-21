<?php
/**
 * TCloud - Sistema de Auditoria
 */

class AuditLog {
    /**
     * Registra uma ação no log de auditoria
     */
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $entityName = null,
        ?array $details = null
    ): void {
        try {
            $db = Database::getInstance();
            $userId = $_SESSION['user_id'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, entity_name, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $action, $entityType, $entityId, $entityName,
                $details ? json_encode($details) : null, $ip, $ua
            ]);
        } catch (\Exception $e) {
            // Falha silenciosa no log - não deve interromper a operação principal
            error_log("AuditLog error: " . $e->getMessage());
        }
    }

    /**
     * Busca logs com filtros
     */
    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array {
        $db = Database::getInstance();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = '(al.entity_name LIKE ? OR al.action LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        
        // Total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Dados
        $limit = max(1, min(500, (int)$limit));
        $offset = max(0, (int)$offset);
        $stmt = $db->prepare("
            SELECT al.*, u.username, u.full_name 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            WHERE {$whereStr}
            ORDER BY al.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    }
}
