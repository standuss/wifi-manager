<?php

declare(strict_types=1);

namespace WifiManager\RouterOS;

final class ApiClient
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{host:string,port:int,username:string,password:string,connect_timeout?:int,read_timeout?:int} $options
     */
    public function __construct(private readonly array $options)
    {
    }

    public function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $host = $this->options['host'];
        $port = $this->options['port'];
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorNumber,
            $errorMessage,
            (float) ($this->options['connect_timeout'] ?? 5),
            STREAM_CLIENT_CONNECT,
        );

        if (!is_resource($socket)) {
            throw new RouterOsException(sprintf('Spojení k MikroTik API se nezdařilo: %s (%d)', $errorMessage, $errorNumber));
        }

        stream_set_timeout($socket, (int) ($this->options['read_timeout'] ?? 8));
        $this->socket = $socket;

        $this->sendSentence([
            '/login',
            '=name=' . $this->options['username'],
            '=password=' . $this->options['password'],
        ]);
        $response = $this->readResponse();
        if ($response['traps'] !== []) {
            $trap = $response['traps'][0];
            $this->disconnect();
            throw new RouterOsException($trap['message'] ?? 'Přihlášení k MikroTiku selhalo.', $trap['category'] ?? null);
        }
    }

    /**
     * @param array<string, scalar|null> $attributes
     * @param list<string> $queries
     * @param list<string>|null $properties
     * @return array{rows:list<array<string,string>>,done:array<string,string>}
     */
    public function command(string $command, array $attributes = [], array $queries = [], ?array $properties = null): array
    {
        $this->connect();
        $words = ['/' . trim($command, '/')];
        if ($properties !== null && $properties !== []) {
            $words[] = '=.proplist=' . implode(',', $properties);
        }
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $words[] = '=' . $name . '=' . self::scalar($value);
            }
        }
        foreach ($queries as $query) {
            $words[] = str_starts_with($query, '?') ? $query : '?' . $query;
        }

        $this->sendSentence($words);
        $response = $this->readResponse();
        if ($response['traps'] !== []) {
            $trap = $response['traps'][0];
            throw new RouterOsException($trap['message'] ?? 'MikroTik vrátil chybu.', $trap['category'] ?? null);
        }
        return ['rows' => $response['rows'], 'done' => $response['done']];
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** @param list<string> $words */
    private function sendSentence(array $words): void
    {
        if (!is_resource($this->socket)) {
            throw new RouterOsException('API socket není připojen.');
        }
        $payload = '';
        foreach ($words as $word) {
            $payload .= self::encodeLength(strlen($word)) . $word;
        }
        $payload .= chr(0);

        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($this->socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RouterOsException('Zápis do MikroTik API selhal.');
            }
            $offset += $written;
        }
    }

    /** @return array{rows:list<array<string,string>>,traps:list<array<string,string>>,done:array<string,string>} */
    private function readResponse(): array
    {
        $rows = [];
        $traps = [];
        $done = [];

        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === []) {
                continue;
            }
            $reply = array_shift($sentence);
            $attributes = self::parseAttributes($sentence);
            if ($reply === '!re') {
                $rows[] = $attributes;
            } elseif ($reply === '!trap') {
                $traps[] = $attributes;
            } elseif ($reply === '!fatal') {
                throw new RouterOsException($attributes['message'] ?? 'MikroTik ukončil API spojení.');
            } elseif ($reply === '!empty') {
                // Since RouterOS 7.18, commands without data can emit !empty,
                // but the response still ends with a separate !done sentence.
                // Stopping here leaves !done in the socket and shifts every
                // subsequent response to the following command.
                continue;
            } elseif ($reply === '!done') {
                $done = $attributes;
                break;
            }
        }

        return compact('rows', 'traps', 'done');
    }

    /** @return list<string> */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $length = $this->readLength();
            if ($length === 0) {
                return $words;
            }
            $words[] = $this->readExact($length);
        }
    }

    private function readLength(): int
    {
        $first = ord($this->readExact(1));
        if (($first & 0x80) === 0x00) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & 0x3F) << 8) | ord($this->readExact(1));
        }
        if (($first & 0xE0) === 0xC0) {
            $tail = unpack('n', $this->readExact(2));
            return (($first & 0x1F) << 16) | (int) $tail[1];
        }
        if (($first & 0xF0) === 0xE0) {
            $tail = unpack('N', chr(0) . $this->readExact(3));
            return (($first & 0x0F) << 24) | (int) $tail[1];
        }
        if ($first === 0xF0) {
            $tail = unpack('N', $this->readExact(4));
            return (int) $tail[1];
        }
        throw new RouterOsException('MikroTik API poslalo neznámý formát délky slova.');
    }

    private function readExact(int $length): string
    {
        if (!is_resource($this->socket)) {
            throw new RouterOsException('API socket není připojen.');
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new RouterOsException('Vypršel čas při čtení z MikroTik API.');
                }
                throw new RouterOsException('MikroTik API neočekávaně ukončilo spojení.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        }
        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . pack('n', $length & 0xFFFF);
        }
        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . substr(pack('N', $length), 1);
        }
        return chr(0xF0) . pack('N', $length);
    }

    /** @param list<string> $words @return array<string,string> */
    private static function parseAttributes(array $words): array
    {
        $attributes = [];
        foreach ($words as $word) {
            if ($word === '' || ($word[0] !== '=' && $word[0] !== '.')) {
                continue;
            }
            $offset = $word[0] === '=' ? 1 : 0;
            $position = strpos($word, '=', $offset);
            if ($position === false) {
                continue;
            }
            $name = substr($word, $offset, $position - $offset);
            $attributes[$name] = substr($word, $position + 1);
        }
        return $attributes;
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return (string) $value;
    }

}
