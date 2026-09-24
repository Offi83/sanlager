<?php

/*
 * Daten für die Seite: Inhalt eines Lagerorts.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$viewLocation = null;
$locationStockRows = [];

$viewLocationId = (int) ($_GET['id'] ?? 0);

if ($viewLocationId <= 0) {
    redirect('?page=locations');
}

$viewLocation = $locationRepository->find($viewLocationId);

if (!$viewLocation) {
    redirect('?page=locations');
}

$locationStockRows = $stock->getStockAtLocationDetailed(
    $viewLocationId
);

/*
 * Was hier unter dem Mindestbestand liegt – die Seite zeigt sonst nur,
 * was da ist, nicht was fehlt. Hinweis mit Link zur Auffüllliste.
 */
$locationMissing = array_values(array_filter(
    $reports->getLowStockItems(),
    static fn (array $row): bool => (int) $row['location_id'] === $viewLocationId
));
