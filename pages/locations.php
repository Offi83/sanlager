<?php

/*
 * Daten für die Seite: Lagerorte (Liste, Bearbeiten, Komplettumzug).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$locationList = [];

$locationList = $locationRepository->all();

$editLocation = null;
$editLocationHasStock = false;
$transferTargetLocations = [];

if (isset($_GET['edit'])) {

    $editLocationId = (int) $_GET['edit'];

    if ($editLocationId > 0) {
        $editLocation = $locationRepository->find($editLocationId);
    }

    if ($editLocation) {

        $editLocationHasStock = $stock->locationHasStock(
            (int) $editLocation['id']
        );

        $transferTargetLocations = array_values(array_filter(
            $locationRepository->all(),
            static fn (array $location): bool =>
                (int) $location['id'] !== (int) $editLocation['id']
        ));
    }
}
