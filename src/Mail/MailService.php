<?php
declare(strict_types=1);

namespace App\Mail;

use App\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Thin wrapper around PHPMailer. If no SMTP host is configured, mails
 * are written to storage/mail as .eml files for local development.
 */
final class MailService
{
    public function __construct(
        private readonly Config $config,
        private readonly string $basePath,
        private readonly string $viewsPath,
    ) {}

    /**
     * @param array<string,mixed> $vars
     */
    public function send(string $toEmail, ?string $toName, string $template, array $vars): bool
    {
        $subject = $this->subjectFor($template);
        $html    = $this->render("{$template}.html.php", $vars);
        $text    = $this->render("{$template}.txt.php", $vars);

        $host = (string)$this->config->get('MAIL_HOST', '');
        if ($host === '') {
            return $this->writeToDisk($toEmail, $toName, $subject, $html, $text);
        }

        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host       = $host;
            $mailer->Port       = $this->config->int('MAIL_PORT', 587);
            $user = (string)$this->config->get('MAIL_USERNAME', '');
            $pass = (string)$this->config->get('MAIL_PASSWORD', '');
            if ($user !== '') {
                $mailer->SMTPAuth   = true;
                $mailer->Username   = $user;
                $mailer->Password   = $pass;
            }
            $enc = strtolower((string)$this->config->get('MAIL_ENCRYPTION', 'tls'));
            if ($enc === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls' || $enc === 'starttls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPAutoTLS = false;
                $mailer->SMTPSecure = '';
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom(
                (string)$this->config->get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
                (string)$this->config->get('MAIL_FROM_NAME', 'GravelExplorer'),
            );
            $mailer->addAddress($toEmail, $toName ?? '');

            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $html;
            $mailer->AltBody = $text;

            $mailer->send();
            return true;
        } catch (MailerException $e) {
            $this->logFailure($toEmail, $subject, $e->getMessage());
            return false;
        }
    }

    private function subjectFor(string $template): string
    {
        return match ($template) {
            'verify_email'   => 'Bestätige deine E-Mail-Adresse',
            'reset_password' => 'Passwort zurücksetzen',
            default          => 'GravelExplorer',
        };
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function render(string $file, array $vars): string
    {
        $full = rtrim($this->viewsPath, '/') . '/' . $file;
        if (!is_file($full)) {
            return '';
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $full;
        return (string)ob_get_clean();
    }

    private function writeToDisk(string $toEmail, ?string $toName, string $subject, string $html, string $text): bool
    {
        $dir = $this->basePath . '/storage/mail';
        // H7: kein @ — wenn das Verzeichnis nicht angelegt werden kann, soll
        // das geloggt und im Aufruf als Fehler bekannt werden.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log("MailService: konnte Mail-Verzeichnis nicht erzeugen: {$dir}");
            return false;
        }
        $stamp = date('Ymd_His');
        $rand  = bin2hex(random_bytes(3));
        $safeTo = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $toEmail) ?: 'unknown';
        $path = "{$dir}/{$stamp}_{$safeTo}_{$rand}.eml";

        $from = (string)$this->config->get('MAIL_FROM_ADDRESS', 'no-reply@example.com');
        $fromName = (string)$this->config->get('MAIL_FROM_NAME', 'GravelExplorer');
        $boundary = 'b_' . bin2hex(random_bytes(8));

        // H2: Defense-in-Depth — auch hier, nicht nur im Validator, alle
        // Steuerzeichen aus den Header-Komponenten entfernen, damit ein
        // versehentlicher CRLF-Eintrag (z.B. aus einer alten DB-Zeile)
        // keine Header-Injection erlaubt.
        $cleanFromName = self::stripControlChars($fromName);
        $cleanToName   = $toName !== null ? self::stripControlChars($toName) : null;

        $headers = "From: {$cleanFromName} <{$from}>\r\n"
                 . 'To: ' . ($cleanToName ? "{$cleanToName} <{$toEmail}>" : $toEmail) . "\r\n"
                 . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                 . 'Date: ' . gmdate('r') . "\r\n\r\n";

        $body  = "--{$boundary}\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
               . $text . "\r\n\r\n"
               . "--{$boundary}\r\n"
               . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
               . $html . "\r\n\r\n"
               . "--{$boundary}--\r\n";

        $bytes = file_put_contents($path, $headers . $body);
        if ($bytes === false) {
            error_log("MailService: konnte EML nicht schreiben: {$path}");
            return false;
        }
        return true;
    }

    private function logFailure(string $to, string $subject, string $err): void
    {
        $logDir = $this->basePath . '/storage/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            error_log("MailService: konnte Log-Verzeichnis nicht erzeugen: {$logDir} | original-err: {$err}");
            return;
        }
        $line = sprintf("[%s] mail-fail to=%s subject=%s err=%s\n",
            gmdate('Y-m-d H:i:s'), $to, $subject, $err);
        if (file_put_contents($logDir . '/mail.log', $line, FILE_APPEND) === false) {
            error_log("MailService: konnte mail.log nicht beschreiben | original-err: {$err}");
        }
    }

    private static function stripControlChars(string $value): string
    {
        return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }
}
