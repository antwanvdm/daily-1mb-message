<?php
require_once '../settings.php';
require_once '../vendor/autoload.php';

\App\Logger::info('Token: ' . $_SERVER['X-Telegram-Bot-Api-Secret-Token']);

if (!isset($_SERVER['X-Telegram-Bot-Api-Secret-Token']) || $_SERVER['X-Telegram-Bot-Api-Secret-Token'] !== TELEGRAM_SECRET_HEADER) {
    die("Nothing much to see here");
}

$telegramBot = new \App\Sender\TelegramBotMessage();
$telegramBot->handleUpdate();
