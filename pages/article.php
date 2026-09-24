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
$articleSummary = \LagerApp\StockRepository::EMPTY_SUMMARY;
$qrCode = null;
$articleHasExpiry = true;
$showExpiryCard = true;
$stockFormFrom = 'receipt';
$stockFormTo = 'issue';
$batchStockByLocation = [];

$articleId = (int) ($_GET['id'] ?? 0);

if ($articleId <= 0) {
    redirect('?page=articles');
}

$article = $articles->find($articleId);

/*
 * Unbekannte und gelöschte Artikel: zurück zur Liste. Gelöschte würden
 * sich sonst per Adresse weiter bebuchen lassen – der Bestand wäre dann
 * nirgends mehr sichtbar.
 */
if (!$article || (int) $article['active'] !== 1) {
    redirect('?page=articles');
}

if ($page === 'article') {

    $articleStock = $stock->getStockForArticle(
        $articleId
    );

    /*
     * MHD-Auswahl im Formular: nur Chargen, von denen noch etwas da ist.
     * Aufgebrauchte blieben sonst über die Jahre in der Liste stehen; ein
     * neues MHD, das es schon gab, findet findOrCreate() trotzdem wieder.
     */
    $articleBatches = array_values(array_filter(
        $stock->getStockByBatch($articleId),
        static fn (array $batch): bool => (int) $batch['quantity'] > 0
    ));

    $articleBatchesByLocation = $stock->getStockByBatchAndLocation(
        $articleId
    );

    $locations = $locationRepository->all();

    $articleSummary = $stock->getStockSummary($articleId);

    /*
     * Artikel ohne MHD (z. B. Mullbinden): MHD-Auswahl und "Bestand nach
     * MHD" entfallen – außer es liegt noch alter Bestand mit MHD da.
     */
    $articleHasExpiry = (int) $article['has_expiry'] === 1;

    $showExpiryCard = $articleHasExpiry || array_filter(
        $articleBatchesByLocation,
        static fn (array $row): bool => $row['batch_id'] !== null
    ) !== [];

    /*
     * QR-Code nur mit Artikelnummer (seit dem Bearbeiten-Pflichtfeld
     * eigentlich immer vorhanden, ältere Daten können aber ohne sein).
     */
    $qrCode = !empty($article['article_number'])
        ? (new \LagerApp\QrCodeGenerator())->generate($article['article_number'])
        : null;

    /*
     * "Bestand buchen": Von/Nach der letzten Buchung beibehalten
     * (kommen per Redirect, siehe StockActions::stockMove()), sonst
     * Einlagern in den Standard-Lagerort (erster der festgelegten
     * Reihenfolge, siehe LocationRepository::defaultLocation()).
     * "receipt" = Einlagern (Von), "issue" = Ausbuchen (Nach), sonst
     * Lagerort-ID.
     */
    $locationIds = array_map('strval', array_column($locations, 'id'));

    $stockFormFrom = in_array($_GET['from'] ?? '', ['receipt', ...$locationIds], true)
        ? $_GET['from']
        : 'receipt';

    $stockFormTo = in_array($_GET['to'] ?? '', ['issue', ...$locationIds], true)
        ? $_GET['to']
        : ($locationIds[0] ?? 'issue');

    /*
     * Bestand je Charge ("none" = ohne MHD) und Lagerort, damit die
     * MHD-Auswahl beim Ausbuchen/Umbuchen zeigt, was am gewählten
     * Lagerort tatsächlich liegt (siehe stock-form.js).
     */
    $batchStockByLocation = [];

    foreach ($articleBatchesByLocation as $row) {
        $batchKey = $row['batch_id'] === null ? 'none' : (string) (int) $row['batch_id'];
        $batchStockByLocation[$batchKey][(int) $row['location_id']] = (int) $row['quantity'];
    }
}
