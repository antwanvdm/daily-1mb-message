<?php namespace App;

/**
 * Class Database
 * @package App
 */
class Database
{
    private static ?\PDO $instance = null;
    protected \PDO $connection;

    private string $host = DB_HOST;
    private string $username = DB_USER;
    private string $password = DB_PASS;
    private string $database = DB_NAME;

    /**
     * Database constructor. (private to make the Singleton pattern active)
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * @return \PDO
     */
    public static function getInstance(): \PDO
    {
        if (self::$instance === null) {
            self::$instance = (new Database())->getConnection();
        }

        return self::$instance;
    }

    /**
     * Retrieve a new PDO instance to communicate with the DB
     */
    private function connect(): void
    {
        try {
            $this->connection = new \PDO("mysql:dbname=$this->database;host=$this->host", $this->username, $this->password);
            $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            //throw new \Exception("DB Connection failed: " . $e->getMessage());
        }
    }

    /**
     * @return \PDO
     */
    public function getConnection(): \PDO
    {
        return $this->connection;
    }
}
