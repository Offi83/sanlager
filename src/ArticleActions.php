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
        private StockRepository $stock
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
        $unit = $this->string($input, 'unit', 'Stück');

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

        $this->articles->create(
            $articleNumber,
            $name,
            $description,
            $unit !== '' ? $unit : 'Stück',
            $categoryId
        );

        return ActionResult::redirect('?page=new_article&message=Artikel+angelegt');
    }

    private function update(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

        $articleNumber = $this->string($input, 'article_number');
        $name = $this->string($input, 'name');
        $description = $this->string($input, 'description');
        $unit = $this->string($input, 'unit', 'Stück');

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

        $this->articles->update(
            $id,
            $articleNumber !== '' ? $articleNumber : null,
            $name,
            $description,
            $unit !== '' ? $unit : 'Stück',
            $categoryId
        );

        return ActionResult::redirect(
            '?page=article&id=' . $id .
            '&message=Artikel+gespeichert'
        );
    }

    private function deactivate(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        $this->articles->deactivate($id);

        return ActionResult::redirect('?page=articles&message=Artikel+gelöscht');
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

        foreach ($this->stock->locations() as $location) {
            $locationId = (int) $location['id'];
            $rawValue = $this->string($submittedMinimums, (string) $locationId);

            $minimums[$locationId] = $rawValue === ''
                ? null
                : max(0, (int) $rawValue);
        }

        $this->stock->saveMinimums($articleId, $minimums);

        return ActionResult::redirect(
            '?page=article&id=' . $articleId .
            '&message=' . urlencode('Mindestbestände gespeichert')
        );
    }
}
