<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * "Pix copia e cola" estático (BR Code, padrão EMV do Banco Central) a partir da chave da loja.
 *
 * Usado pelos QR Codes de Pix (Marketing → QR Codes). O app do banco lê o código direto do QR: por isso ele é
 * fixo (não passa pelo nosso link curto) e não dá para contar leituras. Cobrança com valor por pedido e baixa
 * automática é outra coisa: PixCharges, pelos gateways.
 */
class PixBrCode
{
    public const KEY_TYPES = ['cpf', 'cnpj', 'email', 'phone', 'random'];

    public const NAME_MAX = 25;

    public const CITY_MAX = 15;

    public const TXID_MAX = 25;

    /** Chave no formato que o Pix aceita, ou null se não for válida para o tipo. */
    public static function normalizeKey(string $type, ?string $key): ?string
    {
        $key = trim((string) $key);
        $digits = preg_replace('/\D/', '', $key);

        return match ($type) {
            'cpf' => self::validCpf($digits) ? $digits : null,
            'cnpj' => self::validCnpj($digits) ? $digits : null,
            'email' => filter_var($key, FILTER_VALIDATE_EMAIL) && strlen($key) <= 77 ? Str::lower($key) : null,
            'phone' => self::phone($digits),
            'random' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) ? Str::lower($key) : null,
            default => null,
        };
    }

    /** Celular: +55 + DDD + número (aceita com ou sem o 55 na frente). */
    private static function phone(string $digits): ?string
    {
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return in_array(strlen($digits), [10, 11], true) && $digits[0] !== '0' ? "+55{$digits}" : null;
    }

    public static function validCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            if ((int) $cpf[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function validCnpj(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $weights = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cnpj[$i] * $weights[$i];
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $cnpj[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    /** Texto como o padrão pede: sem acento e só caracteres simples (nome, cidade, descrição). */
    public static function plainText(?string $text, int $max): string
    {
        $text = Str::ascii((string) $text);
        $text = preg_replace('/[^A-Za-z0-9 .,\-\/&]/', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return trim(substr($text, 0, $max));
    }

    /** Identificador (aparece no extrato): só letras e números, até 25; vazio = "***" (sem identificador). */
    public static function txid(?string $txid): string
    {
        $txid = substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) $txid)), 0, self::TXID_MAX);

        return $txid !== '' ? $txid : '***';
    }

    /**
     * Monta o código. `$key` já normalizada; `$amount` null/0 = a pessoa digita o valor no banco.
     *
     * @param  array{key:string,name:string,city:string,amount?:float|string|null,description?:string|null,txid?:string|null}  $pix
     */
    public static function payload(array $pix): string
    {
        $key = (string) $pix['key'];
        $description = self::plainText($pix['description'] ?? null, 99);
        // O bloco da conta (26) tem no máximo 99 caracteres: a descrição usa o que sobra depois da chave
        $room = 99 - strlen(self::field('00', 'br.gov.bcb.pix')) - strlen(self::field('01', $key)) - 4;
        $description = $room > 0 ? trim(substr($description, 0, $room)) : '';

        $account = self::field('00', 'br.gov.bcb.pix').self::field('01', $key)
            .($description !== '' ? self::field('02', $description) : '');

        $amount = (float) ($pix['amount'] ?? 0);

        $code = self::field('00', '01')
            .self::field('26', $account)
            .self::field('52', '0000')
            .self::field('53', '986')
            .($amount > 0 ? self::field('54', number_format($amount, 2, '.', '')) : '')
            .self::field('58', 'BR')
            .self::field('59', self::plainText($pix['name'], self::NAME_MAX) ?: 'LOJA')
            .self::field('60', self::plainText($pix['city'], self::CITY_MAX) ?: 'BRASIL')
            .self::field('62', self::field('05', self::txid($pix['txid'] ?? null)))
            .'6304';

        return $code.self::crc16($code);
    }

    /** Confere o dígito de controle (CRC16) de um código pronto. */
    public static function isValid(string $code): bool
    {
        return strlen($code) > 8 && str_starts_with($code, '000201')
            && substr($code, -8, 4) === '6304'
            && self::crc16(substr($code, 0, -4)) === substr($code, -4);
    }

    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em hexadecimal maiúsculo com 4 dígitos. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
