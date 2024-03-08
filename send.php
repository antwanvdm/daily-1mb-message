<?php

require_once 'settings.php';
require_once 'vendor/autoload.php';

global $argv;
$platform = $argv[1] ?? null;
if ($platform === null) {
    die('Choose a platform to send the message');
}

//Receive messages from DB
$chatMessages = \App\ChatMessages\ChatMessage::getByAccountId(SENDER_ACCOUNT_DATABASE_ID);

switch ($platform) {
    case 'twitter':
        $twitterDM = new \App\Sender\TwitterDM();
        $messageData = $twitterDM->convertChatMessagesToDM($chatMessages);
        $twitterDM->send(SENDER_TWITTER_ID, $messageData, \App\Account::getById(1));
        break;

    case 'telegram':
        $telegramBotMessage = new \App\Sender\TelegramBotMessage();
        $messageData = $telegramBotMessage->convertChatMessagesToDM($chatMessages);
        $telegramBotMessage->send(TELEGRAM_CHAT_ID, $messageData);
        break;

    default:
        die("Choose a supported platform please, $platform is not supported");
}
