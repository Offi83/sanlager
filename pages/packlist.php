<?php

/*
 * Daten für die Seiten: Packliste eines Lagerorts (zum Ausdrucken,
 * ?page=packlist) und Inventur (Zählung eingeben, ?page=inventory –
 * bindet diese Datei ein). Wird von public/index.php eingebunden, bevor
 * HTML ausgegeben wird (Weiterleitungen sind hier also noch möglich).
 * Alle hier gesetzten Variablen stehen der Vorlage templates/pages/ zur
 * Verfügung.
 *
 * Beide zeigen je Artikel Soll (Mindestbestand an diesem Lagerort) und
 * Ist (Bestand je MHD), siehe StockRepository::getLocationChecklist().
 */

$viewLocationId = (int) ($_GET['id'] ?? 0);
$viewLocation = $viewLocationId > 0 ? $locationRepository->find($viewLocationId) : null;

if (!$viewLocation) {
    redirect('?page=locations');
}

$checklist = $stock->getLocationChecklist($viewLocationId);
