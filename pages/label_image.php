<?php

/*
 * Ein Etikett als PNG (siehe LabelImage): Vorschau für den
 * Etikettendrucker – dasselbe Bild, das an den Drucker geht – und die
 * Etiketten auf den A4-Bögen. Gibt nur das Bild aus, kein HTML.
 * Unbekannte Artikel: 404.
 */

$labelImageArticle = $articles->findActive((int) ($_GET['id'] ?? 0));

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
