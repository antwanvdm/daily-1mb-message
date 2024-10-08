<?php

namespace App\ChatMessages;

use App\Database;
use App\DataEncryption;
use PDO;

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
     * @var SpecialStatus
     */
    public SpecialStatus $special_status;

    /**
     * Used to later check if we need before/after logic
     *
     * @var bool
     */
    public static bool $randomOrder = true;

    /**
     * @param int|null $id
     * @param int|null $account_id
     * @param string $date
     * @param string $time
     * @param int $messenger
     * @param string $message
     * @param int $special_status
     */
    public function __construct(
        public ?int $id = null,
        public ?int $account_id = null,
        public string $date = '',
        public string $time = '',
        int $messenger = 3,
        public string $message = '',
        int $special_status = 0,
    )
    {
        //Required because constructor can't understand this with PDO (there is no auto enum conversation)
        $this->messenger = Messenger::from($messenger);
        $this->special_status = SpecialStatus::from($special_status);
    }

    /**
     * @param int $accountId
     * @return false|string
     */
    public function save(int $accountId): string|bool
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "INSERT INTO messages
                        (`account_id`, `date`, `time`, `messenger`, `message`, `special_status`) 
                        VALUES (:account_id, :date, :time, :messenger, :message, :special_status)"
        );
        $statement->execute([
            ':account_id' => $accountId,
            ':date' => $this->date,
            ':time' => $this->time,
            ':messenger' => $this->messenger->value,
            ':message' => ENCRYPTION_ENABLED ? DataEncryption::encrypt($this->message) : $this->message,
            ':special_status' => $this->special_status->value,
        ]);
        return $db->lastInsertId();
    }

    /**
     * @param int $accountId
     * @param int $messageId
     * @param bool $before If false, you get the messages later than the given date
     * @param int $amount
     * @return ChatMessage[]
     * @throws \Exception
     */
    public static function getSurroundingMessages(int $accountId, int $messageId, bool $before = true, int $amount = 5): array
    {
        self::$randomOrder = false;
        $db = Database::getInstance();

        $statement = $db->prepare(
            "SELECT TIMESTAMP(`date`, `time`) FROM messages WHERE `id` = :id AND `account_id` = :account_id LIMIT 1"
        );
        $statement->bindParam('id', $messageId, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        $timestamp = $statement->fetchColumn();

        if ($timestamp === false) {
            throw new \Exception('Zonder geldig ID kan ik ook geen context geven helaas..');
        }

        $statement = $db->prepare(
            $before
                ? "SELECT * FROM messages WHERE `account_id` = :account_id AND TIMESTAMP(`date`, `time`) < :timestamp ORDER BY `date` DESC, `time` DESC LIMIT :limit"
                : "SELECT * FROM messages WHERE `account_id` = :account_id AND TIMESTAMP(`date`, `time`) > :timestamp ORDER BY `date`, `time` LIMIT :limit"
        );
        $statement->bindParam('timestamp', $timestamp);
        $statement->bindParam('limit', $amount, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        $messages = $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\ChatMessages\\ChatMessage::buildFromPDO');
        return $before ? array_reverse($messages) : $messages;
    }

    /**
     * @param int $accountId
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getByAccountId(int $accountId, int $amount = 5): array
    {
        return match ((int)date('N')) {
            2 => self::getSpecialStatusByAccountId($accountId, SpecialStatus::Tuesday->value, $amount),
            3 => self::getSpecialStatusByAccountId($accountId, SpecialStatus::Wednesday->value, $amount),
            4 => self::getThrowbackThursdayByAccountId($accountId, $amount),
            5 => self::getSpecialStatusByAccountId($accountId, SpecialStatus::Friday->value, $amount),
            7 => self::getContextSundayByAccountId($accountId),
            default => self::getRandomByAccountId($accountId, $amount),
        };
    }

    /**
     * @param int $accountId
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getRandomByAccountId(int $accountId, int $amount = 5): array
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "SELECT * FROM messages WHERE `account_id` = :account_id ORDER BY RAND() LIMIT :limit"
        );
        $statement->bindParam('limit', $amount, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\ChatMessages\\ChatMessage::buildFromPDO');
    }

    /**
     * @param int $accountId
     * @param int $specialStatus
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getSpecialStatusByAccountId(int $accountId, int $specialStatus, int $amount = 5): array
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "SELECT * FROM messages WHERE `account_id` = :account_id AND `special_status` = :special_status ORDER BY RAND() LIMIT :limit"
        );
        $statement->bindParam('limit', $amount, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->bindParam('special_status', $specialStatus, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\ChatMessages\\ChatMessage::buildFromPDO');
    }

    /**
     * @param int $accountId
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getThrowbackThursdayByAccountId(int $accountId, int $amount = 5): array
    {
        $db = Database::getInstance();
        $statement = $db->prepare(
            "SELECT * FROM messages WHERE `account_id` = :account_id AND WEEKDAY(`date`) = 3 ORDER BY RAND() LIMIT :limit"
        );
        $statement->bindParam('limit', $amount, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\ChatMessages\\ChatMessage::buildFromPDO');
    }

    /**
     * @param int $accountId
     * @param int $amount
     * @return ChatMessage[]
     */
    public static function getContextSundayByAccountId(int $accountId, int $amount = 10): array
    {
        self::$randomOrder = false;
        $db = Database::getInstance();

        $statement = $db->prepare(
            "SELECT TIMESTAMP(`date`, `time`) FROM messages WHERE `account_id` = :account_id ORDER BY RAND() LIMIT 1"
        );
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        $timestamp = $statement->fetchColumn();

        $statement = $db->prepare(
            "SELECT * FROM messages WHERE `account_id` = :account_id AND TIMESTAMP(`date`, `time`) >= :timestamp ORDER BY `date`, `time` LIMIT :limit"
        );
        $statement->bindParam('timestamp', $timestamp);
        $statement->bindParam('limit', $amount, PDO::PARAM_INT);
        $statement->bindParam('account_id', $accountId, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_FUNC, '\\App\\ChatMessages\\ChatMessage::buildFromPDO');
    }

    /**
     * @param int $id
     * @param int $account_id
     * @param string $date
     * @param string $time
     * @param int $messenger
     * @param string $message
     * @param int $special_status
     * @return ChatMessage
     */
    public static function buildFromPDO(
        int $id,
        int $account_id,
        string $date,
        string $time,
        int $messenger,
        string $message,
        int $special_status
    ): ChatMessage
    {
        $message = ENCRYPTION_ENABLED ? DataEncryption::decrypt($message) : $message;
        return new self($id, $account_id, $date, $time, $messenger, $message, $special_status);
    }
}
