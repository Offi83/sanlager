<?php

/*
 * Daten für die Seite: Einheiten (Verwaltung).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$unitList = $units->all();

$editUnit = isset($_GET['edit'])
    ? $units->find((int) $_GET['edit'])
    : null;
