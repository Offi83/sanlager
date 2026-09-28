<?php

/*
 * Vorschau eines Etiketts für den Etikettendrucker als PNG – dasselbe
 * Bild, das an den Drucker geht (siehe LabelImage). Gibt nur das Bild aus,
 * kein HTML. Ohne Etikettendrucker (LABEL_OUTPUT=a4) und für unbekannte
 * Artikel: 404.
 */

$labelImageArticle = $labelConfig->usesPrinter()
    ? $articles->findActive((int) ($_GET['id'] ?? 0))
    : null;

if (!$labelImageArticle) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden';
    exit;
}

$labelImagePng = (new \LagerApp\LabelImage($labelConfig))->png($labelImageArticle);

header('Content-Type: image/png');
header('Cache-Control: no-store');
echo $labelImagePng;
exit;
