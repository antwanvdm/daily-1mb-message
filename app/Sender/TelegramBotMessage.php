<?php namespace App\Sender;

use App\Account;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Send a formatted Telegram bot message based on the Database entries
 * @link https://help.nethunt.com/en/articles/6253243-how-to-make-an-api-call-to-the-telegram-channel
 * @link https://packagist.org/packages/longman/telegram-bot
 */
class TelegramBotMessage extends BaseSender
{
    private Telegram $telegram;

    public function __construct()
    {
        $this->telegram = new Telegram(TELEGRAM_BOT_TOKEN, 'daily-1mb-message');
    }

    public function handleUpdate(): void
    {
        try {
            $input = Request::getInput();
            $post = json_decode($input, true);
            \App\Logger::info('Token: ' . print_r($post, true));

            $update = new Update($post, $this->telegram->getBotUsername());
            if ($update->getMessage()->getText() === 'test') {
                $this->send(TELEGRAM_CHAT_ID, "Wow, je hebt een test gestuurd. Bij deze mijn ontvangstbevestiging!");
            }
        } catch (\Throwable $e) {
            echo $e->getMessage();
        }
    }

    /**
     * @return ServerResponse
     * @throws TelegramException
     */
    public function registerWebhook(): ServerResponse
    {
        return $this->telegram->setWebhook('https://daily-1mb-message.antwan.eu/', [
            'allowed_updates' => ['message'],
            'secret_token' => TELEGRAM_SECRET_HEADER
        ]);
    }

    /**
     * @return void
     * @throws TelegramException
     */
    public function deleteWebhook(): void
    {
        $this->telegram->deleteWebhook();
    }

    /**
     * @param int $receiverId
     * @param string $message
     * @param Account|null $senderAccount
     * @return void
     */
    public function send(int $receiverId, string $message, Account $senderAccount = null): void
    {
        //Let's see if we can send a DM
        try {
            Request::sendMessage([
                'chat_id' => $receiverId,
                'text' => $message,
            ]);
        } catch (TelegramException $e) {
            // Handle error case
        }
    }
}
