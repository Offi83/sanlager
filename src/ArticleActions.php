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
    public function __construct(
        private ArticleRepository $articles,
        private CategoryRepository $categories,
        private StockRepository $stock
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
            'create_article' => $this->create(),
            'update_article' => $this->update(),
            'deactivate_article' => $this->deactivate(),
            'set_article_minimums' => $this->setMinimums(),
            default => null,
        };
    }

    private function create(): void
    {
        $articleNumber = trim($_POST['article_number'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? 'Stück');

        $categoryId = (int) ($_POST['category_id'] ?? 0);

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

        redirect('?page=new_article&message=Artikel+angelegt');
    }

    private function update(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        $articleNumber = trim($_POST['article_number'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? 'Stück');

        $categoryId = (int) ($_POST['category_id'] ?? 0);

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

        redirect(
            '?page=article&id=' . $id .
            '&message=Artikel+gespeichert'
        );
    }

    private function deactivate(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        $this->articles->deactivate($id);

        redirect('?page=articles&message=Artikel+gelöscht');
    }

    /**
     * Speichert die Mindestbestände eines Artikels je Lagerort. Nur die
     * Lagerorte, die im Formular einen Wert bekommen haben, werden
     * überwacht; ein leeres Feld entfernt eine ggf. vorhandene Überwachung
     * für diesen Lagerort wieder.
     */
    private function setMinimums(): void
    {
        $articleId = (int) ($_POST['article_id'] ?? 0);

        if ($articleId <= 0 || !$this->articles->find($articleId)) {
            throw new RuntimeException(
                'Ungültiger Artikel.'
            );
        }

        $submittedMinimums = $_POST['minimum_stock'] ?? [];

        $minimums = [];

        foreach ($this->stock->locations() as $location) {
            $locationId = (int) $location['id'];
            $rawValue = trim((string) ($submittedMinimums[$locationId] ?? ''));

            $minimums[$locationId] = $rawValue === ''
                ? null
                : max(0, (int) $rawValue);
        }

        $this->stock->saveMinimums($articleId, $minimums);

        redirect(
            '?page=article&id=' . $articleId .
            '&message=' . urlencode('Mindestbestände gespeichert')
        );
    }
}
