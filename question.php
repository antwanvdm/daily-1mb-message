<?php
require_once 'settings.php';
require_once 'vendor/autoload.php';

global $argv;
$question = $argv[1] ?? null;
$sendToTelegram = $argv[2] ?? false;
if ($question === null) {
    die('Ask a question to send');
}

$telegramBot = new \App\Sender\TelegramBotMessage();
$chatResponse = $telegramBot->askVectorStore($question);

if ($sendToTelegram) {
    $telegramBot->sendCustomMessage(TELEGRAM_CHAT_ID, $chatResponse);
}

echo $chatResponse->answer;
