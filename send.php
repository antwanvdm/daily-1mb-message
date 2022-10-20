<?php

require_once 'settings.php';
require_once 'vendor/autoload.php';

//Receive data from DB
$chatMessages = \App\ChatMessage::getRandomByAccountId(SENDER_ACCOUNT_DATABASE_ID);
$twitterAccessToken = \App\Account::getTwitterAccessTokenById(1);

//Let's send message on Twitter!
$twitterDMSender = new \App\TwitterDMSender();
$twitterMessage = $twitterDMSender->convertChatMessagesToTwitterMessage($chatMessages);
$twitterDMSender->send(SENDER_TWITTER_ID, $twitterMessage, $twitterAccessToken);
