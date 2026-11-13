<?php

namespace App\Services\Alexa;

use App\Models\Quote;
use App\Support\Delivery;
use Illuminate\Support\Carbon;

/**
 * O que a Alexa fala sobre as entregas. Nunca fala valores (a equipe de Produção também usa).
 * Lista as 5 primeiras em voz; o cartão no app da Alexa traz todas.
 */
class AlexaSpeech
{
    public const SPOKEN_LIMIT = 5;

    public const CARD_LIMIT = 30;

    public const REMINDER_BUDGET = 240;

    private const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    private const MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * Entregas de um dia ou de um período.
     *
     * @param  array<int, array{date: string, time: ?string, items: string, customer: ?string, method: ?string}>  $rows
     * @return array{speech: string, card: string}
     */
    public function period(Carbon $from, Carbon $to, array $rows, int $overdueCount = 0): array
    {
        $singleDay = $from->isSameDay($to);
        $when = $singleDay ? $this->dayLabel($from) : $this->rangeLabel($from, $to);
        $count = count($rows);

        if ($count === 0) {
            $speech = $singleDay ? "Você não tem entregas para {$this->dayPhrase($from)}." : "Você não tem entregas {$when}.";
        } else {
            $speech = $this->capitalize($when).', você tem '.$this->plural($count, 'entrega', 'entregas').'. ';
            $speech .= $this->spokenList($rows, ! $singleDay);
        }

        if ($overdueCount > 0) {
            $speech .= ' Atenção: '.$this->plural($overdueCount, 'entrega atrasada', 'entregas atrasadas').'.';
        }

        return ['speech' => trim($speech), 'card' => $this->cardList($rows, ! $singleDay)];
    }

    /** @param  array{date: string, time: ?string, items: string, customer: ?string, method: ?string}|null  $row */
    public function next(?array $row): array
    {
        if (! $row) {
            return ['speech' => 'Você não tem nenhuma entrega marcada daqui pra frente.', 'card' => ''];
        }

        $date = Carbon::parse($row['date'], Delivery::TZ);
        $speech = 'Sua próxima entrega é '.$this->dayPhrase($date).($row['time'] ? ', '.$this->timePhrase($row['time']) : '').': '.$this->describe($row).'.';

        return ['speech' => $speech, 'card' => $this->cardLine($row, true)];
    }

    /** @param  array<int, array{date: string, time: ?string, items: string, customer: ?string, method: ?string}>  $rows */
    public function overdue(array $rows): array
    {
        if ($rows === []) {
            return ['speech' => 'Nenhuma entrega atrasada. Tudo em dia!', 'card' => ''];
        }

        $speech = 'Você tem '.$this->plural(count($rows), 'entrega atrasada', 'entregas atrasadas').'. '.$this->spokenList($rows, true);

        return ['speech' => trim($speech), 'card' => $this->cardList($rows, true)];
    }

    /**
     * Texto do lembrete diário (a Alexa fala sozinha no horário): curto — cabe o que der em ~240 caracteres e o
     * resto fica pra pergunta. Fala "hoje" porque o texto é sempre refeito para o dia em que o lembrete toca.
     *
     * @param  array<int, array{date: string, time: ?string, items: string, customer: ?string, method: ?string}>  $rows
     */
    public function dailyReminder(array $rows, int $overdueCount = 0): string
    {
        $overdue = $overdueCount > 0 ? ' E '.$this->plural($overdueCount, 'entrega atrasada', 'entregas atrasadas').'.' : '';
        if ($rows === []) {
            return 'Bom dia! Hoje você não tem entregas marcadas.'.$overdue;
        }

        $text = 'Bom dia! Hoje você tem '.$this->plural(count($rows), 'entrega', 'entregas').'.';
        $spoken = 0;
        foreach ($rows as $row) {
            $line = ' '.$this->capitalize(($row['time'] ? $this->timePhrase($row['time']).', ' : '').$this->describe($row)).'.';
            if (mb_strlen($text.$line.$overdue) > self::REMINDER_BUDGET) {
                break;
            }
            $text .= $line;
            $spoken++;
        }
        if ($spoken < count($rows)) {
            $text .= ' Pergunte ao Meu Custo Total para ouvir todas.';
        }

        return $text.$overdue;
    }

    /** Venda da Agenda → o mínimo que a Alexa precisa (sem valores). */
    public static function row(Quote $quote): array
    {
        return [
            'date' => $quote->delivery_date->toDateString(),
            'time' => $quote->delivery_time,
            'items' => collect($quote->soldLines())->map(fn ($l) => $l['quantity'].'x '.$l['description'])->implode(', '),
            'customer' => $quote->customer?->name,
            'method' => $quote->delivery_method,
        ];
    }

