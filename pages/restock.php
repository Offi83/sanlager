<?php

/*
 * Daten für die Seite: Auffüll-Liste.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 *
 * Alles, was an einem Lagerort unter dem Mindestbestand liegt, gruppiert
 * nach Lagerort ("Rucksack 2: es fehlen 3× …"). Für die übrigen Lagerorte
 * steht dabei, wie viel davon im Standard-Lagerort vorhanden ist; fehlt
 * etwas im Standard-Lagerort selbst, muss es nachbestellt werden.
 */

$restockGroups = [];
$restockItemCount = 0;

$restockDefaultLocation = $locationRepository->defaultLocation();
$restockDefaultId = (int) ($restockDefaultLocation['id'] ?? 0);

$restockDefaultStock = $restockDefaultId > 0
    ? $reports->getUsableQuantitiesAtLocation($restockDefaultId)
    : [];

/*
 * getLowStockItems() ist bereits nach Lagerort und Kategorie sortiert.
 */
foreach ($reports->getLowStockItems() as $row) {

    $locationId = (int) $row['location_id'];

    $restockGroups[$locationId] ??= [
        'location_id' => $locationId,
        'location_name' => $row['location_name'],
        'is_default' => $locationId === $restockDefaultId,
        'items' => [],
    ];

    $restockGroups[$locationId]['items'][] = $row + [
        'default_quantity' => $restockDefaultStock[(int) $row['article_id']] ?? 0,
    ];

    $restockItemCount++;
}
