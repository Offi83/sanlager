<?php

/*
 * A4-Etikettenbögen als PDF (siehe LabelSheetPdf): dieselbe Auswahl wie
 * die Druckansicht der Sammeletiketten (?page=labels_pdf&qty[ID]=n, beim
 * Einzeletikett qty[ID]=8). Gibt nur das PDF aus, kein HTML.
 *
 * Immer im A4-Aussehen (LabelConfig::a4()), auch wenn zusätzlich ein
 * Etikettendrucker eingerichtet ist.
 */

$labelsPdfArticles = $articles->all();

$labelsPdfQuantities = \LagerApp\LabelActions::quantities(
    is_array($_GET['qty'] ?? null) ? $_GET['qty'] : [],
    $labelsPdfArticles
);

if ($labelsPdfQuantities === []) {
    flash('Bitte bei mindestens einem Artikel eine Anzahl eintragen.', 'error');
    redirect('?page=labels');
}

/*
 * Jeder Artikel so oft wie gewählt, in der Reihenfolge der Artikelliste.
 */
$labelsPdfLabels = [];

foreach ($labelsPdfArticles as $labelsPdfArticle) {
    $quantity = $labelsPdfQuantities[(int) $labelsPdfArticle['id']] ?? 0;

    if ($quantity > 0) {
        array_push($labelsPdfLabels, ...array_fill(0, $quantity, $labelsPdfArticle));
    }
}

$labelsPdf = (new \LagerApp\LabelSheetPdf(\LagerApp\LabelConfig::a4()))->render($labelsPdfLabels);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="etiketten.pdf"');
header('Cache-Control: no-store');
echo $labelsPdf;
exit;
