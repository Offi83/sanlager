<?php

/*
 * Daten für die Seite: Heute ausgebucht (Ausbuchungen inkl. Entsorgungen, Umbuchungen).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$todayIssues = [];
$todayTransfers = [];

$todayTransfers = $reports->getTodayTransfers();

/*
 * Entsorgungen erscheinen in derselben Liste wie die Ausbuchungen
 * (als "entsorgt" gekennzeichnet), zählen aber nicht zur Zahl der
 * Ausbuchungen – Entsorgen ist kein Verbrauch.
 */
$todayIssues = array_merge(
    array_map(
        static fn (array $row): array => $row + ['kind' => 'issue'],
        $reports->getTodayIssues()
    ),
    array_map(
        static fn (array $row): array => $row + ['kind' => 'disposal'],
        $reports->getTodayDisposals()
    )
);

usort(
    $todayIssues,
    static fn (array $a, array $b): int =>
        strcasecmp($a['article_name'], $b['article_name'])
        ?: strcmp((string) $a['expiry_date'], (string) $b['expiry_date'])
);

/*
 * Zusammenfassung oben, je Einheit ("18 Stück · 12 Paar") statt einer
 * Summe über verschiedene Einheiten.
 */
$todayIssuedSummary = quantitiesByUnit(array_filter(
    $todayIssues,
    static fn (array $row): bool => $row['kind'] === 'issue'
));

$todayDisposedSummary = quantitiesByUnit(array_filter(
    $todayIssues,
    static fn (array $row): bool => $row['kind'] === 'disposal'
));
