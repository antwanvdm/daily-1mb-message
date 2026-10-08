<?php

namespace App;

use App\ChatMessages\ChatMessage;
use App\ChatMessages\Messenger;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

final class Game
{
    private const STATE_FILE = __DIR__ . '/../game-state.json';

    public function menu(): array
    {
        return [
            'text' => "🎮 Spel\nKies een spel:",
            'reply_markup' => new InlineKeyboard(
                [
                    ['text' => 'Jaar', 'callback_data' => 'game:start:year'],
                    ['text' => 'Wie', 'callback_data' => 'game:start:who'],
                ],
                [
                    ['text' => 'Score', 'callback_data' => 'game:score'],
                    ['text' => 'Stop', 'callback_data' => 'game:stop'],
                ],
            ),
        ];
    }

    public function handleCallback(string $data, ?object $from, ?object $callbackMessage = null): bool
    {
        if (!str_starts_with($data, 'game:')) {
            return false;
        }

        $player = $this->playerFromCallback($from, $callbackMessage);
        if ($player === null) {
            $this->send('Deze knop is alleen voor de twee spelers die in de configuratie staan.');
            return true;
        }

        $state = $this->readState();
        $action = substr($data, 5);
        if ($action === 'score') {
            $this->send($this->scoreText($state), $this->menu()['reply_markup']);
            return true;
        }
        if ($action === 'stop') {
            $state['active'] = null;
            $this->writeState($state);
            $this->send('Het actieve spel is gestopt. Er zijn geen punten toegekend.');
            return true;
        }
        if (str_starts_with($action, 'start:')) {
            $type = substr($action, 6);
            if (!in_array($type, ['year', 'who'], true)) {
                return true;
            }
            if ($state['active'] !== null) {
                $this->send('Er is al een actieve ronde. Kies eerst Stop.');
                return true;
            }
            try {
                $state['active'] = $this->newRound($type);
                $this->writeState($state);
                $this->send($state['active']['question'], $this->answerKeyboard($state['active']));
            } catch (\Throwable $e) {
                Logger::error($e);
                $this->send('Er kon geen spelronde uit het archief worden geladen.');
            }
            return true;
        }
        if (str_starts_with($action, 'answer:')) {
            $answerIndex = filter_var(substr($action, 7), FILTER_VALIDATE_INT);
            $active = $state['active'];
            if (!is_array($active) || $answerIndex === false || !isset($active['options'][$answerIndex])) {
                $this->send('Er is geen actieve ronde.');
                return true;
            }
            $answer = $active['options'][$answerIndex];
            if (isset($active['answers'][$player])) {
                $this->send('Je antwoord voor deze ronde is al ontvangen.');
                return true;
            }
            $active['answers'][$player] = $answer;
            if (count($active['answers']) < 2) {
                $state['active'] = $active;
                $this->writeState($state);
                $this->send('Antwoord ontvangen. De ronde wacht nog op de andere speler.');
                return true;
            }
            $results = [];
            foreach ($active['answers'] as $answeringPlayer => $submitted) {
                $correct = $submitted === $active['answer'];
                if ($correct) {
                    $state['scores'][$answeringPlayer]++;
                }
                $results[] = $answeringPlayer . ': ' . ($correct ? 'goed (+1)' : 'fout (+0)');
            }
            $state['active'] = null;
            $this->writeState($state);
            $this->send("Beide antwoorden zijn ontvangen.\n\nJuiste antwoord: {$active['answer']}\n" . implode("\n", $results) . "\n\n" . $this->scoreText($state));
            return true;
        }

        return true;
    }

    private function newRound(string $type): array
    {
        $message = ChatMessage::getRandomByAccountId(SENDER_ACCOUNT_DATABASE_ID, 1)[0] ?? null;
        if (!$message instanceof ChatMessage) {
            throw new \RuntimeException('No archive message found');
        }
        $year = (int) date('Y', strtotime($message->date));
        $options = $type === 'year'
            ? [(string) ($year - 2), (string) ($year - 1), (string) $year, (string) ($year + 1)]
            : [PERSONAL_NAME, SENDER_NAME, 'Groepschat'];
        shuffle($options);
        $answer = $type === 'year'
            ? (string) $year
            : match ($message->messenger) {
                Messenger::Self => PERSONAL_NAME,
                Messenger::Sender => SENDER_NAME,
                default => 'Groepschat',
            };
        return [
            'type' => $type,
            'question' => "🎮 Kies het juiste antwoord voor dit archiefbericht:\n\n{$message->message}",
            'options' => $options,
            'answer' => $answer,
            'answers' => [],
        ];
    }

    private function answerKeyboard(array $active): InlineKeyboard
    {
        return new InlineKeyboard(...array_chunk(array_map(
            static fn (string $option, int $index): array => ['text' => $option, 'callback_data' => 'game:answer:' . $index],
            $active['options'],
            array_keys($active['options']),
        ), 2));
    }

    private function playerFromCallback(?object $from, ?object $message): ?string
    {
        $identity = '';
        if ($from !== null) {
            $identity = trim(implode(' ', array_filter([
                $from->getFirstName(), $from->getLastName(), $from->getUsername(),
            ])));
        }
        if (stripos($identity, PERSONAL_NAME) !== false) {
            return PERSONAL_NAME;
        }
        if (stripos($identity, SENDER_NAME) !== false) {
            return SENDER_NAME;
        }
        $signature = $message !== null && method_exists($message, 'getAuthorSignature')
            ? ($message->getAuthorSignature() ?? '')
            : '';
        if (str_contains($signature, PERSONAL_NAME)) {
            return PERSONAL_NAME;
        }
        if (str_contains($signature, SENDER_NAME)) {
            return SENDER_NAME;
        }
        return null;
    }

    private function readState(): array
    {
        $default = ['scores' => [PERSONAL_NAME => 0, SENDER_NAME => 0], 'active' => null];
        if (!is_file(self::STATE_FILE)) {
            return $default;
        }
        $state = json_decode((string) file_get_contents(self::STATE_FILE), true);
        return is_array($state) ? array_replace_recursive($default, $state) : $default;
    }

    private function writeState(array $state): void
    {
        $directory = dirname(self::STATE_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $temporary = $directory . '/game-state.json.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporary, self::STATE_FILE);
    }

    private function scoreText(array $state): string
    {
        return "🏆 Score\n" . PERSONAL_NAME . ': ' . $state['scores'][PERSONAL_NAME] . "\n" . SENDER_NAME . ': ' . $state['scores'][SENDER_NAME];
    }

    private function send(string $text, ?InlineKeyboard $keyboard = null): void
    {
        $params = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $text];
        if ($keyboard !== null) {
            $params['reply_markup'] = $keyboard;
        }
        Request::sendMessage($params);
    }
}
