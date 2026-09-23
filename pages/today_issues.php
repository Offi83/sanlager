<?php

/*
 * Daten für die Seite: Heute ausgebucht (Ausbuchungen inkl. Entsorgungen, Umbuchungen).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$todayIssues = [];
$todayIssueCount = 0;
$todayTransfers = [];
$todayDisposedCount = 0;

$todayIssueCount = $stock->getTodayIssueCount();
$todayTransfers = $stock->getTodayTransfers();

/*
 * Entsorgungen erscheinen in derselben Liste wie die Ausbuchungen
 * (als "entsorgt" gekennzeichnet), zählen aber nicht zur Zahl der
 * Ausbuchungen – Entsorgen ist kein Verbrauch.
 */
$todayIssues = array_merge(
    array_map(
        static fn (array $row): array => $row + ['kind' => 'issue'],
        $stock->getTodayIssues()
    ),
    array_map(
        static fn (array $row): array => $row + ['kind' => 'disposal'],
        $stock->getTodayDisposals()
    )
);

usort(
    $todayIssues,
    static fn (array $a, array $b): int =>
        strcasecmp($a['article_name'], $b['article_name'])
        ?: strcmp((string) $a['expiry_date'], (string) $b['expiry_date'])
);

$todayDisposedCount = array_sum(array_map(
    static fn (array $row): int => $row['kind'] === 'disposal' ? (int) $row['quantity'] : 0,
    $todayIssues
));
