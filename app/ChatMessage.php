<?php

namespace App;

/**
 * Representing 'Model' for messages table
 */
class ChatMessage
{
    /**
     * @var Messenger
     */
    public Messenger $messenger;

    /**
     * @param int|null $id
     * @param int|null $account_id
     * @param string $date
     * @param string $time
     * @param int $messenger
     * @param string $message
     */
    public function __construct(
        public ?int $id = null,
        public ?int $account_id = null,
        public string $date = '',
        public string $time = '',
        int $messenger = 3,
        public string $message = ''
    ) {
        //Required because constructor can't understand this with PDO (there is no auto enum conversation)
        $this->messenger = Messenger::from($messenger);
    }

    /**
     * @param int $accountId
     * @return false|string
     */
    public function save(int $accountId): string|bool
    {
        $db = \App\Database::getInstance();
        $statement = $db->prepare(
            "INSERT INTO messages
                        (`account_id`, `date`, `time`, `messenger`, `message`) 
                        VALUES (:account_id, :date, :time, :messenger, :message)"
        );
        $statement->execute([
            ':account_id' => $accountId,
            ':date' => $this->date,
            ':time' => $this->time,
            ':messenger' => $this->messenger->value,
            ':message' => ENCRYPTION_ENABLED ? DataEncryption::encrypt($this->message) : $this->message,
        ]);
        return $db->lastInsertId();
    }

    /**
     * @param int $accountId
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getRandomByAccountId(int $accountId, int $amount = 5): array
    {
        $db = \App\Database::getInstance();
        $statement = $db->prepare(
            "SELECT * FROM messages WHERE `account_id` = :account_id ORDER BY RAND() LIMIT :limit"
        );
        $statement->bindParam('limit', $amount, \PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(\PDO::FETCH_FUNC, '\\App\\ChatMessage::buildFromPDO');
    }

    /**
     * @param int $id
     * @param int $account_id
     * @param string $date
     * @param string $time
     * @param int $messenger
     * @param string $message
     * @return ChatMessage
     */
    public static function buildFromPDO(
        int $id,
        int $account_id,
        string $date,
        string $time,
        int $messenger,
        string $message
    ): ChatMessage {
        $message = ENCRYPTION_ENABLED ? DataEncryption::decrypt($message) : $message;
        return new self($id, $account_id, $date, $time, $messenger, $message);
    }
}
