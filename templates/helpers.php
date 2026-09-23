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
 * Rückgängig-Button (Pfeil) für eine Zeile auf "Heute ausgebucht": nimmt
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
