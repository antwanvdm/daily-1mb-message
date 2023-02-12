<?php

namespace App;

use PDO;

/**
 * Representing 'Model' for accounts table
 */
class Account
{
    /**
     * @param int|null $id
     * @param string $email
     * @param string|null $twitter_screen_name
     * @param string|null $twitter_access_token
     */
    public function __construct(
        public ?int $id = null,
        public string $email = '',
        public ?string $twitter_screen_name = '',
        public ?string $twitter_access_token = ''
    ) {
    }

    /**
     * @return false|string
     */
    public function save(): int|bool
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "INSERT INTO accounts
                        (`email`, `twitter_screen_name`, `twitter_access_token`)
                        VALUES (:email, :twitter_screen_name, :twitter_access_token)"
        );
        $statement->execute([
            ':email' => ENCRYPTION_ENABLED ? DataEncryption::encrypt($this->email) : $this->email,
            ':twitter_screen_name' => $this->twitter_screen_name,
            ':twitter_access_token' => $this->twitter_access_token
        ]);
        return $db->lastInsertId();
    }

    /**
     * @param int $id
     * @return Account
     */
    public static function getById(int $id): Account
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "SELECT * FROM accounts WHERE `id` = :id"
        );
        $statement->execute([':id' => $id]);
        return $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\Account::buildFromPDO')[0];
    }

    /**
     * @param int $id
     * @param string $email
     * @param string $twitter_screen_name
     * @param string $twitter_access_token
     * @return Account
     */
    public static function buildFromPDO(
        int $id,
        string $email,
        string $twitter_screen_name,
        string $twitter_access_token
    ): Account {
        $email = ENCRYPTION_ENABLED ? DataEncryption::decrypt($email) : $email;
        return new self($id, $email, $twitter_screen_name, $twitter_access_token);
    }
}