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

    $expiry = strtotime($date);

    if ($expiry === false) {
        return $none;
    }

    $today = strtotime(date('Y-m-d'));
    $warningThreshold = strtotime('+90 days');

    if ($expiry < $today) {
        return ['class' => 'expiry-expired', 'warning' => 'ABGELAUFEN'];
    }

    if ($expiry <= $warningThreshold) {
        return ['class' => 'expiry-warning', 'warning' => 'MHD bald erreicht'];
    }

    return $none;
}
