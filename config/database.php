<?php
/**
 * Database configuration and PDO connection
 * Supports: PostgreSQL, MySQL/MariaDB, SQLite
 */

// Database credentials
define('DB_TYPE', 'sqlite');        // Options: 'pgsql', 'mysql', 'sqlite'
define('DB_HOST', 'localhost');
define('DB_NAME', 'maintenance_tracker');
define('DB_USER', 'maintenance_user');
define('DB_PASS', 'your_password');

// SQLite database file path (only used when DB_TYPE is 'sqlite')
define('DB_SQLITE_FILE', __DIR__ . '/../maintenance_tracker.db');

class Database {
    private $dbtype = DB_TYPE;
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $sqlite_file = DB_SQLITE_FILE;
    private $conn;

    /**
     * Get database connection
     * @return PDO|null
     */
    public function getConnection() {
        $this->conn = null;

        try {
            // Build DSN based on database type
            if ($this->dbtype === 'sqlite') {
                // SQLite: use file path
                $dsn = 'sqlite:' . $this->sqlite_file;
                $this->conn = new PDO(
                    $dsn,
                    null,
                    null,
                    array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    )
                );

                // CRITICAL: Enable foreign keys for SQLite
                $this->conn->exec('PRAGMA foreign_keys = ON');

            } else {
                // PostgreSQL or MySQL: use host/database/user/password
                $dsn = $this->dbtype . ':host=' . $this->host . ';dbname=' . $this->db_name;
                $this->conn = new PDO(
                    $dsn,
                    $this->username,
                    $this->password,
                    array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    )
                );

                // Set character encoding
                if ($this->dbtype === 'mysql') {
                    $this->conn->exec("SET NAMES utf8mb4");
                } else if ($this->dbtype === 'pgsql') {
                    $this->conn->exec("SET CLIENT_ENCODING TO 'UTF8'");
                }
            }

        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }

        return $this->conn;
    }
}
?>
