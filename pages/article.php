<?php

/*
 * Daten für die Seite: Artikel-Detailseite und Etikett (Artikel laden; Bestände nur für die Detailseite).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$article = null;
$articleStock = [];
$articleBatches = [];
$articleBatchesByLocation = [];
$locations = [];

$articleId = (int) ($_GET['id'] ?? 0);

if ($articleId <= 0) {
    redirect('?page=articles');
}

$article = $articles->find($articleId);

if (!$article) {
    redirect('?page=articles');
}

if ($page === 'article') {

    $articleStock = $stock->getStockForArticle(
        $articleId
    );

    $articleBatches = $stock->getStockByBatch(
        $articleId
    );

    $articleBatchesByLocation = $stock->getStockByBatchAndLocation(
        $articleId
    );

    $locations = $stock->locations();
}
