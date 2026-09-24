<?php

namespace LagerApp;

use RuntimeException;

/**
 * Verarbeitet die POST-Aktionen rund um Artikelstammdaten
 * (Artikel anlegen/bearbeiten/deaktivieren).
 *
 * Validiert die Eingaben, delegiert an ArticleRepository und leitet bei
 * Erfolg weiter. Ungültige Eingaben werfen eine RuntimeException, die der
 * Aufrufer (public/index.php) abfängt und als Fehlermeldung anzeigt.
 */
class ArticleActions
{
    use ReadsInput;

    public function __construct(
        private ArticleRepository $articles,
        private CategoryRepository $categories,
        private StockRepository $stock,
        private LocationRepository $locations,
        private UnitRepository $units
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
            'create_article' => $this->create($input),
            'update_article' => $this->update($input),
            'deactivate_article' => $this->deactivate($input),
            'set_article_minimums' => $this->setMinimums($input),
            default => null,
        };
    }

    private function create(array $input): ActionResult
    {
        $articleNumber = $this->string($input, 'article_number');
        $name = $this->string($input, 'name');
        $description = $this->string($input, 'description');
        $unit = $this->unit($input);

        $categoryId = $this->int($input, 'category_id');

        if ($categoryId <= 0 || !$this->categories->find($categoryId)) {
            throw new RuntimeException(
                'Bitte eine gültige Kategorie auswählen.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Artikelnamen eingeben.'
            );
        }

        if ($articleNumber === '') {
            throw new RuntimeException(
                'Bitte eine Artikelnummer eingeben.'
            );
        }

        $id = $this->articles->create(
            $articleNumber,
            $name,
            $description,
            $unit,
            $categoryId,
            $this->hasExpiry($input)
        );

        /*
         * "Anlegen & öffnen": zur neuen Artikelseite. Sonst ("Anlegen &
         * nächster Artikel") zurück ins leere Formular, mit derselben
         * Kategorie vorausgewählt.
         */
        return ActionResult::redirect(
            $this->string($input, 'after') === 'open'
                ? '?page=article&id=' . $id
                : '?page=new_article&category=' . $categoryId,
            '„' . $name . '“ angelegt'
        );
    }

    private function update(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

        $articleNumber = $this->string($input, 'article_number');
        $name = $this->string($input, 'name');
        $description = $this->string($input, 'description');
        $unit = $this->unit($input);

        $categoryId = $this->int($input, 'category_id');

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        if ($categoryId <= 0 || !$this->categories->find($categoryId)) {
            throw new RuntimeException(
                'Bitte eine gültige Kategorie auswählen.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Artikelnamen eingeben.'
            );
        }

        /*
         * Wie beim Anlegen Pflicht: Ohne Nummer ließe sich der Artikel
         * nicht mehr scannen, und das Etikett hätte keinen Code.
         */
        if ($articleNumber === '') {
            throw new RuntimeException(
                'Bitte eine Artikelnummer eingeben.'
            );
        }

        $this->articles->update(
            $id,
            $articleNumber,
            $name,
            $description,
            $unit,
            $categoryId,
            $this->hasExpiry($input)
        );

        return ActionResult::redirect(
            '?page=article&id=' . $id,
            'Artikel gespeichert'
        );
    }

    /**
     * Einheit aus der Auswahlliste (`unit_id`, siehe Verwaltung →
     * Einheiten) als Einzahl-Name. Fehlt das Feld ganz, gilt "Stück".
     *
     * @throws RuntimeException bei einer unbekannten Einheit
     */
    private function unit(array $input): string
    {
        if (!isset($input['unit_id'])) {
            return 'Stück';
        }

        $unit = $this->units->find($this->int($input, 'unit_id'));

        if (!$unit) {
            throw new RuntimeException(
                'Bitte eine Einheit auswählen.'
            );
        }

        return $unit['name'];
    }

    /**
     * Ankreuzfeld "Hat ein MHD": Das Formular schickt davor ein verstecktes
     * `has_expiry=0`, angekreuzt überschreibt es das mit `1`. Fehlt das Feld
     * ganz, bleibt es beim Standard (mit MHD).
     */
    private function hasExpiry(array $input): bool
    {
        return $this->string($input, 'has_expiry', '1') !== '0';
    }

    private function deactivate(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

        $article = $this->articles->find($id);

        if (!$article) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        /*
         * Ein gelöschter (deaktivierter) Artikel verschwindet aus allen
         * Listen. Hätte er noch Bestand, läge dieser unsichtbar weiter im
         * Lagerort – der ließe sich dann z. B. nicht mehr deaktivieren.
         */
        $physicalStock = $this->stock->getPhysicalStock($id);

        if ($physicalStock > 0) {
            throw new RuntimeException(
                'Der Artikel kann nicht gelöscht werden, solange noch Bestand vorhanden ist ('
                . quantityText($physicalStock, $article)
                . ', abgelaufene Chargen eingeschlossen). Bitte zuerst ausbuchen oder entsorgen.'
            );
        }

        $this->articles->deactivate($id);

        return ActionResult::redirect('?page=articles', 'Artikel gelöscht');
    }

    /**
     * Speichert die Mindestbestände eines Artikels je Lagerort. Nur die
     * Lagerorte, die im Formular einen Wert bekommen haben, werden
     * überwacht; ein leeres Feld entfernt eine ggf. vorhandene Überwachung
     * für diesen Lagerort wieder.
     */
    private function setMinimums(array $input): ActionResult
    {
        $articleId = $this->int($input, 'article_id');

        if ($articleId <= 0 || !$this->articles->find($articleId)) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        $submittedMinimums = $this->array($input, 'minimum_stock');

        $minimums = [];

        foreach ($this->locations->all() as $location) {
            $locationId = (int) $location['id'];
            $rawValue = $this->string($submittedMinimums, (string) $locationId);

            $minimums[$locationId] = $rawValue === ''
                ? null
                : max(0, (int) $rawValue);
        }

        $this->stock->saveMinimums($articleId, $minimums);

        return ActionResult::redirect(
            '?page=article&id=' . $articleId,
            'Mindestbestände gespeichert'
        );
    }
}
