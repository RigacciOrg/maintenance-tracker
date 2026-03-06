<?php
/**
 * Database configuration and PDO connection
 */

// Database credentials
define('DB_TYPE', 'pgsql');
define('DB_HOST', 'localhost');
define('DB_NAME', 'maintenance_tracker');
define('DB_USER', 'maintenance_user');
define('DB_PASS', 'your_password');

class Database {
    private $dbtype = DB_TYPE;
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn;

    /**
     * Get database connection
     * @return PDO|null
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                $this->dbtype . ":host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                )
            );
            if ($this->dbtype == "mysql") {
                $this->conn->exec("SET NAMES utf8");
            } else if ($this->dbtype == "pgsql") {
                $this->conn->exec("SET CLIENT_ENCODING TO 'UTF8'");
            }
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }

        return $this->conn;
    }
}
?>
