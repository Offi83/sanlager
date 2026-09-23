<?php

namespace LagerApp;

use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Einstellungen für den Wochenbericht per E-Mail, ausschließlich aus
 * der .env-Datei (siehe .env.example).
 *
 * Bewusst nicht über die Weboberfläche einstellbar: Die Werte ändern
 * sich selten, enthalten SMTP-Zugangsdaten und sollen nicht von jedem
 * geändert werden können, der im Lager bucht.
 */
final class ReportConfig
{
    /**
     * @param Address[] $recipients
     */
    private function __construct(
        public readonly string $mailerDsn,
        public readonly Address $from,
        public readonly array $recipients,
        public readonly int $expiryDays,
        public readonly ?string $appUrl
    ) {
    }

    /**
     * @param array<string, mixed> $env in der Anwendung $_ENV
     * @throws RuntimeException mit allen fehlenden/ungültigen Einstellungen
     */
    public static function fromEnv(array $env): self
    {
        $errors = [];

        $value = static fn (string $key): string =>
            is_string($env[$key] ?? null) ? trim($env[$key]) : '';

        $mailerDsn = $value('MAILER_DSN');

        if ($mailerDsn === '') {
            $errors[] = 'MAILER_DSN fehlt (z. B. smtp://benutzer:passwort@smtp.example.org:587).';
        }

        $from = null;

        try {
            $from = Address::create($value('REPORT_FROM'));
        } catch (Throwable) {
            $errors[] = 'REPORT_FROM fehlt oder ist keine gültige Absenderadresse.';
        }

        $recipientList = array_filter(
            array_map('trim', explode(',', $value('REPORT_RECIPIENTS')))
        );

        if ($recipientList === []) {
            $errors[] = 'REPORT_RECIPIENTS fehlt (eine oder mehrere Adressen, durch Komma getrennt).';
        }

        $recipients = [];

        foreach ($recipientList as $recipient) {
            try {
                $recipients[] = Address::create($recipient);
            } catch (Throwable) {
                $errors[] = 'REPORT_RECIPIENTS enthält eine ungültige Adresse: ' . $recipient;
            }
        }

        $expiryDays = $value('REPORT_EXPIRY_DAYS');

        if ($expiryDays === '') {
            $expiryDays = '90';
        }

        if (!ctype_digit($expiryDays) || (int) $expiryDays < 1 || (int) $expiryDays > 365) {
            $errors[] = 'REPORT_EXPIRY_DAYS muss eine Zahl zwischen 1 und 365 sein.';
        }

        $appUrl = rtrim($value('APP_URL'), '/');

        if ($appUrl !== '' && !preg_match('#^https?://#', $appUrl)) {
            $errors[] = 'APP_URL muss mit http:// oder https:// beginnen.';
        }

        if ($errors !== []) {
            throw new RuntimeException(
                "Wochenbericht ist nicht korrekt konfiguriert (.env):\n- "
                . implode("\n- ", $errors)
            );
        }

        return new self(
            $mailerDsn,
            $from,
            $recipients,
            (int) $expiryDays,
            $appUrl !== '' ? $appUrl : null
        );
    }
}
