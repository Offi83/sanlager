<?php

namespace LagerApp;

use RuntimeException;
use Throwable;

/**
 * Verarbeitet die POST-Aktionen rund um Lagerbewegungen: das Buchen
 * (Ausbuchen/Umbuchen per Scanner oder manueller Eingabe) sowie das
 * artikelbezogene Bestand-buchen-Formular (Einlagern/Ausbuchen/Umbuchen).
 */
class StockActions
{
    use ReadsInput;

    public function __construct(
        private ArticleRepository $articles,
        private LocationRepository $locations,
        private StockRepository $stock,
        private BatchRepository $batches
    ) {
    }

    /**
     * Führt die zu $action passende Aktion mit den Formularwerten aus
     * $input (in der Anwendung $_POST) aus, falls diese Klasse dafür
     * zuständig ist. Für nicht zuständige Aktionen wird null geliefert,
     * damit der Aufrufer einfach alle Action-Klassen nacheinander fragen
     * kann. Ungültige Eingaben werfen eine RuntimeException.
     */
    public function dispatch(?string $action, array $input): ?ActionResult
    {
        return match ($action) {
            'issue' => $this->issue($input),
            'stock_move' => $this->stockMove($input),
            'transfer_all_stock' => $this->transferAllStock($input),
            'undo_issue' => $this->undoToday('issue', $input),
            'undo_transfer' => $this->undoToday('transfer', $input),
            'undo_disposal' => $this->undoToday('disposal', $input),
            'dispose_batch' => $this->disposeBatch($input),
            default => null,
        };
    }

    /**
     * Bucht ein Stück eines gescannten/eingegebenen Artikels ab einem
     * wählbaren Quell-Lagerort (`source`, Standard: erster Lagerort der
     * Sortierung, siehe LocationRepository::defaultLocation()): entweder
     * klassisch aus (`target=issue`, Standard) oder an einen anderen
     * Lagerort um (`target=<location_id>`).
     *
     * `ajax=1` liefert JSON zurück (Kamera-Scanner), sonst erfolgt ein
     * normaler Redirect mit Erfolgsmeldung (per Session, siehe flash()).
     * Fehler werden hier abschließend behandelt (nicht an den Aufrufer
     * weitergereicht), damit der Scanner nach einem Fehlversuch sofort
     * für den nächsten Scan bereit ist.
     */
    private function issue(array $input): ActionResult
    {
        $isAjax = $this->string($input, 'ajax') === '1';

        try {
            $articleNumber = $this->string($input, 'article_number');

            if ($articleNumber === '') {
                throw new RuntimeException(
                    'Bitte eine Artikelnummer eingeben oder scannen.'
                );
            }

            $article = $this->articles->findByArticleNumber(
                $articleNumber
            );

            if (!$article) {
                throw new RuntimeException(
                    'Artikelnummer nicht gefunden: ' .
                    $articleNumber
                );
            }

            $sourceLocationId = $this->int($input, 'source');

            $sourceLocation = $sourceLocationId > 0
                ? $this->locations->find($sourceLocationId)
                : $this->locations->defaultLocation();

            if (!$sourceLocation) {
                throw new RuntimeException(
                    'Der ausgewählte Quell-Lagerort wurde nicht gefunden.'
                );
            }

            $sourceLocationId = (int) $sourceLocation['id'];

            $target = $this->string($input, 'target', 'issue');

            if ($target === '' || $target === 'issue') {
                $result = $this->stock->issueOldest(
                    (int) $article['id'],
                    $sourceLocationId,
                    'Scanner-Ausbuchung aus ' . $sourceLocation['name']
                );

                $actionLabel = 'ausgebucht';
            } else {
                $targetLocationId = (int) $target;

                if (
                    $targetLocationId <= 0
                    || $targetLocationId === $sourceLocationId
                ) {
                    throw new RuntimeException(
                        'Ungültiges Buchungsziel.'
                    );
                }

                $targetLocation = $this->locations->find(
                    $targetLocationId
                );

                if (!$targetLocation) {
                    throw new RuntimeException(
                        'Der ausgewählte Lagerort wurde nicht gefunden.'
                    );
                }

                $result = $this->stock->transferOldest(
                    (int) $article['id'],
                    $sourceLocationId,
                    $targetLocationId,
                    'Scanner-Umbuchung von ' . $sourceLocation['name']
                        . ' nach ' . $targetLocation['name']
                );

                $actionLabel = 'umgebucht nach ' . $targetLocation['name'];
            }

            /*
             * Bei Artikeln ohne MHD (z. B. Mullbinden) wäre "ohne MHD" nur
             * Rauschen – dann entfällt die Angabe ganz.
             */
            $expiryText = match (true) {
                $result['expiry_date'] !== null => formatDate($result['expiry_date']),
                (int) $article['has_expiry'] === 1 => 'ohne MHD',
                default => '',
            };

            /*
             * Es wird stets die Charge mit dem ältesten MHD gebucht.
             * Ist diese Charge bereits abgelaufen, wird das hier
             * zusätzlich angezeigt, damit niemand unbemerkt
             * abgelaufenes Material ausgebucht oder umgebucht bekommt.
             */
            $expiryWarning = expiryInfo($result['expiry_date'])['warning'];

            if ($expiryWarning !== '') {
                $expiryText .= ' – ' . $expiryWarning;
            }

            if ($isAjax) {
                return ActionResult::json([
                    'success' => true,
                    'article_name' => $article['name'],
                    'article_number' => $articleNumber,
                    'unit' => $article['unit'],
                    'unit_plural' => $article['unit_plural'],
                    'expiry_date' => $expiryText,
                    'action_label' => $actionLabel,
                    'expired' => $expiryWarning !== ''
                ]);
            }

            return ActionResult::redirect(
                /*
                 * Von/Nach mitgeben, damit die nächste Buchung (z. B. per
                 * Hand-Barcodescanner mit Enter) wieder in dieselbe
                 * Richtung geht, statt auf "Standard-Lagerort/Ausbuchen"
                 * zurückzufallen – wie beim Kamera-Scan ohne Neuladen.
                 */
                '?page=issue'
                    . '&source=' . $sourceLocationId
                    . '&target=' . urlencode($target === '' ? 'issue' : $target),
                $article['name'] . ' – ' . quantityText(1, $article) . ' '
                    . $actionLabel . ($expiryText !== '' ? ' (' . $expiryText . ')' : ''),
                // Abgelaufene Charge gebucht: rot statt grün hervorheben.
                $expiryWarning !== '' ? 'error' : 'success'
            );
        } catch (Throwable $exception) {
            if ($isAjax) {
                return ActionResult::json([
                    'success' => false,
                    'error' => userMessage($exception)
                ], 400);
            }

            throw $exception;
        }
    }

