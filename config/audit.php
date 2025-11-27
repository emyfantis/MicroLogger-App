<?php
/**
 * Audit Trail System
 * 
 * Provides logging capabilities for CREATE, UPDATE, DELETE
 * operations on database records. Stores old/new values,
 * user information, IP address and browser details.
 */

class Audit {
    private PDO $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Generic audit logger
     *
     * @param string      $action     Action type (CREATE, UPDATE, DELETE)
     * @param string      $tableName  The table name being affected
     * @param int|null    $recordId   Record primary key
     * @param array|null  $oldValues  Old values before change
     * @param array|null  $newValues  New values after change
     */
    public function log(
        string $action,
        string $tableName,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $sql = "INSERT INTO audit_logs 
                    (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
                    VALUES 
                    (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':user_id'     => $_SESSION['user_id'] ?? 0,
                ':action'      => $action,
                ':table_name'  => $tableName,
                ':record_id'   => $recordId,
                ':old_values'  => $oldValues ? json_encode($oldValues) : null,
                ':new_values'  => $newValues ? json_encode($newValues) : null,
                ':ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ':user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);
        } catch (Exception $e) {
            error_log('[AUDIT] Failed to log: ' . $e->getMessage());
        }
    }
    
    /**
     * Log creation of a record
     */
    public function logCreate(string $tableName, int $recordId, array $data): void {
        $this->log('CREATE', $tableName, $recordId, null, $data);
    }
    
    /**
     * Log update of a record
     */
    public function logUpdate(string $tableName, int $recordId, array $oldData, array $newData): void {
        $this->log('UPDATE', $tableName, $recordId, $oldData, $newData);
    }
    
    /**
     * Log deletion of a record
     */
    public function logDelete(string $tableName, int $recordId, array $data): void {
        $this->log('DELETE', $tableName, $recordId, $data, null);
    }
    
    /**
     * Retrieve audit history for a specific record
     */
    public function getHistory(string $tableName, int $recordId, int $limit = 50): array {
        $sql = "SELECT a.*, u.name as user_name
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.table_name = :table_name 
                  AND a.record_id = :record_id
                ORDER BY a.created_at DESC
                LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':table_name', $tableName);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
