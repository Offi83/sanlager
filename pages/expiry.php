<?php

/*
 * Daten für die Seite: MHD-Übersicht.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 *
 * Gruppiert wie die Auffüll-Liste: je Lagerort (in dessen Reihenfolge)
 * eine Karte, darin nach Kategorie und Artikel – so lässt sich ein
 * Rucksack Fach für Fach durchsehen.
 */

$expiryGroups = [];
$expiredCount = 0;
$expiringSoonCount = 0;

/*
 * Vorlaufzeit wie im Wochenbericht (REPORT_EXPIRY_DAYS, Standard 90).
 */
$expiryDays = expiryWarningDays();

$expiringBatches = $reports->getExpiringBatches($expiryDays);

usort(
    $expiringBatches,
    static fn (array $a, array $b): int =>
        [(int) $a['location_sort_order'], mb_strtolower($a['location_name']), (int) ($a['category_sort_order'] ?? 9999), mb_strtolower($a['category_name'] ?? ''), mb_strtolower($a['article_name']), $a['expiry_date']]
        <=> [(int) $b['location_sort_order'], mb_strtolower($b['location_name']), (int) ($b['category_sort_order'] ?? 9999), mb_strtolower($b['category_name'] ?? ''), mb_strtolower($b['article_name']), $b['expiry_date']]
);

foreach ($expiringBatches as $row) {
    $locationId = (int) $row['location_id'];

    $expiryGroups[$locationId] ??= [
        'location_id' => $locationId,
        'location_name' => $row['location_name'],
        'expired' => 0,
        'soon' => 0,
        'items' => [],
    ];

    $row['expiry'] = expiryInfo($row['expiry_date']);

    if ($row['expiry']['class'] === 'expiry-expired') {
        $expiryGroups[$locationId]['expired']++;
        $expiredCount++;
    } elseif ($row['expiry']['class'] === 'expiry-warning') {
        $expiryGroups[$locationId]['soon']++;
        $expiringSoonCount++;
    }

    $expiryGroups[$locationId]['items'][] = $row;
}