    /**
     * "Bestand buchen" auf der Artikelseite. Der Vorgang ergibt sich aus
     * Von und Nach:
     *
     *   from=receipt,  to=<Lagerort>  → Einlagern
     *   from=<Lagerort>, to=issue     → Ausbuchen
     *   from=<Lagerort>, to=<anderer> → Umbuchen
     */
    private function stockMove(array $input): ActionResult
    {
        $articleId = $this->int($input, 'article_id');
        $quantity = $this->int($input, 'quantity');
        $from = $this->string($input, 'from');
        $to = $this->string($input, 'to');

        $article = $this->articles->find($articleId);

        if (!$article) {
            throw new RuntimeException(
                'Bitte einen Artikel auswählen.'
            );
        }

        if ($quantity <= 0) {
            throw new RuntimeException(
                'Die Menge muss größer als 0 sein.'
            );
        }

        if ($from === 'receipt' && $to === 'issue') {
            throw new RuntimeException(
                'Bitte bei Von oder Nach einen Lagerort auswählen.'
            );
        }

        $fromLocation = $from === 'receipt' ? null : $this->locations->find((int) $from);
        $toLocation = $to === 'issue' ? null : $this->locations->find((int) $to);

        if (
            ($from !== 'receipt' && !$fromLocation)
            || ($to !== 'issue' && !$toLocation)
        ) {
            throw new RuntimeException(
                'Bitte gültige Lagerorte für Von und Nach auswählen.'
            );
        }

        if ($fromLocation && $toLocation && (int) $fromLocation['id'] === (int) $toLocation['id']) {
            throw new RuntimeException(
                'Von und Nach dürfen nicht derselbe Lagerort sein.'
            );
        }

        $movementType = match (true) {
            $fromLocation === null => 'receipt',
            $toLocation === null => 'issue',
            default => 'transfer',
        };

        /*
         * Lagerort, an dem eingelagert bzw. von dem ausgebucht/umgebucht wird.
         */
        $location = $fromLocation ?? $toLocation;
        $locationId = (int) $location['id'];

        /*
         * MHD-Auswahl:
         *
         * none = ohne MHD
         * ID   = vorhandenes MHD
         * new  = neues MHD
         */
        $batchSelection = $this->string(
            $input,
            'batch_selection',
            'none'
        );

        $batchId = null;

        /*
         * Rückfrage zu einem ungewöhnlichen MHD bestätigt, siehe
         * assertPlausibleExpiry().
         */
        $expiryConfirmed = $this->string($input, 'confirm_expiry') === '1';

        /*
         * Neues MHD
         */
        if ($batchSelection === 'new') {
            if ($movementType !== 'receipt') {
                throw new RuntimeException(
                    'Ein neues MHD kann nur beim Einlagern angelegt werden.'
                );
            }

            if ((int) $article['has_expiry'] !== 1) {
                throw new RuntimeException(
                    'Dieser Artikel hat kein MHD (siehe „Artikel bearbeiten“).'
                );
            }

            $expiryDate = $this->string($input, 'expiry_date');

            if ($expiryDate === '') {
                throw new RuntimeException(
                    'Bitte ein MHD eingeben.'
                );
            }

            $normalizedExpiryDate = normalizeDate($expiryDate);

            if ($normalizedExpiryDate === null) {
                throw new RuntimeException(
                    'Ungültiges MHD: ' . $expiryDate
                    . ' (erwartet z. B. 31.12.2027).'
                );
            }

            $expiryDate = $normalizedExpiryDate;

            $this->assertPlausibleExpiry($expiryDate, $expiryConfirmed);

            $batchId = $this->batches->findOrCreate(
                $articleId,
                $expiryDate
            );
        }

        /*
         * Vorhandenes MHD
         */
        elseif ($batchSelection !== 'none') {
            $batchId = (int) $batchSelection;

            if ($batchId <= 0) {
                throw new RuntimeException(
                    'Ungültige MHD-Auswahl.'
                );
            }

            $batch = $this->batches->find($batchId);

            if (!$batch) {
                throw new RuntimeException(
                    'Das ausgewählte MHD wurde nicht gefunden.'
                );
            }

            if ((int) $batch['article_id'] !== $articleId) {
                throw new RuntimeException(
                    'Das MHD gehört nicht zu diesem Artikel.'
                );
            }

            if ($movementType === 'receipt') {
                $this->assertPlausibleExpiry((string) $batch['expiry_date'], $expiryConfirmed);
            }
        }

        $note = $this->string($input, 'note');

        if ($movementType === 'transfer') {
            $this->stock->transferBatch(
                $articleId,
                $batchId,
                $locationId,
                (int) $toLocation['id'],
                $quantity,
                $note !== ''
                    ? $note
                    : 'Umbuchung von ' . $location['name'] . ' nach ' . $toLocation['name']
            );

            $message = quantityText($quantity, $article) . ' umgebucht: ' . $location['name']
                . ' → ' . $toLocation['name'];
        } else {
            $this->stock->move(
                $articleId,
                $locationId,
                $quantity,
                $movementType,
                $note !== '' ? $note : null,
                $batchId
            );

            $message = quantityText($quantity, $article)
                . ($movementType === 'receipt'
                    ? ' eingelagert in ' . $location['name']
                    : ' ausgebucht aus ' . $location['name']);
        }

        /*
         * Von/Nach mitgeben, damit das Formular für die nächste Buchung so
         * eingestellt bleibt (wie auf der Buchen-Seite).
         */
        return ActionResult::redirect(
            '?page=article&id=' . $articleId
            . '&from=' . urlencode($from)
            . '&to=' . urlencode($to),
            $message
        );
    }

