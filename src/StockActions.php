<?php

namespace LagerApp;

use RuntimeException;
use Throwable;

/**
 * Verarbeitet die POST-Aktionen rund um Lagerbewegungen: das Buchen
 * (Ausbuchen/Umbuchen per Scanner oder manueller Eingabe) sowie das
 * artikelbezogene Bestand-buchen-Formular (Einlagern/Entnehmen).
 */
class StockActions
{
    public function __construct(
        private ArticleRepository $articles,
        private LocationRepository $locations,
        private StockRepository $stock,
        private BatchRepository $batches
    ) {
    }

    /**
     * Führt die zu $action passende Aktion aus, falls diese Klasse dafür
     * zuständig ist. Nicht zuständige Aktionen werden ignoriert, damit
     * der Aufrufer einfach alle Action-Klassen nacheinander aufrufen kann.
     */
    public function dispatch(?string $action): void
    {
        match ($action) {
            'issue' => $this->issue(),
            'stock_move' => $this->stockMove(),
            'transfer_all_stock' => $this->transferAllStock(),
            default => null,
        };
    }

    /**
     * Bucht ein Stück eines gescannten/eingegebenen Artikels ab einem
     * wählbaren Quell-Lagerort (`source`, Standard: Hauptlager): entweder
     * klassisch aus (`target=issue`, Standard) oder an einen anderen
     * Lagerort um (`target=<location_id>`).
     *
     * `ajax=1` liefert JSON zurück (Kamera-Scanner), sonst erfolgt ein
     * normaler Redirect mit Erfolgs-/Fehlermeldung als GET-Parameter.
     * Fehler werden hier abschließend behandelt (nicht an den Aufrufer
     * weitergereicht), damit der Scanner nach einem Fehlversuch sofort
     * für den nächsten Scan bereit ist.
     */
    private function issue(): void
    {
        $isAjax = ($_POST['ajax'] ?? '') === '1';

        try {
            $articleNumber = trim(
                $_POST['article_number'] ?? ''
            );

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

            $sourceLocationId = (int) ($_POST['source'] ?? 0);

            $sourceLocation = $sourceLocationId > 0
                ? $this->locations->find($sourceLocationId)
                : $this->locations->findByName('Hauptlager');

            if (!$sourceLocation) {
                throw new RuntimeException(
                    'Der ausgewählte Quell-Lagerort wurde nicht gefunden.'
                );
            }

            $sourceLocationId = (int) $sourceLocation['id'];

            $target = trim($_POST['target'] ?? 'issue');

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

            $expiryText = $result['expiry_date']
                ? formatDate($result['expiry_date'])
                : 'ohne MHD';

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
                header(
                    'Content-Type: application/json; charset=utf-8'
                );

                echo json_encode([
                    'success' => true,
                    'article_name' => $article['name'],
                    'article_number' => $articleNumber,
                    'unit' => $article['unit'],
                    'expiry_date' => $expiryText,
                    'action_label' => $actionLabel,
                    'expired' => $expiryWarning !== ''
                ]);

                exit;
            }

            redirect(
                '?page=issue' .
                '&success=' . urlencode(
                    $article['name'] .
                    ' – 1 ' .
                    $article['unit'] .
                    ' ' .
                    $actionLabel .
                    ' (' .
                    $expiryText .
                    ')'
                ) .
                ($expiryWarning !== '' ? '&expired=1' : '')
            );
        } catch (Throwable $exception) {
            if ($isAjax) {
                http_response_code(400);

                header(
                    'Content-Type: application/json; charset=utf-8'
                );

                echo json_encode([
                    'success' => false,
                    'error' => $exception->getMessage()
                ]);

                exit;
            }

            throw $exception;
        }
    }

    private function stockMove(): void
    {
        $articleId = (int) ($_POST['article_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $movementType = $_POST['movement_type'] ?? '';

        if ($articleId <= 0) {
            throw new RuntimeException(
                'Bitte einen Artikel auswählen.'
            );
        }

        if ($locationId <= 0) {
            throw new RuntimeException(
                'Bitte einen Lagerort auswählen.'
            );
        }

        if ($quantity <= 0) {
            throw new RuntimeException(
                'Die Menge muss größer als 0 sein.'
            );
        }

        if (!in_array(
            $movementType,
            ['receipt', 'issue'],
            true
        )) {
            throw new RuntimeException(
                'Ungültiger Vorgang.'
            );
        }

        /*
         * MHD-Auswahl:
         *
         * none = ohne MHD
         * ID   = vorhandenes MHD
         * new  = neues MHD
         */
        $batchSelection =
            $_POST['batch_selection'] ?? 'none';

        $batchId = null;

        /*
         * Neues MHD
         */
        if ($batchSelection === 'new') {
            if ($movementType !== 'receipt') {
                throw new RuntimeException(
                    'Bei einer Entnahme kann kein neues MHD angelegt werden.'
                );
            }

            $expiryDate = trim(
                $_POST['expiry_date'] ?? ''
            );

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
        }

        $note = trim(
            $_POST['note'] ?? ''
        );

        $this->stock->move(
            $articleId,
            $locationId,
            $quantity,
            $movementType,
            $note !== '' ? $note : null,
            $batchId
        );

        redirect(
            '?page=article&id=' .
            $articleId .
            '&message=' .
            urlencode(
                $movementType === 'receipt'
                    ? 'Bestand eingelagert'
                    : 'Bestand entnommen'
            )
        );
    }

    /**
     * Verschiebt den kompletten Bestand eines Lagerorts auf einen
     * anderen, z. B. um eine Kiste nach einem Dienst vollständig
     * zurück ins Lager zu räumen. Wird über das Bearbeiten-Formular
     * eines Lagerorts auf der Lagerorte-Seite ausgelöst.
     */
    private function transferAllStock(): void
    {
        $fromLocationId = (int) ($_POST['from_location_id'] ?? 0);
        $toLocationId = (int) ($_POST['to_location_id'] ?? 0);

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

        $movedUnits = $this->stock->transferAllStock(
            $fromLocationId,
            $toLocationId,
            'Komplettumzug von ' . $fromLocation['name']
                . ' nach ' . $toLocation['name']
        );

        redirect(
            '?page=locations&edit=' . $fromLocationId .
            '&message=' . urlencode(
                $movedUnits > 0
                    ? 'Bestand nach ' . $toLocation['name']
                        . ' verschoben (' . $movedUnits . ' Stück).'
                    : 'Es war kein Bestand zum Verschieben vorhanden.'
            )
        );
    }
}
