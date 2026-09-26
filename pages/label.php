<?php

/*
 * Daten für das Etikett: dieselben wie für die Artikel-Detailseite
 * (Artikel laden, bei unbekannter ID zurück zur Artikelliste), dazu ein
 * Bogen mit acht gleichen Etiketten – gerendert wie die Sammeletiketten
 * (siehe pages/labels.php, renderLabelSheet()).
 */

require __DIR__ . '/article.php';

$labelSheets = [
    array_fill(0, 8, [
        'article' => $article,
        'qr' => !empty($article['article_number'])
            ? (new \LagerApp\QrCodeGenerator())->generate($article['article_number'])
            : null,
    ]),
];
