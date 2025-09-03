<?php
require_once 'settings.php';
require_once 'vendor/autoload.php';

global $argv;
$message = $argv[1] ?? null;
if ($message === null) {
    die('Write a message to send');
}

$telegramBot = new \App\Sender\TelegramBotMessage();
$chatResponse = \App\DTO\VectorResponse::fromArray(['answer' => $message, 'image' => null]);
$telegramBot->sendCustomMessage(TELEGRAM_CHAT_ID, $chatResponse);
