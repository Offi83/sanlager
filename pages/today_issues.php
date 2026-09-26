<?php

/*
 * Daten für die Seite: Heute (Ausbuchungen, Entsorgungen, Umbuchungen,
 * Einlagerungen). Wird von public/index.php eingebunden, bevor HTML
 * ausgegeben wird (Weiterleitungen sind hier also noch möglich). Alle hier
 * gesetzten Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$todayIssues = $reports->getTodayIssues();
$todayDisposals = $reports->getTodayDisposals();
$todayTransfers = $reports->getTodayTransfers();
$todayReceipts = $reports->getTodayReceipts();

/*
 * Umbuchungen je Richtung ("Hauptlager → Rucksack 1"); die Abfrage
 * liefert sie bereits danach sortiert.
 */
$todayTransferGroups = [];

foreach ($todayTransfers as $transfer) {
    $todayTransferGroups[$transfer['from_location_name'] . ' → ' . $transfer['to_location_name']][] = $transfer;
}

/*
 * Kacheln oben: Anzahl der Zeilen je Abschnitt (je Artikel, Charge und
 * Lagerort), nicht die Stückzahl – die steht in den Zeilen darunter.
 * Die Kachel springt zum Abschnitt, sofern es ihn gibt.
 */
$todayCounts = [
    ['id' => 'heute-ausgebucht', 'count' => count($todayIssues), 'one' => 'Ausbuchung', 'many' => 'Ausbuchungen'],
    ['id' => 'heute-entsorgt', 'count' => count($todayDisposals), 'one' => 'Entsorgung', 'many' => 'Entsorgungen'],
    ['id' => 'heute-umgebucht', 'count' => count($todayTransfers), 'one' => 'Umbuchung', 'many' => 'Umbuchungen'],
    ['id' => 'heute-eingelagert', 'count' => count($todayReceipts), 'one' => 'Einlagerung', 'many' => 'Einlagerungen'],
];

/*
 * Abschnitte mit Lagerort-Spalte; die Umbuchungen (je Richtung) folgen
 * in der Vorlage an dritter Stelle.
 */
$todaySections = [
    'heute-ausgebucht' => [
        'title' => 'Heute ausgebucht',
        'rows' => $todayIssues,
        'undo' => 'undo_issue',
        'question' => static fn (array $row): string => quantityText((int) $row['quantity'], $row) . ' '
            . $row['article_name'] . ' wieder in ' . $row['location_name'] . ' einbuchen?',
    ],
    'heute-entsorgt' => [
        'title' => 'Heute entsorgt',
        'rows' => $todayDisposals,
        'undo' => 'undo_disposal',
        'question' => static fn (array $row): string => quantityText((int) $row['quantity'], $row) . ' '
            . $row['article_name'] . ' wieder in ' . $row['location_name'] . ' einbuchen (Entsorgung rückgängig)?',
    ],
    'heute-eingelagert' => [
        'title' => 'Heute eingelagert',
        'rows' => $todayReceipts,
        'undo' => 'undo_receipt',
        'question' => static fn (array $row): string => quantityText((int) $row['quantity'], $row) . ' '
            . $row['article_name'] . ' wieder aus ' . $row['location_name'] . ' entfernen (Einlagerung rückgängig)?',
    ],
];
