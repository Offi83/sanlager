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
            'undo_receipt' => $this->undoToday('receipt', $input),
            'dispose_batch' => $this->disposeBatch($input),
            'inventory' => $this->inventory($input),
            'sort_out_expired' => $this->sortOutExpired($input),
            default => null,
        };
    }

    /**
     * Bucht einen gescannten/eingegebenen Artikel auf der Buchen-Seite.
     * Der Vorgang ergibt sich aus Von (`source`) und Nach (`target`):
     *
     *   source=<Lagerort>, target=issue      → Ausbuchen (Standard)
     *   source=<Lagerort>, target=<anderer>  → Umbuchen
     *   source=receipt,    target=<Lagerort> → Einlagern mit `expiry_date`
     *
     * `quantity` (Standard 1) Stück werden gebucht; beim Aus- und
     * Umbuchen beginnend mit dem ältesten MHD, auch über mehrere Chargen.
     * Ohne `source` gilt der erste Lagerort der Sortierung, siehe
     * LocationRepository::defaultLocation().
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

            $quantityInput = $this->string($input, 'quantity', '1');
            $quantity = preg_match('/^\d{1,3}$/', $quantityInput) ? (int) $quantityInput : 0;

            if ($quantity < 1) {
                throw new RuntimeException(
                    'Ungültige Menge: ' . $quantityInput . ' (erlaubt: 1 bis 999).'
                );
            }

            $target = $this->string($input, 'target', 'issue');

            if ($target === '') {
                $target = 'issue';
            }

            if ($this->string($input, 'source') === 'receipt') {
                return $this->receiptByScan($input, $article, $target, $quantity, $isAjax);
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

            if ($target === 'issue') {
                $result = $this->stock->issueOldest(
                    (int) $article['id'],
                    $sourceLocationId,
                    'Scanner-Ausbuchung aus ' . $sourceLocation['name'],
                    $quantity
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
                        . ' nach ' . $targetLocation['name'],
                    $quantity
                );

                $actionLabel = 'umgebucht nach ' . $targetLocation['name'];
            }

            /*
             * Es wird stets zuerst die Charge mit dem ältesten MHD gebucht.
             * Ist eine gebuchte Charge bereits abgelaufen (oder läuft bald
             * ab), wird das zusätzlich angezeigt, damit niemand unbemerkt
             * abgelaufenes Material ausgebucht oder umgebucht bekommt.
             */
            $warnings = array_map(
                static fn (array $batch): string => expiryInfo($batch['expiry_date'])['warning'],
                $result['batches']
            );

            $expiryWarning = in_array('ABGELAUFEN', $warnings, true)
                ? 'ABGELAUFEN'
                : (string) current(array_filter($warnings));

            /*
             * Abgelaufene Chargen dieser Buchung für den Alarm mit
             * „Aussortieren“ (booking.js, Aktion sort_out_expired):
             * gebuchte Menge und was davon am Lagerort noch liegt.
             */
            $expiredBatches = [];

            foreach ($result['batches'] as $batch) {
                if (expiryInfo($batch['expiry_date'])['warning'] !== 'ABGELAUFEN') {
                    continue;
                }

                $expiredBatches[] = [
                    'batch_id' => $batch['batch_id'],
                    'expiry_date' => formatDate($batch['expiry_date']),
                    'quantity' => $batch['quantity'],
                    'remaining' => $this->stock->getStockAtLocation(
                        (int) $article['id'],
                        $sourceLocationId,
                        $batch['batch_id']
                    ),
                ];
            }

            return $this->bookingResult(
                $isAjax,
                $article,
                $articleNumber,
                $quantity,
                $actionLabel,
                $this->batchesText($result['batches'], $article),
                $expiryWarning,
                '?page=issue&source=' . $sourceLocationId . '&target=' . urlencode($target),
                [
                    'article_id' => (int) $article['id'],
                    'source' => (string) $sourceLocationId,
                    'source_name' => $sourceLocation['name'],
                    'target' => $target,
                    'expired_batches' => $expiredBatches,
                ]
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
     * Einlagern per Scan (Buchen-Seite, Von "Wareneingang"): $quantity Stück
     * mit dem MHD aus `expiry_date` an den Lagerort $target. Artikel ohne
     * MHD werden ohne MHD eingelagert, das Feld wird dann nicht beachtet.
     */
    private function receiptByScan(
        array $input,
        array $article,
        string $target,
        int $quantity,
        bool $isAjax
    ): ActionResult {
        $targetLocation = $target !== 'issue'
            ? $this->locations->find((int) $target)
            : null;

        if (!$targetLocation) {
            throw new RuntimeException(
                'Bitte bei Nach einen Lagerort zum Einlagern auswählen.'
            );
        }

        $batchId = null;
        $expiryDate = null;

        if ((int) $article['has_expiry'] === 1) {
            $expiryInput = $this->string($input, 'expiry_date');

            if ($expiryInput === '') {
                throw new RuntimeException(
                    'Bitte ein MHD eingeben – ' . $article['name'] . ' hat ein MHD.'
                );
            }

            $expiryDate = normalizeDate($expiryInput);

            if ($expiryDate === null) {
                throw new RuntimeException(
                    'Ungültiges MHD: ' . $expiryInput . ' (erwartet z. B. 31.12.2027).'
                );
            }

            $this->assertPlausibleExpiry($expiryDate, $this->string($input, 'confirm_expiry') === '1');

            $batchId = $this->batches->findOrCreate((int) $article['id'], $expiryDate);
        }

        $this->stock->move(
            (int) $article['id'],
            (int) $targetLocation['id'],
            $quantity,
            'receipt',
            'Scanner-Einlagerung in ' . $targetLocation['name'],
            $batchId
        );

        /*
         * Das MHD bleibt für die nächsten Scans stehen (meist kommen mehrere
         * Packungen derselben Lieferung), die Bestätigung eines
         * ungewöhnlichen MHD ebenso.
         */
        $expiryInput = $this->string($input, 'expiry_date');
        $keepExpiry = normalizeDate($expiryInput);

        return $this->bookingResult(
            $isAjax,
            $article,
            $article['article_number'],
            $quantity,
            'eingelagert in ' . $targetLocation['name'],
            $expiryDate !== null ? formatDate($expiryDate) : '',
            '',
            '?page=issue&source=receipt&target=' . (int) $targetLocation['id']
                . ($keepExpiry !== null ? '&expiry=' . $keepExpiry : '')
                . ($this->string($input, 'confirm_expiry') === '1' ? '&confirm_expiry=1' : '')
        );
    }

    /**
     * MHD-Angabe der gebuchten Chargen: "31.12.2027", bei mehreren Chargen
     * "2 × 31.12.2027, 1 × 30.06.2028". Bei Artikeln ohne MHD (z. B.
     * Mullbinden) wäre "ohne MHD" nur Rauschen – dann entfällt die Angabe.
     *
     * @param list<array{expiry_date: ?string, quantity: int}> $batches
     */
    private function batchesText(array $batches, array $article): string
    {
        $label = static fn (array $batch): string => match (true) {
            $batch['expiry_date'] !== null => formatDate($batch['expiry_date']),
            (int) $article['has_expiry'] === 1 => 'ohne MHD',
            default => '',
        };

        if (count($batches) === 1) {
            return $label($batches[0]);
        }

        return implode(', ', array_map(
            static fn (array $batch): string => trim($batch['quantity'] . ' × ' . $label($batch)),
            $batches
        ));
    }

    /**
     * Antwort einer Buchung auf der Buchen-Seite: JSON für den
     * Kamera-Scanner, sonst Redirect mit Meldung. $redirectUrl behält
     * Von/Nach (und beim Einlagern das MHD) für die nächste Buchung, z. B.
     * per Hand-Barcodescanner mit Enter – wie beim Kamera-Scan ohne
     * Neuladen. Die Menge gilt nur für diese eine Buchung. $json ergänzt
     * die JSON-Antwort (z. B. abgelaufene Chargen für den Alarm).
     */
    private function bookingResult(
        bool $isAjax,
        array $article,
        string $articleNumber,
        int $quantity,
        string $actionLabel,
        string $expiryText,
        string $expiryWarning,
        string $redirectUrl,
        array $json = []
    ): ActionResult {
        if ($expiryWarning !== '') {
            $expiryText .= ' – ' . $expiryWarning;
        }

        if ($isAjax) {
            return ActionResult::json([
                'success' => true,
                'article_name' => $article['name'],
                'article_number' => $articleNumber,
                'quantity' => $quantity,
                'unit' => $article['unit'],
                'unit_plural' => $article['unit_plural'],
                'expiry_date' => $expiryText,
                'action_label' => $actionLabel,
                'expired' => $expiryWarning !== ''
            ] + $json);
        }

        return ActionResult::redirect(
            $redirectUrl,
            $article['name'] . ' – ' . quantityText($quantity, $article) . ' '
                . $actionLabel . ($expiryText !== '' ? ' (' . $expiryText . ')' : ''),
            // Abgelaufene Charge gebucht: rot statt grün hervorheben.
            $expiryWarning !== '' ? 'error' : 'success'
        );
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
     * mehr als 20 Jahre voraus kann stimmen, muss aber bestätigt werden
     * (Rückfrage in stock-form.js, setzt `confirm_expiry=1`). 20 Jahre,
     * weil viele Verbandmittel so lange haltbar sind.
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

        if ($expiryDate > date('Y-m-d', strtotime('+20 years'))) {
            throw new RuntimeException(
                'Das MHD ' . formatDate($expiryDate) . ' liegt über 20 Jahre in der Zukunft. '
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
     * @param string $kind issue|transfer|disposal|receipt
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
        } elseif ($kind === 'receipt') {
            $this->stock->reverseTodayReceipt($articleId, $batchId, $locationId, $quantity);

            $done = 'Einlagerung rückgängig, aus ' . $location['name'] . ' entfernt';
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

    /**
     * Übernimmt eine Inventur (Seite ?page=inventory): `count[Artikel][Charge]`
     * ist die gezählte Menge je vorhandener Charge (`none` = ohne MHD,
     * leer = nicht gezählt), `found[Artikel]` mit `expiry_date` und
     * `quantity` eine zusätzlich gefundene Charge. Abweichungen werden als
     * Korrektur gebucht, siehe StockRepository::applyInventory().
     *
     * Erst wird alles geprüft, dann gebucht – bei einem Fehler bleibt der
     * Bestand unverändert.
     */
    private function inventory(array $input): ActionResult
    {
        $location = $this->locations->find($this->int($input, 'location_id'));

        if (!$location) {
            throw new RuntimeException(
                'Der Lagerort wurde nicht gefunden.'
            );
        }

        $counts = [];
        $found = [];
        $names = [];

        $article = function (string|int $articleId) use (&$names): array {
            $article = $this->articles->find((int) $articleId);

            if (!$article || (int) $article['active'] !== 1) {
                throw new RuntimeException(
                    'Ein Artikel der Inventur wurde nicht gefunden.'
                );
            }

            $names[(int) $article['id']] = $article['name'];

            return $article;
        };

        $number = static function (mixed $value, string $name): ?int {
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                return null;
            }

            if (!preg_match('/^\d{1,4}$/', $value)) {
                throw new RuntimeException(
                    $name . ': ungültige Menge „' . $value . '“ (erlaubt: 0 bis 9999).'
                );
            }

            return (int) $value;
        };

        foreach ($this->array($input, 'count') as $articleId => $batches) {
            $row = $article($articleId);

            foreach (is_array($batches) ? $batches : [] as $batchKey => $value) {
                $counted = $number($value, $row['name']);

                if ($counted === null) {
                    continue;
                }

                $batchId = null;

                if ($batchKey !== 'none') {
                    $batch = $this->batches->find((int) $batchKey);

                    if (!$batch || (int) $batch['article_id'] !== (int) $row['id']) {
                        throw new RuntimeException(
                            $row['name'] . ': Das MHD gehört nicht zu diesem Artikel.'
                        );
                    }

                    $batchId = (int) $batch['id'];
                }

                $counts[(int) $row['id'] . ':' . $batchId] = [
                    'article_id' => (int) $row['id'],
                    'batch_id' => $batchId,
                    'counted' => $counted,
                ];
            }
        }

        foreach ($this->array($input, 'found') as $articleId => $values) {
            $values = is_array($values) ? $values : [];
            $row = $article($articleId);
            $quantity = $number($values['quantity'] ?? '', $row['name']);

            if (!$quantity) {
                continue;
            }

            $expiryInput = is_string($values['expiry_date'] ?? null) ? trim($values['expiry_date']) : '';
            $expiryDate = null;

            if ((int) $row['has_expiry'] === 1) {
                $expiryDate = normalizeDate($expiryInput);

                if ($expiryDate === null) {
                    throw new RuntimeException(
                        $row['name'] . ': Bitte zur gefundenen Menge ein gültiges MHD eingeben (z. B. 31.12.2027).'
                    );
                }

                // Abgelaufenes kann gefunden werden – nur Tippfehler im Jahr abfangen.
                $this->assertPlausibleExpiry($expiryDate, true);
            }

            $found[] = ['article' => $row, 'expiry_date' => $expiryDate, 'quantity' => $quantity];
        }

        /*
         * Gefundene Charge: zählt zu dem, was bei dieser Charge schon
         * gezählt wurde (falls sie in der Liste stand).
         */
        foreach ($found as $item) {
            $articleId = (int) $item['article']['id'];
            $batchId = $this->batches->findOrCreate($articleId, $item['expiry_date'] ?? '');
            $key = $articleId . ':' . $batchId;

            $counts[$key] = [
                'article_id' => $articleId,
                'batch_id' => $batchId,
                'counted' => ($counts[$key]['counted'] ?? $this->stock->getStockAtLocation($articleId, (int) $location['id'], $batchId))
                    + $item['quantity'],
            ];
        }

        $changes = $this->stock->applyInventory((int) $location['id'], array_values($counts));

        $message = match (count($changes)) {
            0 => 'Inventur gespeichert, keine Abweichungen.',
            1 => 'Inventur gespeichert, 1 Abweichung korrigiert: ',
            default => 'Inventur gespeichert, ' . count($changes) . ' Abweichungen korrigiert: ',
        };

        $message .= implode(', ', array_map(
            static fn (array $change): string => $names[$change['article_id']] . ' '
                . ($change['difference'] > 0 ? '+' : '−') . abs($change['difference']),
            $changes
        ));

        return ActionResult::redirect(
            '?page=location&id=' . (int) $location['id'],
            $message
        );
    }

    /**
     * „Aussortieren“ im Alarm der Buchen-Seite: Eine gerade gebuchte
     * abgelaufene Charge wird zurückgenommen und am Lagerort `source`
     * vollständig entsorgt, siehe StockRepository::sortOutExpired().
     * `target` ist wie beim Buchen `issue` oder der Ziel-Lagerort,
     * `batches[Charge]` die jeweils gebuchte Menge.
     *
     * Mit `ajax=1` JSON (Buchen-Seite), sonst Redirect zurück zum Buchen.
     */
    private function sortOutExpired(array $input): ActionResult
    {
        $isAjax = $this->string($input, 'ajax') === '1';
        $target = $this->string($input, 'target', 'issue');

        try {
            $article = $this->articles->find($this->int($input, 'article_id'));
            $source = $this->locations->find($this->int($input, 'source'));
            $targetLocation = $target !== 'issue' ? $this->locations->find((int) $target) : null;
            $batches = $this->array($input, 'batches');

            if (!$article || !$source || ($target !== 'issue' && !$targetLocation) || !$batches) {
                throw new RuntimeException(
                    'Aussortieren nicht möglich: Angaben unvollständig.'
                );
            }

            $disposed = $this->stock->sortOutExpiredBatches(
                (int) $article['id'],
                (int) $source['id'],
                $targetLocation ? (int) $targetLocation['id'] : null,
                array_map(static fn (mixed $quantity): int => is_string($quantity) ? (int) $quantity : 0, $batches)
            );

            $message = $article['name'] . ' – ' . quantityText($disposed, $article)
                . ' aus ' . $source['name'] . ' entsorgt (MHD abgelaufen)';

            if ($isAjax) {
                return ActionResult::json([
                    'success' => true,
                    'message' => $message,
                ]);
            }

            return ActionResult::redirect(
                '?page=issue&source=' . (int) $source['id'] . '&target=' . urlencode($target),
                $message
            );
        } catch (Throwable $exception) {
            if ($isAjax) {
                return ActionResult::json([
                    'success' => false,
                    'error' => userMessage($exception),
                ], 400);
            }

            throw $exception;
        }
    }
}
