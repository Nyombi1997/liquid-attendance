<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Envoi d'e-mails par SMTP, sans dépendance (pas de Composer).
 * Configuration dans .env : SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls, ssl ou none),
 * SMTP_USER, SMTP_PASSWORD, SMTP_FROM, SMTP_FROM_NAME. Sans SMTP_HOST ni
 * SMTP_FROM, aucun e-mail n'est envoyé (notifications dans l'application seulement).
 * Appelé par le worker (bin/fleet-tasks.php), jamais pendant une requête web.
 */
final class Mailer
{
    private const TIMEOUT = 20;

    /** @var resource|null */
    private $socket = null;

    public static function configured(): bool
    {
        return (string) Config::get('mail.host', '') !== '' && (string) Config::get('mail.from', '') !== '';
    }

    /**
     * Envoie un message texte. Renvoie null si tout s'est bien passé, sinon l'erreur.
     */
    public function send(string $to, string $subject, string $body): ?string
    {
        if (!self::configured()) {
            return 'SMTP non configuré';
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse invalide : ' . $to;
        }
        $host = (string) Config::get('mail.host');
        $port = (int) Config::get('mail.port', 587);
        $secure = (string) Config::get('mail.secure', 'tls');
        $from = (string) Config::get('mail.from');
        try {
            $this->open(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port);
            $this->expect(220);
            $this->hello();
            if ($secure === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('négociation TLS impossible');
                }
                $this->hello();
            }
            $user = (string) Config::get('mail.user', '');
            if ($user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($user), 334);
                $this->command(base64_encode((string) Config::get('mail.password', '')), 235);
            }
            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', 354);
            $this->write($this->message($from, $to, $subject, $body) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
            return null;
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 250);
        } finally {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /** En-têtes et corps (UTF-8, base64 : aucune ligne commençant par un point). */
    private function message(string $from, string $to, string $subject, string $body): string
    {
        $name = (string) Config::get('mail.from_name', Config::get('app_name', ''));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($name !== '' ? self::encode($name) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))), 76, "\r\n"));
    }

    private static function encode(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }

    private function open(string $address): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($address, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new \RuntimeException("connexion à $address impossible : $errstr ($errno)");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;
    }

    private function hello(): void
    {
        $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
    }

    /** @param int|int[] $expected */
    private function command(string $line, int|array $expected): void
    {
        $this->write($line);
        $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data . "\r\n") === false) {
            throw new \RuntimeException('écriture impossible');
        }
    }

    /** @param int|int[] $expected */
    private function expect(int|array $expected): void
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            throw new \RuntimeException('réponse SMTP inattendue : ' . trim($reply ?: 'aucune réponse'));
        }
    }
}
