<?php namespace App\Sender;

use App\Account;
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
    /**
     * @param int $receiverId
     * @param string $message
     * @param Account $senderAccount
     * @return void
     */
    public function send(int $receiverId, string $message, Account $senderAccount): void
    {
        //Let's see if we can send a DM
        try {
            $telegram = new Telegram(TELEGRAM_BOT_TOKEN, 'daily-1mb-message');
            Request::sendMessage([
                'chat_id' => $receiverId,
                'text' => $message,
            ]);
        } catch (TelegramException $e) {
            // Handle error case
        }
    }
}
