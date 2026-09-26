<?php

/*
|--------------------------------------------------------------------------
| Wiederkehrende Bausteine der Seitenvorlagen
|--------------------------------------------------------------------------
|
| Kleine Funktionen, die HTML für Buttons/Formulare erzeugen, die auf
| mehreren Seiten vorkommen. Allgemeine Helfer ohne HTML (h(), icon(),
| formatDate(), ...) liegen in src/helpers.php.
|--------------------------------------------------------------------------
*/

/**
 * Button "Entsorgen" für eine abgelaufene Charge an einem Lagerort
 * (MHD-Übersicht, Artikel- und Lagerort-Detailseite). Entnimmt nach
 * Rückfrage die komplette Menge, siehe StockActions::disposeBatch().
 *
 * @param string $return Seite, auf die danach zurückgeleitet wird:
 *                       expiry|article|location
 */
function renderDisposeForm(
    int $articleId,
    int $batchId,
    int $locationId,
    string $question,
    string $return
): string {
    return '<form method="post" class="dispose-form" onsubmit="return confirm('
        . h(json_encode($question, JSON_UNESCAPED_UNICODE)) . ');">'
        . '<input type="hidden" name="action" value="dispose_batch">'
        . '<input type="hidden" name="article_id" value="' . $articleId . '">'
        . '<input type="hidden" name="batch_id" value="' . $batchId . '">'
        . '<input type="hidden" name="location_id" value="' . $locationId . '">'
        . '<input type="hidden" name="return" value="' . h($return) . '">'
        . '<button type="submit" class="button button-danger small icon-button"'
        . ' title="Entsorgen" aria-label="Entsorgen">' . icon('trash') . '</button>'
        . '</form>';
}

/**
 * Rückgängig-Button (Pfeil) für eine Zeile auf "Heute": nimmt
 * nach Rückfrage die ganze Zeile zurück. Gegenbuchung statt Löschen,
 * siehe StockActions::undoToday().
 *
 * @param string $action undo_issue|undo_transfer|undo_disposal
 * @param array<string, int|null> $fields versteckte Felder (article_id, batch_id, ...)
 * @param string $question Rückfrage, z. B. "3 Stück Mullbinde zurück nach Hauptlager buchen?"
 */
function renderUndoForm(
    string $action,
    array $fields,
    int $quantity,
    string $question
): string {
    $html = '<form method="post" onsubmit="return confirm('
        . h(json_encode($question, JSON_UNESCAPED_UNICODE))
        . ');">'
        . '<input type="hidden" name="action" value="' . h($action) . '">';

    foreach ($fields + ['quantity' => $quantity] as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . (int) $value . '">';
    }

    return $html
        . '<button type="submit" class="button button-secondary small icon-button"'
        . ' title="Rückgängig" aria-label="Rückgängig">' . icon('undo') . '</button>'
        . '</form>';
}

/**
 * Ein A4-Bogen Etiketten (2 × 4 à 105 × 74 mm, siehe .label-print-page in
 * app.css) – für das Einzeletikett und die Sammeletiketten. Ein Platz ist
 * entweder ['article' => Artikel, 'qr' => SVG oder null] oder null für
 * einen frei bleibenden Platz (Rest des letzten Bogens).
 *
 * @param array<int, array{article: array, qr: ?string}|null> $slots
 */
function renderLabelSheet(array $slots): string
{
    $html = '<div class="label-print-page">';

    foreach ($slots as $slot) {
        if ($slot === null) {
            $html .= '<div class="label label-empty" aria-hidden="true"></div>';
            continue;
        }

        $article = $slot['article'];

        $html .= '<div class="label">'
            . '<div class="label-category">' . h($article['category_name'] ?? 'Sonstiges') . '</div>'
            . '<div class="label-content">'
            . '<div class="label-text">'
            . '<div class="label-name">' . h($article['name']) . '</div>'
            . (!empty($article['article_number'])
                ? '<div class="label-number">' . h($article['article_number']) . '</div>'
                : '')
            . '</div>'
            // SVG aus QrCodeGenerator, enthält nur die Artikelnummer.
            . ($slot['qr'] !== null ? '<div class="label-qr">' . $slot['qr'] . '</div>' : '')
            . '</div>'
            . '</div>';
    }

    return $html . '</div>';
}
