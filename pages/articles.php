<?php

/*
 * Daten für die Seite: Artikelliste (Suche, Kategorie-Filter, Bestandskennzahlen).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$search = trim($_GET['search'] ?? '');

$articleList = [];
$selectedCategoryId = null;

$categoryParam = (int) ($_GET['category'] ?? 0);

if ($categoryParam > 0 && $categories->find($categoryParam)) {
    $selectedCategoryId = $categoryParam;
}

$articleList = $articles->all(
    $search,
    $selectedCategoryId
);

/*
 * Bestand, abgelaufene Menge und Mindestbestand-Warnung für alle
 * Artikel mit zwei Abfragen statt drei Abfragen je Artikel.
 */
$articleStockSummaries = $stock->getStockSummaries();