    /**
     * Prüft das MHD beim Einlagern auf Tippfehler: Offensichtlich falsche
     * Jahre (vor 2000, mehr als 30 Jahre voraus – z. B. "0027" bei
     * Handeingabe) werden abgelehnt. Ein MHD in der Vergangenheit oder
     * mehr als 10 Jahre voraus kann stimmen, muss aber bestätigt werden
     * (Rückfrage in stock-form.js, setzt `confirm_expiry=1`).
     *
     * @param string $expiryDate Y-m-d
     */
    private function assertPlausibleExpiry(string $expiryDate, bool $confirmed): void
    {
        if ($expiryDate < '2000-01-01' || $expiryDate > date('Y-m-d', strtotime('+30 years'))) {
            throw new RuntimeException(
                'Ungültiges MHD: ' . formatDate($expiryDate) . '. Bitte das Jahr prüfen.'
            );
        }

        if ($confirmed) {
            return;
        }

        if ($expiryDate < date('Y-m-d')) {
            throw new RuntimeException(
                'Das MHD ' . formatDate($expiryDate) . ' ist bereits abgelaufen. '
                . 'Bitte prüfen und beim Einlagern bestätigen.'
            );
        }

        if ($expiryDate > date('Y-m-d', strtotime('+10 years'))) {
            throw new RuntimeException(
                'Das MHD ' . formatDate($expiryDate) . ' liegt über 10 Jahre in der Zukunft. '
                . 'Bitte prüfen und beim Einlagern bestätigen.'
            );
        }
    }

