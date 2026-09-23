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

/**
 * Kleines Inline-SVG-Symbol für Buttons (übernimmt die Textfarbe via
 * currentColor, funktioniert ohne Internet). Dekorativ – der Button
 * braucht zusätzlich Text oder ein aria-label/title.
 *
 * @param string $name undo|trash
 */
function icon(string $name): string
{
    $paths = match ($name) {
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>'
            . '<path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
            . '<path d="M10 11v6"/><path d="M14 11v6"/>',
        default => '',
    };

    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths . '</svg>';
}
