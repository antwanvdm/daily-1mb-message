<?php

namespace App;

use App\ChatMessages\ChatMessage;
use App\ChatMessages\Messenger;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

final class Game
{
    private const STATE_FILE = __DIR__ . '/../game-state/game-state.json';

    public function menu(): array
    {
        return [
            'text' => "🎮 Spel\nKies een spel:",
            'reply_markup' => new InlineKeyboard(
                [
                    ['text' => 'Wanneer', 'callback_data' => 'game:start:when'],
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
        Logger::info('Game callback: ' . json_encode([
            'data' => $data,
            'action' => $action,
            'activeRound' => is_array($state['active']) ? ($state['active']['id'] ?? null) : null,
        ], JSON_UNESCAPED_UNICODE));
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
            if (!in_array($type, ['when', 'who'], true)) {
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
            [$roundId, $answerValue] = array_pad(explode(':', substr($action, 7), 2), 2, null);
            $answerIndex = filter_var($answerValue, FILTER_VALIDATE_INT);
            Logger::info('Game answer callback: ' . json_encode([
                'roundId' => $roundId,
                'answerIndex' => $answerIndex,
            ], JSON_UNESCAPED_UNICODE));
            $active = $state['active'];
            if (!is_array($active) || !hash_equals((string) ($active['id'] ?? ''), (string) $roundId)) {
                Logger::info('Game answer rejected: no matching active round.');
                $this->send('Er is geen actieve ronde.');
                return true;
            }
            if ($answerIndex === false || !isset($active['options'][$answerIndex])) {
                $this->send('Dit antwoord hoort niet bij de actieve ronde.');
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
        $timestamp = strtotime($message->date);
        $answerDate = $type === 'when' ? $this->formatMonthYear($timestamp) : null;
        $options = $type === 'when'
            ? $this->monthOptions($timestamp, $answerDate)
            : [PERSONAL_NAME, SENDER_NAME, 'Groepschat'];
        shuffle($options);
        $answer = $type === 'when'
            ? $answerDate
            : match ($message->messenger) {
                Messenger::Self => PERSONAL_NAME,
                Messenger::Sender => SENDER_NAME,
                default => 'Groepschat',
            };
        return [
            'id' => bin2hex(random_bytes(8)),
            'type' => $type,
            'question' => ($type === 'when'
                ? "🎮 Wanneer is het volgende bericht verstuurd?"
                : "🎮 Wie heeft het volgende bericht verstuurd?") . "\n\n{$message->message}",
            'options' => $options,
            'answer' => $answer,
            'answers' => [],
        ];
    }

    private function formatMonthYear(int $timestamp): string
    {
        $months = [
            1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
            5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
            9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
        ];
        return $months[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }

    private function monthOptions(int $answerTimestamp, string $answer): array
    {
        $start = strtotime('2003-10-01');
        $end = strtotime('2008-02-01');
        $options = [$answer];
        while (count($options) < 4) {
            $timestamp = strtotime('+' . random_int(0, 52) . ' months', $start);
            if ($timestamp <= $end) {
                $option = $this->formatMonthYear($timestamp);
                if (!in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
        }
        return $options;
    }

    private function answerKeyboard(array $active): InlineKeyboard
    {
        return new InlineKeyboard(...array_chunk(array_map(
            static fn (string $option, int $index): array => ['text' => $option, 'callback_data' => 'game:answer:' . $active['id'] . ':' . $index],
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
            Logger::info('Game state missing: ' . self::STATE_FILE);
            return $default;
        }

        $contents = file_get_contents(self::STATE_FILE);
        $state = json_decode((string) $contents, true);
        if (!is_array($state)) {
            Logger::info('Game state invalid: ' . self::STATE_FILE);
            return $default;
        }

        $state = array_replace_recursive($default, $state);
        Logger::info('Game state read: ' . json_encode([
            'file' => self::STATE_FILE,
            'activeRound' => is_array($state['active']) ? ($state['active']['id'] ?? null) : null,
        ], JSON_UNESCAPED_UNICODE));
        return $state;
    }

    private function writeState(array $state): void
    {
        $directory = dirname(self::STATE_FILE);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create game state directory: ' . $directory);
        }

        $temporary = $directory . '/game-state.json.' . bin2hex(random_bytes(8)) . '.tmp';
        $contents = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write temporary game state: ' . $temporary);
        }
        if (!rename($temporary, self::STATE_FILE)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not replace game state: ' . self::STATE_FILE);
        }

        Logger::info('Game state written: ' . json_encode([
            'file' => self::STATE_FILE,
            'activeRound' => is_array($state['active']) ? ($state['active']['id'] ?? null) : null,
        ], JSON_UNESCAPED_UNICODE));
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
