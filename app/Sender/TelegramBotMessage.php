<?php namespace App\Sender;

use App\Account;
use App\ChatMessages\ChatMessage;
use App\Logger;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Send a formatted Telegram bot message based on the Database entries
 * @link https://help.nethunt.com/en/articles/6253243-how-to-make-an-api-call-to-the-telegram-channel
 * @link https://packagist.org/packages/longman/telegram-bot
 * @link https://api.telegram.org/bot<token>/getWebhookInfo
 * @link https://core.telegram.org/bots/api#update
 */
class TelegramBotMessage extends BaseSender
{
    private Telegram $telegram;

    public function __construct()
    {
        $this->telegram = new Telegram(TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME);
    }

    /**
     * Used as main entry point for the web public/index.php
     * It handles updates and responds to them when required
     *
     * @return void
     */
    public function handleUpdate(): void
    {
        try {
            $input = Request::getInput();
            $post = json_decode($input, true);
            Logger::info('Post: ' . print_r($post, true));

            $update = new Update($post, $this->telegram->getBotUsername());
            if ($update->getUpdateType() === 'callback_query') {
                $data = $update->getCallbackQuery()->getData();
                $lastChar = substr($data, -1);
                $id = rtrim($data, $lastChar);
                $before = $lastChar === 'B';

                try {
                    $chatMessages = ChatMessage::getSurroundingMessages(SENDER_ACCOUNT_DATABASE_ID, $id, $before);
                    $this->setContext($before);
                    $messageData = $this->convertChatMessagesToDM($chatMessages);
                    $this->send(TELEGRAM_CHAT_ID, $messageData);
                } catch (\Throwable $e) {
                    $this->send(TELEGRAM_CHAT_ID, ['text' => 'Er is iets fout gegaan, zorg dat je aanvraag op de juiste manier gedaan wordt.', 'ids' => []]);
                    Logger::error($e);
                }
            } elseif ($update->getUpdateType() === 'channel_post') {
                $text = $update->getChannelPost()->getText();
                if (str_starts_with($text, TELEGRAM_BOT_NAME)) {
                    $text = trim(str_replace(TELEGRAM_BOT_NAME, '', $text));
                    if (array_key_exists($text, TELEGRAM_PREDEFINED_ANSWERS)) {
                        $this->send(TELEGRAM_CHAT_ID, ['text' => TELEGRAM_PREDEFINED_ANSWERS[$text], 'ids' => []]);
                    }

                    if (str_starts_with($text, '/context #')) {
                        $contextContent = str_replace('/context #', '', $text);
                        list($id, $before) = explode(' ', $contextContent);
                        $before = $before === 'before';

                        try {
                            $chatMessages = ChatMessage::getSurroundingMessages(SENDER_ACCOUNT_DATABASE_ID, $id, $before);
                            $this->setContext($before);
                            $messageData = $this->convertChatMessagesToDM($chatMessages);
                            $this->send(TELEGRAM_CHAT_ID, $messageData);
                        } catch (\Throwable $e) {
                            $this->send(TELEGRAM_CHAT_ID, ['text' => 'Er is iets fout gegaan, zorg dat je aanvraag op de juiste manier gedaan wordt.', 'ids' => []]);
                            Logger::error($e);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::error($e);
        }
    }

    /**
     * @return ServerResponse
     * @throws TelegramException
     */
    public function registerWebhook(): ServerResponse
    {
        return $this->telegram->setWebhook(TELEGRAM_WEBHOOK_URL, [
            'allowed_updates' => ['channel_post', 'callback_query'],
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
     * @param array $messageData
     * @param Account|null $senderAccount
     * @return void
     */
    public function send(int $receiverId, array $messageData, Account $senderAccount = null): void
    {
        $inlineCallbacks = [];
        foreach ($messageData['ids'] as $id) {
            $inlineCallbacks[] = ['text' => "#{$id} Before", 'callback_data' => "{$id}B"];
            $inlineCallbacks[] = ['text' => "#{$id} After", 'callback_data' => "{$id}A"];
        }

        $inlineKeyboard = empty($inlineCallbacks) ? [] : new InlineKeyboard(...array_chunk($inlineCallbacks, 2));

        //Let's see if we can send a DM
        try {
            $messageParams = [
                'chat_id' => $receiverId,
                'text' => $messageData['text']
            ];

            if (!empty($inlineKeyboard)) {
                $messageParams['reply_markup'] = $inlineKeyboard;
            }
            Request::sendMessage($messageParams);
        } catch (TelegramException $e) {
            Logger::error($e);
        }
    }
}
