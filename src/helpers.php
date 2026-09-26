<?php

/*
|--------------------------------------------------------------------------
| Globale Helper-Funktionen
|--------------------------------------------------------------------------
|
| Kleine, seiteneffekt-arme Funktionen, die sowohl im HTML-Template
| (public/index.php) als auch in den Action-Klassen unter src/ gebraucht
| werden. Bewusst als einfache Funktionen statt als Klasse gehalten, damit
| Aufrufe wie h($wert) im Template kurz bleiben.
|
| Wird über den "files"-Autoload-Eintrag in composer.json automatisch mit
| vendor/autoload.php geladen.
|--------------------------------------------------------------------------
*/

/**
 * HTML-escaped Ausgabe für alle dynamischen Werte im Template.
 */
function h(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Leitet weiter und beendet die Ausführung (Redirect-nach-POST-Pattern).
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Formatiert ein Datum (z. B. MHD) als d.m.Y für die Anzeige,
 * `null`/leer wird als "ohne MHD" dargestellt.
 */
function formatDate(?string $date): string
{
    if (!$date) {
        return 'ohne MHD';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('d.m.Y', $timestamp);
}

/**
 * MHD-Spalte in Tabellen: Datum, "ohne MHD" – oder "–" bei Artikeln, die
 * gar kein MHD haben (z. B. Mullbinden), da wäre "ohne MHD" nur Rauschen.
 */
function formatExpiry(?string $date, bool $articleHasExpiry): string
{
    return !$date && !$articleHasExpiry ? '–' : formatDate($date);
}

/**
 * Menge mit Einheit in der passenden Form: "1 Rolle", "5 Rollen".
 * $row enthält `unit` (Einzahl) und `unit_plural` (Mehrzahl), wie sie die
 * Abfragen aus der Tabelle `units` liefern.
 */
function quantityText(int $quantity, array $row): string
{
    return $quantity . ' ' . unitText($quantity, $row);
}

/**
 * Nur die Einheit, passend zur Menge (Einzahl nur bei genau 1).
 */
function unitText(int $quantity, array $row): string
{
    $singular = (string) ($row['unit'] ?? 'Stück');

    return abs($quantity) === 1
        ? $singular
        : (string) ($row['unit_plural'] ?? $singular);
}

/**
 * Mengen je Einheit zusammengezählt, z. B. "18 Stück · 12 Paar · 1 Rolle"
 * statt einer Summe über verschiedene Einheiten. Größte Menge zuerst.
 *
 * @param array<int, array{quantity: int|string, unit: string, unit_plural?: string}> $rows
 */
function quantitiesByUnit(array $rows): string
{
    $sums = [];

    foreach ($rows as $row) {
        $sums[$row['unit']] ??= ['quantity' => 0, 'row' => $row];
        $sums[$row['unit']]['quantity'] += (int) $row['quantity'];
    }

    uasort($sums, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);

    return implode(' · ', array_map(
        // Geschütztes Leerzeichen: umbrochen wird nur an den Punkten.
        static fn (array $sum): string => $sum['quantity'] . "\u{00A0}" . unitText($sum['quantity'], $sum['row']),
        $sums
    ));
}

/**
 * Vorlaufzeit in Tagen, ab der ein MHD als "bald erreicht" gilt – aus
 * REPORT_EXPIRY_DAYS in der .env (1–365, sonst 90). Derselbe Wert wie im
 * Wochenbericht (siehe ReportConfig), damit Mail und MHD-Übersicht
 * dasselbe melden.
 */
function expiryWarningDays(): int
{
    $value = is_string($_ENV['REPORT_EXPIRY_DAYS'] ?? null)
        ? trim($_ENV['REPORT_EXPIRY_DAYS'])
        : '';

    return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 365
        ? (int) $value
        : 90;
}

/**
 * Liefert CSS-Klasse und Warntext für ein MHD in einem Aufwasch,
 * statt Ablaufberechnung und Schwellwerte zweimal zu duplizieren.
 *
 * @return array{class: string, warning: string}
 */
function expiryInfo(?string $date): array
{
    $none = ['class' => '', 'warning' => ''];

    if (!$date) {
        return $none;
    }

    $date = normalizeDate($date);

    if ($date === null) {
        return $none;
    }

    /*
     * Reiner Datumsvergleich (Y-m-d als String), identisch zur Logik in
     * StockRepository, damit Anzeige und Bestandszahlen denselben Tag
     * als "abgelaufen" werten.
     */
    $today = date('Y-m-d');
    $warningThreshold = date('Y-m-d', strtotime('+' . expiryWarningDays() . ' days'));

    if ($date < $today) {
        return ['class' => 'expiry-expired', 'warning' => 'ABGELAUFEN'];
    }

    if ($date <= $warningThreshold) {
        return ['class' => 'expiry-warning', 'warning' => 'MHD bald erreicht'];
    }

    return $none;
}

/**
 * Prüft ein eingegebenes Datum (z. B. MHD) und liefert es im
 * Speicherformat Y-m-d zurück, oder `null`, wenn es kein gültiges
 * Kalenderdatum ist.
 *
 * Akzeptiert Y-m-d (Datumsfeld im Browser) sowie d.m.Y (manuelle
 * Eingabe, falls der Browser kein Datumsfeld anbietet). Alle
 * MHD-Vergleiche in SQL laufen als Textvergleich und funktionieren
 * daher nur, wenn ausschließlich Y-m-d gespeichert wird.
 */
function normalizeDate(string $value): ?string
{
    $value = trim($value);

    foreach (['Y-m-d', 'd.m.Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        if ($date !== false && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

/**
 * Vergleichsform eines Namens (Artikel, Lagerort, Kategorie): ohne
 * Groß-/Kleinschreibung und mit zusammengefassten Leerzeichen, damit
 * "mullbinde" neben "Mullbinde" nicht als zweiter Artikel durchgeht.
 * In PHP statt per COLLATE NOCASE, weil SQLite dabei nur A–Z
 * berücksichtigt, nicht Ä/Ö/Ü.
 */
function nameKey(string $name): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
}

/**
 * Kleines Inline-SVG-Symbol für Buttons (übernimmt die Textfarbe via
 * currentColor, funktioniert ohne Internet). Dekorativ – der Button
 * braucht zusätzlich Text oder ein aria-label/title.
 *
 * Die Pfade stammen aus Lucide (https://lucide.dev, ISC-Lizenz):
 * "undo-2", "trash-2" (letzteres ursprünglich aus Feather, MIT),
 * "volume-2" und "volume-x".
 * Lizenztext siehe licenses/lucide-ISC.txt und THIRD-PARTY-NOTICES.md.
 *
 * @param string $name undo|trash|volume|volume-off
 */
function icon(string $name): string
{
    $paths = match ($name) {
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>'
            . '<path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
            . '<path d="M10 11v6"/><path d="M14 11v6"/>',
        'volume' => '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/>'
            . '<path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/>',
        'volume-off' => '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/>'
            . '<line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/>',
        default => '',
    };

    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths . '</svg>';
}

/**
 * Text, der dem Nutzer zu einer Ausnahme angezeigt werden darf.
 *
 * Eingabe- und Fachfehler (RuntimeException aus den Actions/Repositories,
 * z. B. "Nicht genügend Bestand") sind für den Nutzer gedacht und werden
 * unverändert angezeigt. Alles andere – vor allem Datenbankfehler mit
 * SQL-Details – wird ins PHP-Fehlerprotokoll geschrieben und durch einen
 * allgemeinen Hinweis ersetzt. Mit APP_DEBUG=true in der .env wird der
 * Originaltext angezeigt (Entwicklung).
 */
function userMessage(Throwable $exception): string
{
    $isTechnical = $exception instanceof PDOException
        || !$exception instanceof RuntimeException;

    if (!$isTechnical) {
        return $exception->getMessage();
    }

    error_log('SanLager: ' . $exception);

    if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)) {
        return get_class($exception) . ': ' . $exception->getMessage();
    }

    if ($exception instanceof PDOException && str_contains($exception->getMessage(), 'database is locked')) {
        return 'Die Datenbank ist gerade beschäftigt. Bitte noch einmal versuchen.';
    }

    return 'Es ist ein technischer Fehler aufgetreten. Bitte noch einmal versuchen '
        . 'oder den Administrator informieren.';
}

/**
 * Schutz vor Cross-Site Request Forgery: Stammt eine POST-Anfrage von
 * einer Seite dieser Anwendung? Browser senden bei POST-Anfragen den
 * Origin-Header (sonst Referer); fehlen beide oder zeigen sie auf einen
 * anderen Host, wird die Anfrage abgelehnt.
 *
 * Die HTTP-Basic-Authentifizierung schützt davor nicht, weil der Browser
 * die Zugangsdaten auch bei Anfragen von fremden Seiten mitschickt.
 * Verglichen wird nur Host (inkl. Port), nicht das Schema – so klappt es
 * auch hinter einem Proxy, der HTTPS entgegennimmt.
 *
 * @param array<string, mixed> $server in der Anwendung $_SERVER
 */
function isSameOriginRequest(array $server): bool
{
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));

    $source = $server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '';

    if ($host === '' || !is_string($source) || $source === '' || $source === 'null') {
        return false;
    }

    $parts = parse_url($source);

    if (!isset($parts['host'])) {
        return false;
    }

    $sourceHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

    return $sourceHost === $host;
}

/**
 * Startet die PHP-Session (nur für Meldungen nach einer Aktion, siehe
 * flash()). Cookie nur per HTTP, nicht für fremde Seiten mitgesendet.
 */
function startSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name('sanlager');

    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
    ]);

    session_start();
}

/**
 * Merkt eine Meldung für die nächste Seite vor (Redirect-nach-POST).
 * Anders als ein Text in der Adresse (?message=...) kann sie nicht von
 * außen untergeschoben werden.
 *
 * @param string $type success|error
 */
function flash(string $text, string $type = 'success'): void
{
    startSession();

    $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}

/**
 * Liefert die vorgemerkte Meldung und entfernt sie (einmalige Anzeige).
 *
 * @return array{text: string, type: string}|null
 */
function takeFlash(): ?array
{
    startSession();

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}
