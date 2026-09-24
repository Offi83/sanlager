<?php

/*
 * Daten für die Seite: Artikel anlegen.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

/*
 * Auswahl der Einheit (Verwaltung → Einheiten); vorausgewählt ist die
 * erste der Sortierung.
 */
$unitList = $units->all();

/*
 * Vorausgewählte Kategorie: nach "Anlegen & nächster Artikel" die des
 * zuletzt angelegten (per Redirect), sonst "Sonstiges".
 */
$categoryIdsByName = array_column($categoryList, 'id', 'name');
$requestedCategoryId = (int) ($_GET['category'] ?? 0);

$newArticleCategoryId = in_array($requestedCategoryId, array_map('intval', $categoryIdsByName), true)
    ? $requestedCategoryId
    : (int) ($categoryIdsByName['Sonstiges'] ?? 0);
