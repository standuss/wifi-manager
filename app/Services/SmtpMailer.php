<?php

declare(strict_types=1);

namespace WifiManager\Services;

final class SmtpMailer
{
    /** @param array<string,string> $settings */
    public function send(array $settings, string $recipient, string $subject, string $body, string $password = ''): void
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $encryption = (string) ($settings['smtp_encryption'] ?? 'none');
        $timeout = max(3, min(60, (int) ($settings['smtp_timeout_seconds'] ?? 10)));
        if ($host === '' || $port < 1 || $port > 65535) throw new \RuntimeException('SMTP server není správně nastavený.');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('E-mail příjemce není platný.');

        $transport = $encryption === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $number = 0;
        $message = '';
        $socket = @stream_socket_client($transport . '://' . $host . ':' . $port, $number, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new \RuntimeException('Spojení se SMTP serverem selhalo: ' . ($message ?: (string) $number));
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO wifimanager', [250]);
            if ($encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP server odmítl zabezpečení STARTTLS.');
                }
                $this->command($socket, 'EHLO wifimanager', [250]);
            }
            if (($settings['smtp_auth_enabled'] ?? '0') === '1') {
                $username = (string) ($settings['smtp_username'] ?? '');
                if ($username === '' || $password === '') throw new \RuntimeException('Pro SMTP ověření chybí uživatelské jméno nebo heslo.');
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334], false);
                $this->command($socket, base64_encode($password), [235], false);
            }

            $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
            $fromName = trim((string) ($settings['smtp_from_name'] ?? 'WiFi Manager'));
            if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) throw new \RuntimeException('Odesílací SMTP e-mail není platný.');
            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->header($fromName) . ' <' . $fromEmail . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $this->header($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@wifimanager>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
            $normalized = preg_replace('/(^|\r\n)\./', '$1..', $normalized) ?? $normalized;
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $normalized . "\r\n.\r\n";
            if (fwrite($socket, $payload) === false) throw new \RuntimeException('Zápis zprávy na SMTP server selhal.');
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $expected */
    private function command($socket, string $command, array $expected, bool $loggable = true): void
    {
        if (fwrite($socket, $command . "\r\n") === false) throw new \RuntimeException('Komunikace se SMTP serverem selhala.');
        $this->expect($socket, $expected, $loggable ? $command : 'citlivý údaj');
    }

    /** @param resource $socket @param list<int> $expected */
    private function expect($socket, array $expected, string $after = ''): void
    {
        $lines = [];
        do {
            $line = fgets($socket, 8192);
            if ($line === false) throw new \RuntimeException('SMTP server přestal odpovídat' . ($after !== '' ? ' po příkazu ' . $after : '') . '.');
            $lines[] = trim($line);
        } while (strlen($line) >= 4 && $line[3] === '-');
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('SMTP server vrátil chybu ' . $code . ': ' . mb_substr(implode(' ', $lines), 0, 500));
        }
    }

    private function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