    /**
     * Verschiebt den kompletten Bestand eines Lagerorts auf einen
     * anderen, z. B. um eine Kiste nach einem Dienst vollständig
     * zurück ins Lager zu räumen. Wird über das Bearbeiten-Formular
     * eines Lagerorts auf der Lagerorte-Seite ausgelöst.
     */
    private function transferAllStock(array $input): ActionResult
    {
        $fromLocationId = $this->int($input, 'from_location_id');
        $toLocationId = $this->int($input, 'to_location_id');

        if ($fromLocationId <= 0 || $toLocationId <= 0) {
            throw new RuntimeException(
                'Bitte Quell- und Ziel-Lagerort auswählen.'
            );
        }

        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException(
                'Quell- und Ziellagerort dürfen nicht identisch sein.'
            );
        }

        $fromLocation = $this->locations->find($fromLocationId);
        $toLocation = $this->locations->find($toLocationId);

        if (!$fromLocation || !$toLocation) {
            throw new RuntimeException(
                'Lagerort wurde nicht gefunden.'
            );
        }

        $moved = $this->stock->transferAllStock(
            $fromLocationId,
            $toLocationId,
            'Komplettumzug von ' . $fromLocation['name']
                . ' nach ' . $toLocation['name']
        );

        return ActionResult::redirect(
            '?page=locations&edit=' . $fromLocationId,
            // Je Einheit statt "47 Stück" über Rollen, Paare usw. hinweg.
            $moved !== []
                ? 'Bestand nach ' . $toLocation['name']
                    . ' verschoben (' . quantitiesByUnit($moved) . ').'
                : 'Es war kein Bestand zum Verschieben vorhanden.'
        );
    }

    /**
     * Macht heutige Buchungen rückgängig (Seite "Heute"): die übergebene
     * Menge – der Button nimmt bewusst die ganze Zeile zurück –, als
     * Gegenbuchung.
     * `batch_id` leer/0 bedeutet "ohne MHD". Bei Umbuchungen bezeichnet
     * `location_id` die ursprüngliche Quelle, `to_location_id` das Ziel.
     *
     * @param string $kind issue|transfer|disposal
     */
    private function undoToday(string $kind, array $input): ActionResult
    {
        $articleId = $this->int($input, 'article_id');
        $locationId = $this->int($input, 'location_id');
        $quantity = $this->int($input, 'quantity');
        $batchId = $this->int($input, 'batch_id') ?: null;

        $article = $this->articles->find($articleId);
        $location = $this->locations->find($locationId);

        if (!$article || !$location) {
            throw new RuntimeException(
                'Ungültige Buchung.'
            );
        }

        if ($kind === 'transfer') {
            $toLocation = $this->locations->find(
                $this->int($input, 'to_location_id')
            );

            if (!$toLocation) {
                throw new RuntimeException(
                    'Ungültige Buchung.'
                );
            }

            $this->stock->reverseTodayTransfer(
                $articleId,
                $batchId,
                $locationId,
                (int) $toLocation['id'],
                $quantity
            );

            $done = 'von ' . $toLocation['name'] . ' zurück nach ' . $location['name'] . ' gebucht';
        } elseif ($kind === 'disposal') {
            $this->stock->reverseTodayDisposal($articleId, $batchId, $locationId, $quantity);

            $done = 'Entsorgung rückgängig, wieder in ' . $location['name'];
        } else {
            $this->stock->reverseTodayIssue($articleId, $batchId, $locationId, $quantity);

            $done = 'zurück nach ' . $location['name'] . ' gebucht';
        }

        return ActionResult::redirect(
            '?page=today_issues',
            $article['name'] . ' – ' . quantityText($quantity, $article) . ' ' . $done
        );
    }

    /**
     * Entsorgt eine abgelaufene Charge an einem Lagerort vollständig.
     * Aufrufbar aus MHD-Übersicht, Artikel- und Lagerort-Detailseite;
     * `return` bestimmt, wohin danach zurückgeleitet wird.
     */
    private function disposeBatch(array $input): ActionResult
    {
        $articleId = $this->int($input, 'article_id');
        $batchId = $this->int($input, 'batch_id');
        $locationId = $this->int($input, 'location_id');

        $article = $this->articles->find($articleId);
        $location = $this->locations->find($locationId);

        if (!$article || !$location || $batchId <= 0) {
            throw new RuntimeException(
                'Ungültige Charge.'
            );
        }

        $quantity = $this->stock->disposeExpiredBatch(
            $articleId,
            $batchId,
            $locationId
        );

        $return = match ($this->string($input, 'return')) {
            'article' => '?page=article&id=' . $articleId,
            'location' => '?page=location&id=' . $locationId,
            default => '?page=expiry',
        };

        return ActionResult::redirect(
            $return,
            $article['name'] . ' – ' . quantityText($quantity, $article)
                . ' aus ' . $location['name'] . ' entsorgt'
                . ' (rückgängig unter „Heute“)'
        );
    }
}
