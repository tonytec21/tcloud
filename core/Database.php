<?php
/**
 * TCloud - Conexão com Banco de Dados (Singleton PDO)
 */

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
                );
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]);
            } catch (PDOException $e) {
                if (APP_DEBUG) {
                    die('Erro de conexão: ' . $e->getMessage());
                }
                die('Erro interno do servidor.');
            }
        }
        return self::$instance;
    }

    // Previne clonagem e instanciação direta
    private function __construct() {}
    private function __clone() {}
}
