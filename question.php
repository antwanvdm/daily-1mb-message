<?php
require_once 'settings.php';
require_once 'vendor/autoload.php';

global $argv;
$question = $argv[1] ?? null;
if ($question === null) {
    die('Ask a question to send');
}

$telegramBot = new \App\Sender\TelegramBotMessage();
echo $telegramBot->askVectorStore($question);
