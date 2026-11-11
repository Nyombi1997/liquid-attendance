<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Envoi d'e-mails par SMTP, sans dépendance (pas de Composer).
 * Configuration dans .env : SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls, ssl ou none),
 * SMTP_USER, SMTP_PASSWORD, SMTP_FROM, MAIL_NAME (ou SMTP_FROM_NAME). Sans SMTP_HOST
 * ni SMTP_FROM, aucun e-mail n'est envoyé (notifications dans l'application seulement).
 * Chaque message part en HTML (logo intégré à l'e-mail, titre = MAIL_NAME,
 * signature = MAIL_NAME) avec sa version texte.
 * Appelé par le worker (bin/fleet-tasks.php), jamais pendant une requête web.
 */
final class Mailer
{
    private const TIMEOUT = 20;
    /** Logo intégré à chaque e-mail (pièce jointe « inline », aucun lien externe) */
    private const LOGO = '/public/assets/img/LOGO-LIT-color.png';
    private const LOGO_CID = 'logo@liquid-presence';

    /** @var resource|null */
    private $socket = null;

    public static function configured(): bool
    {
        return (string) Config::get('mail.host', '') !== '' && (string) Config::get('mail.from', '') !== '';
    }

    /** Nom de l'expéditeur, titre et signature des e-mails (MAIL_NAME). */
    public static function name(): string
    {
        return (string) Config::get('mail.from_name', Config::get('app_name', 'Liquid Présence'));
    }

    /**
     * Envoie un message. $body : texte (paragraphes séparés par une ligne vide) ;
     * $heading : titre affiché au-dessus du texte (facultatif) ; $link : adresse du
     * bouton « Ouvrir ». Renvoie null si tout s'est bien passé, sinon l'erreur.
     */
    public function send(string $to, string $subject, string $body, ?string $heading = null, ?string $link = null): ?string
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
            $this->write($this->message($from, $to, $subject, self::compose($body, $heading, $link)) . "\r\n.");
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

    /**
     * Versions texte et HTML d'un message (pur, sans envoi).
     * @return array{text:string, html:string}
     */
    public static function compose(string $body, ?string $heading = null, ?string $link = null): array
    {
        $name = self::name();
        $body = trim(str_replace("\r\n", "\n", $body));
        $heading = $heading !== null && trim($heading) !== '' ? trim($heading) : null;
        $text = ($heading !== null ? $heading . "\n\n" : '') . ($body !== '' ? $body . "\n\n" : '')
            . ($link ? 'Ouvrir : ' . $link . "\n\n" : '')
            . "Cordialement,\n" . $name . "\n\n(Message automatique, merci de ne pas répondre.)";

        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paragraphs = '';
        foreach (preg_split('/\n{2,}/', $body) ?: [] as $p) {
            if (trim($p) === '') {
                continue;
            }
            // Adresses cliquables, retours à la ligne gardés
            $p = preg_replace('#https?://[^\s<]+[^\s<.,;:!?)]#u', '<a href="$0" style="color:#273c88">$0</a>', $h(trim($p)));
            $paragraphs .= '<p style="margin:0 0 14px">' . nl2br($p, false) . '</p>';
        }
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $h($name) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f2f4f8">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;font-size:15px;line-height:1.55">'
            . '<tr><td style="padding:24px 28px 12px">'
            . (self::logo() !== null ? '<img src="cid:' . self::LOGO_CID . '" alt="Liquid Intelligent Technologies" width="170" style="display:block;width:170px;max-width:100%;height:auto;border:0">' : '')
            . '</td></tr>'
            . '<tr><td style="padding:0 28px 16px;border-bottom:3px solid #c8187d"><div style="font-size:20px;font-weight:bold;color:#273c88">' . $h($name) . '</div></td></tr>'
            . '<tr><td style="padding:22px 28px 8px">'
            . ($heading !== null ? '<h2 style="margin:0 0 14px;font-size:17px;color:#273c88">' . $h($heading) . '</h2>' : '')
            . $paragraphs
            . ($link ? '<p style="margin:18px 0"><a href="' . $h($link) . '" style="display:inline-block;background:#273c88;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:5px;font-weight:bold">Ouvrir</a></p>' : '')
            . '<p style="margin:22px 0 0">Cordialement,<br><strong style="color:#273c88">' . $h($name) . '</strong></p>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px 22px;color:#6b7280;font-size:12px">Message automatique, merci de ne pas répondre.</td></tr>'
            . '</table></td></tr></table></body></html>';
        return ['text' => $text, 'html' => $html];
    }

    /** Contenu du logo (PNG), ou null s'il est absent. */
    private static function logo(): ?string
    {
        static $png = false;
        if ($png === false) {
            $file = dirname(__DIR__, 2) . self::LOGO;
            $png = is_readable($file) ? (string) file_get_contents($file) : null;
        }
        return $png !== '' ? $png : null;
    }

    /**
     * En-têtes et corps MIME (UTF-8, base64 : aucune ligne commençant par un point) :
     * multipart/related { multipart/alternative { texte, HTML }, logo inline }.
     * @param array{text:string, html:string} $content
     */
    private function message(string $from, string $to, string $subject, array $content): string
    {
        $name = self::name();
        $b64 = static fn(string $s): string => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        $crlf = static fn(string $s): string => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $s));
        $related = 'rel-' . bin2hex(random_bytes(8));
        $alt = 'alt-' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($name !== '' ? self::encode($name) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="' . $related . '"; type="multipart/alternative"',
            'Auto-Submitted: auto-generated',
        ];
        $parts = [
            '--' . $related,
            'Content-Type: multipart/alternative; boundary="' . $alt . '"',
            '',
            '--' . $alt,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $b64($crlf($content['text'])),
            '--' . $alt,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $b64($crlf($content['html'])),
            '--' . $alt . '--',
        ];
        if (($logo = self::logo()) !== null) {
            array_push($parts,
                '--' . $related,
                'Content-Type: image/png; name="logo.png"',
                'Content-Transfer-Encoding: base64',
                'Content-ID: <' . self::LOGO_CID . '>',
                'Content-Disposition: inline; filename="logo.png"',
                '',
                $b64($logo));
        }
        $parts[] = '--' . $related . '--';
        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
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
        // Nom annoncé : domaine de l'expéditeur plutôt que le nom du conteneur
        // (identifiant hexadécimal aléatoire, mal noté par les filtres anti-spam)
        $domain = explode('@', (string) Config::get('mail.from', ''))[1] ?? '';
        $this->command('EHLO ' . ($domain !== '' ? $domain : (gethostname() ?: 'localhost')), 250);
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
