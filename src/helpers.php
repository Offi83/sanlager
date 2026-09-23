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
    $warningThreshold = date('Y-m-d', strtotime('+90 days'));

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
