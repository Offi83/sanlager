<?php

/*
 * Daten für die Seite: Artikel bearbeiten.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$editArticle = $articles->find((int) ($_GET['id'] ?? 0));

/*
 * Unbekannte und gelöschte Artikel: zurück zur Liste.
 */
if (!$editArticle || (int) $editArticle['active'] !== 1) {
    redirect('?page=articles');
}

/*
 * Bestand einschließlich abgelaufener Chargen: Solange etwas da ist,
 * lässt sich der Artikel nicht löschen.
 */
$editArticleStock = $stock->getPhysicalStock((int) $editArticle['id']);

/*
 * Auswahl der Einheit (Verwaltung → Einheiten).
 */
$unitList = $units->all();
