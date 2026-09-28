<?php

/*
 * Daten für das Etikett: dieselben wie für die Artikel-Detailseite
 * (Artikel laden, bei unbekannter ID zurück zur Artikelliste).
 *
 * A4 (Standard): ein Bogen mit acht gleichen Etiketten – gerendert wie
 * die Sammeletiketten (siehe pages/labels.php, renderLabelSheet()), mit
 * demselben Bild wie beim Etikettendrucker.
 * Etikettendrucker (LABEL_OUTPUT=printer): Vorschau (?page=label_image),
 * „Drucken“ schickt ein Etikett per POST-Aktion print_labels.
 */

require __DIR__ . '/article.php';

if ($labelConfigError !== null) {
    $error ??= $labelConfigError;
}

$labelSheets = [];

if (!$labelConfig->usesPrinter()) {
    $labelSheets = [array_fill(0, 8, $article)];
}
