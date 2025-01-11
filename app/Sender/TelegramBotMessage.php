<?php namespace App\Sender;

use App\Account;
use App\ChatMessages\ChatMessage;
use App\Logger;
use Google\ApiCore\ApiException;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\Client\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\SynthesizeSpeechRequest;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
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
    const VECTOR_ERROR_MESSAGE = 'Er ging even iets mis met het stellen van de vraag, probeer het later opnieuw!';

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

                    if (str_starts_with($text, '/question')) {
                        $question = str_replace('/question ', '', $text);
                        $answer = $this->askVectorStore($question);
                        $this->sendCustomMessage(TELEGRAM_CHAT_ID, $answer);
                    }

                    if (str_starts_with($text, '/voice')) {
                        $question = str_replace('/voice ', '', $text);
                        $answer = $this->askVectorStore($question);
                        $this->sendCustomMessage(TELEGRAM_CHAT_ID, $answer, 'voice');
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::error($e);
        }
    }

    /**
     * @param $question
     * @return string|null
     */
    public function askVectorStore($question): ?string
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', VECTOR_STORE_CHAT_URL . urlencode($question), [
                'headers' => [
                    'Accept' => 'application/json',
                ]
            ]);
            return json_decode($response->getBody()->getContents())->answer;
        } catch (\Throwable $e) {
            Logger::error($e);
            return self::VECTOR_ERROR_MESSAGE;
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

        if (ChatMessage::$randomOrder === false) {
            $inlineCallbacks = [$inlineCallbacks[0], $inlineCallbacks[count($inlineCallbacks) - 1]];
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

    /**
     * @param int $receiverId
     * @param string $text
     * @param string $type
     * @return void
     * @throws ApiException
     * @link https://cloud.google.com/text-to-speech/docs/voices
     */
    public function sendCustomMessage(int $receiverId, string $text, string $type = 'text'): void
    {
        try {
            if ($type === 'text' || $text === self::VECTOR_ERROR_MESSAGE) {
                $messageParams = [
                    'chat_id' => $receiverId,
                    'text' => $text
                ];
                Request::sendMessage($messageParams);
            } else {
                $credentialsPath = __DIR__ . '/../../google-keys.json';
                putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
                $client = new TextToSpeechClient();

                // Set up the SynthesisInput object
                $synthesisInput = new SynthesisInput();
                $synthesisInput->setText($text);

                $voices = ['nl-NL-Standard-A', 'nl-NL-Standard-B', 'nl-NL-Standard-C', 'nl-NL-Standard-D', 'nl-NL-Standard-E', 'nl-NL-Standard-F', 'nl-NL-Standard-G', 'nl-NL-Wavenet-A', 'nl-NL-Wavenet-B', 'nl-NL-Wavenet-C', 'nl-NL-Wavenet-D', 'nl-NL-Wavenet-E'];
                $voice = new VoiceSelectionParams();
                $voice->setLanguageCode('nl-NL');
                $voice->setName($voices[array_rand($voices)]);

                $audioConfig = new AudioConfig();
                $audioConfig->setAudioEncoding(AudioEncoding::OGG_OPUS);

                $request = new SynthesizeSpeechRequest();
                $request->setInput($synthesisInput);
                $request->setVoice($voice);
                $request->setAudioConfig($audioConfig);

                $response = $client->synthesizeSpeech($request);

                $messageFileLocation = __DIR__ . '/../../_message.ogg';
                file_put_contents($messageFileLocation, $response->getAudioContent());

                $messageParams = [
                    'chat_id' => $receiverId,
                    'voice' => $messageFileLocation
                ];
                Request::sendVoice($messageParams);
            }
        } catch (TelegramException $e) {
            Logger::error($e);
        }
    }
}