    private function spokenList(array $rows, bool $withDay): string
    {
        $parts = [];
        foreach (array_slice($rows, 0, self::SPOKEN_LIMIT) as $row) {
            $prefix = [];
            if ($withDay) {
                $prefix[] = $this->dayPhrase(Carbon::parse($row['date'], Delivery::TZ));
            }
            if ($row['time']) {
                $prefix[] = $this->timePhrase($row['time']);
            }
            $parts[] = $this->capitalize(($prefix ? implode(', ', $prefix).', ' : '').$this->describe($row)).'.';
        }

        $rest = count($rows) - self::SPOKEN_LIMIT;
        if ($rest > 0) {
            $parts[] = 'E mais '.$this->plural($rest, 'entrega', 'entregas').', que estão no app da Alexa.';
        }

        return implode(' ', $parts);
    }

    /** "2 Chaveiro Rosa para a Maria, retirada" */
    private function describe(array $row): string
    {
        $items = array_map('trim', explode(',', $row['items']));
        $spokenItems = implode(', ', array_slice($items, 0, 2));
        if (count($items) > 2) {
            $spokenItems .= ' e mais '.(count($items) - 2).' '.(count($items) - 2 === 1 ? 'item' : 'itens');
        }
        $spokenItems = preg_replace('/\b(\d+)x\s+/u', '$1 ', $spokenItems);

        $text = $spokenItems;
        $first = $row['customer'] ? trim(explode(' ', trim($row['customer']))[0]) : '';
        if ($first !== '') {
            $text .= ' para '.$first;
        }
        if ($row['method'] && isset(Delivery::METHOD_LABELS[$row['method']])) {
            $text .= ', '.mb_strtolower(Delivery::METHOD_LABELS[$row['method']]);
        }

        return $text;
    }

    /** A resposta inteira da Alexa tem limite de tamanho: o cartão para em 30 linhas (um mês cheio passaria). */
    private function cardList(array $rows, bool $withDay): string
    {
        $lines = array_map(fn ($row) => $this->cardLine($row, $withDay), array_slice($rows, 0, self::CARD_LIMIT));
        if (count($rows) > self::CARD_LIMIT) {
            $lines[] = '… e mais '.(count($rows) - self::CARD_LIMIT).' na Agenda do Meu Custo Total.';
        }

        return implode("\n", $lines);
    }

    private function cardLine(array $row, bool $withDay): string
    {
        $when = $withDay ? Delivery::dateLabel($row['date'], $row['time']) : ($row['time'] ?? 'sem hora');
        $method = $row['method'] ? ' ('.(Delivery::METHOD_LABELS[$row['method']] ?? $row['method']).')' : '';

        return "• {$when} — {$row['items']}".($row['customer'] ? " — {$row['customer']}" : '').$method;
    }

    /** "hoje", "amanhã", "ontem" ou "sexta-feira, 21 de novembro" */
    private function dayPhrase(Carbon $date): string
    {
        $today = now(Delivery::TZ)->startOfDay();
        $diff = (int) $today->diffInDays($date->copy()->startOfDay(), false);

        return match ($diff) {
            0 => 'hoje',
            1 => 'amanhã',
            -1 => 'ontem',
            default => self::WEEKDAYS[$date->dayOfWeek].', '.$date->day.' de '.self::MONTHS[$date->month],
        };
    }

    private function dayLabel(Carbon $date): string
    {
        $phrase = $this->dayPhrase($date);

        return in_array($phrase, ['hoje', 'amanhã', 'ontem'], true) ? $phrase : 'na '.$phrase;
    }

    private function rangeLabel(Carbon $from, Carbon $to): string
    {
        $today = now(Delivery::TZ);
        if ($today->between($from->copy()->startOfDay(), $to->copy()->endOfDay()) && $from->isMonday() && (int) $from->diffInDays($to) === 6) {
            return 'nesta semana';
        }

        return 'de '.$from->day.' de '.self::MONTHS[$from->month].' a '.$to->day.' de '.self::MONTHS[$to->month];
    }

    /** "14:00" → "às 14 horas"; "14:30" → "às 14 e 30"; "01:15" → "à 1 e 15" */
    private function timePhrase(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);
        $article = $h === 1 ? 'à' : 'às';
        if ($m === 0) {
            return "{$article} {$h} ".($h === 1 ? 'hora' : 'horas');
        }

        return "{$article} {$h} e ".str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }

    private function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
