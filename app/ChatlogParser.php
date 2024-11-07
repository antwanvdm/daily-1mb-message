<?php namespace App;

use App\ChatMessages\Messenger;
use App\ChatMessages\ChatMessage;
use App\ChatMessages\SpecialStatus;
use Carbon\Carbon;
use Exception;
use voku\helper\UTF8;

/**
 * Parse a messenger plus chatlog into an organised array
 */
class ChatlogParser
{
    /**
     * @var array|string[]
     */
    private array $possibleMyAccountNames = PERSONAL_POSSIBLE_CHAT_NAMES;

    /**
     * @var array|string[]
     */
    private array $skipStrings = [
        'auto-message',
        'auto-bericht',
        'a uto-bericht',
        'au to-bericht',
        'aut o-bericht',
        'auto -bericht',
        'auto- bericht',
        'auto-b ericht',
        'auto-be richt',
        'auto-ber icht',
        'auto-beri cht',
        'auto-beric ht',
        'auto-berich t',
        'AutoMessage',
        'heeft zijn\/haar status gewijzigd',
        'heeft zijn/haar status gewijzigd',
        'is nu Afwezig',
        'is nu Online',
        'is nu Bezet',
        'is nu Offline',
        'is nu Ben zo terug',
        'is nu Lunchpauze',
        'is nu Aan de telefoon',
        'zijn/haar naam gewijzigd',
    ];

    /**
     * @var array|string[]
     * @see https://stackoverflow.com/a/3373364
     */
    private array $specialCharacters = [
        'à' => 'a',
        'á' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'a',
        'å' => 'a',
        'ç' => 'c',
        'è' => 'e',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'ø' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'û' => 'u',
        'ý' => 'y',
        'ÿ' => 'y'
    ];

    /**
     * @var array|string[][]
     */
    private array $namesWithColon = CHAT_NAMES_WITH_COLONS_WITH_REPLACEMENTS;

    /**
     * @var array|string[]
     */
    private array $rawDataPerRow;

    /**
     * @var array|ChatMessage[][]
     */
    private array $chatMessages = [];

    /**
     * @param string $fileName
     */
    public function __construct(string $fileName)
    {
        $fileData = file_get_contents($fileName);
        $this->rawDataPerRow = explode(PHP_EOL, $this->convertChatToCleanString($fileData));

        $this->parseData();
        $this->setSpecialStatus();
        $this->removeAutoMessages();
    }

    /**
     * Serious pain to make sure every file works..
     *
     * @param $text
     * @return string
     */
    private function convertChatToCleanString($text): string
    {
        $convertedText = iconv(UTF8::str_detect_encoding($text), 'UTF-8//IGNORE', $text);
        $text = json_decode(UTF8::to_utf8_string(json_encode($convertedText)));
        if (!$text) {
            $text = $convertedText;
        }
        $bom = pack('H*', 'EFBBBF');
        return preg_replace("/^$bom/", '', $text);
    }

    /**
     * The actual magic to transform chat lines into multidimensional array based on dates
     *
     * @return void
     */
    private function parseData(): void
    {
        $lastSentenceKey = 0;
        $currentDate = '';
        $chatParticipants = -1;
        foreach ($this->rawDataPerRow as $key => $dataItem) {
            //If there is a new date snippet, let's make sure to create a new array entry
            if (str_starts_with($dataItem, '| Start van sessie') || str_starts_with($dataItem, '| Session Start')) {
                $dateString = trim(str_replace(['Start van sessie:', 'Session Start:', '|'], '', $dataItem));
                try {
                    $currentDate = Carbon::parse($dateString)->toDateString();
                } catch (Exception $e) {
                    $currentDate = Carbon::parseFromLocale($dateString, 'nl')->toDateString();
                }
                $this->chatMessages[$currentDate] = $this->chatMessages[$currentDate] ?? [];
                $chatParticipants = -1;
            } elseif (str_starts_with($dataItem, '|')) {
                $chatParticipants++;
            }

            //Add time based content to the current date in the array
            if (str_starts_with($dataItem, '[')) {
                //Replace the name based on config replace parameters...
                $replaceDataItem = str_replace($this->namesWithColon[0], $this->namesWithColon[1], $dataItem);

                //Strip the message into separate parts
                preg_match('/^\[([0-9:]*)\](.*?):(.*)/', $replaceDataItem, $matches);

                $chatMessage = new ChatMessage();
                $chatMessage->date = $currentDate;
                $chatMessage->time = $matches[1] ?? 0;
                $chatMessage->messenger = isset($matches[2]) ? $this->getMessenger($matches[2], $chatParticipants) : Messenger::Auto;
                $chatMessage->message = isset($matches[3]) ? trim($matches[3]) : $dataItem;
                $chatMessage->special_status = SpecialStatus::None;
                $this->chatMessages[$currentDate][$key] = $chatMessage;
                $lastSentenceKey = $key;
            }

            //Add new line content to the previous sentence (as the logs get 'new-lines' after a few characters)
            if (str_starts_with($dataItem, ' ') && trim($dataItem) !== '') {
                $this->chatMessages[$currentDate][$lastSentenceKey]->message .= (' ' . trim($dataItem));
            }
        }
    }

    /**
     * Decide if message is from personal account, sender, group or auto generated
     *
     * @param string $text
     * @param int $chatParticipants
     * @return Messenger
     */
    private function getMessenger(string $text, int $chatParticipants): Messenger
    {
        //Set the default to the sender, and try to find cased to change this
        $messenger = Messenger::Sender;

        if ($chatParticipants > 2) {
            $messenger = Messenger::Group;
        } else {
            //Check if the sentence is personal based on variations of personal name
            foreach ($this->possibleMyAccountNames as $possibleAccountName) {
                if (str_contains($text, $possibleAccountName)) {
                    $messenger = Messenger::Self;
                    break;
                }
            }
        }

        //Check if the sentence is an auto-message
        foreach ($this->skipStrings as $skipString) {
            if (str_contains($text, $skipString)) {
                $messenger = Messenger::Auto;
                break;
            }
        }
        return $messenger;
    }

    /**
     * Add special status if available per chat message
     *
     * @return void
     */
    private function setSpecialStatus(): void
    {
        foreach ($this->chatMessages as $date => $messages) {
            foreach ($messages as $key => $chatEntry) {
                $text = strtr($chatEntry->message, $this->specialCharacters);

                foreach (SPECIAL_STATUS_OPTIONS as $mode => $specialStrings) {
                    foreach ($specialStrings as $specialString) {
                        if (preg_match('/\b(' . $specialString . ')\b/i', $text)) {
                            $this->chatMessages[$date][$key]->special_status = SpecialStatus::from($mode);
                        }
                    }
                }
            }
        }
    }

    /**
     * Remove all crappy auto messages & status updates
     *
     * @return void
     */
    private function removeAutoMessages(): void
    {
        foreach ($this->chatMessages as $date => $messages) {
            foreach ($messages as $key => $chatEntry) {
                //Remove if the messenger was already identified as auto
                if ($chatEntry->messenger === Messenger::Auto) {
                    unset($this->chatMessages[$date][$key]);
                    continue;
                }

                //If not, let's also check the message itself to remove auto generated content
                foreach ($this->skipStrings as $skipString) {
                    if (str_contains($chatEntry->message, $skipString)) {
                        unset($this->chatMessages[$date][$key]);
                        continue 2;
                    }
                }
            }
        }
    }

    /**
     * @return array|ChatMessage[][]
     */
    public function getChatMessages(): array
    {
        return $this->chatMessages;
    }
}