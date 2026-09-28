<?php

/*
 * Daten für die Seite: Sammeletiketten (mehrere Artikel auf einmal).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 *
 * Zwei Ansichten, beide per GET (es wird nichts gespeichert):
 *
 *   ?page=labels[&category=ID]       Auswahl: Anzahl je Artikel
 *   ?page=labels&print=1&qty[ID]=n   Bögen zum Drucken
 *
 * `category` (aus der Artikelliste) wählt die Artikel dieser Kategorie
 * mit je einem Etikett vor. Gedruckt wird immer ab dem ersten Platz eines
 * neuen Bogens: Angebrochene Bögen führten zu oft zu Papierstau.
 *
 * Mit Etikettendrucker (LABEL_OUTPUT=printer) gibt es nur die Auswahl;
 * sie wird per POST (Aktion print_labels) an den Drucker geschickt.
 */

/*
 * Ein Bogen: 2 × 4 Etiketten à 105 × 74 mm (wie das Einzeletikett,
 * siehe .label-print-page in app.css).
 */
$labelsPerSheet = 8;

$labelArticles = $articles->all();

if ($labelConfigError !== null) {
    $error ??= $labelConfigError;
}

/*
 * Anzahl je Artikel aus der Adresse – nur aktive Artikel, 0–99. Ist
 * das Drucken fehlgeschlagen (z. B. Drucker aus), bleibt die
 * abgeschickte Auswahl stehen.
 */
$requestedQuantities = $action === 'print_labels' && is_array($_POST['qty'] ?? null)
    ? $_POST['qty']
    : (is_array($_GET['qty'] ?? null) ? $_GET['qty'] : []);

$labelQuantities = \LagerApp\LabelActions::quantities($requestedQuantities, $labelArticles);

/*
 * Vorauswahl aus der Artikelliste: alle Artikel der Kategorie je einmal.
 */
$labelCategoryId = (int) ($_GET['category'] ?? 0);

if ($labelQuantities === [] && $labelCategoryId > 0) {
    foreach ($labelArticles as $labelArticle) {
        if ((int) $labelArticle['category_id'] === $labelCategoryId) {
            $labelQuantities[(int) $labelArticle['id']] = 1;
        }
    }
}

$labelCount = array_sum($labelQuantities);

/*
 * Druckansicht nur für A4-Bögen; mit Etikettendrucker wird aus der
 * Auswahl heraus per POST gedruckt (LabelActions), eine alte Adresse mit
 * print=1 zeigt dann die Auswahl.
 */
$labelPrint = ($_GET['print'] ?? '') === '1' && $labelCount > 0 && !$labelConfig->usesPrinter();

if (($_GET['print'] ?? '') === '1' && $labelCount === 0) {
    $error = 'Bitte bei mindestens einem Artikel eine Anzahl eintragen.';
}

/*
 * Bögen zum Drucken: jeder Artikel so oft wie gewählt
 * (in der Reihenfolge der Artikelliste), aufgeteilt in Bögen zu je acht.
 * Der QR-Code wird je Artikel nur einmal erzeugt.
 */
$labelSheets = [];

if ($labelPrint) {
    $labelSlots = [];
    $labelQrGenerator = new \LagerApp\QrCodeGenerator();

    foreach ($labelArticles as $labelArticle) {
        $quantity = $labelQuantities[(int) $labelArticle['id']] ?? 0;

        if ($quantity === 0) {
            continue;
        }

        $label = [
            'article' => $labelArticle,
            'qr' => !empty($labelArticle['article_number'])
                ? $labelQrGenerator->generate($labelArticle['article_number'])
                : null,
        ];

        array_push($labelSlots, ...array_fill(0, $quantity, $label));
    }

    $labelSheets = array_chunk($labelSlots, $labelsPerSheet);

    /*
     * Letzten Bogen auffüllen, damit das Raster gleich bleibt.
     */
    $lastSheet = array_key_last($labelSheets);
    $labelSheets[$lastSheet] = array_pad($labelSheets[$lastSheet], $labelsPerSheet, null);
}
