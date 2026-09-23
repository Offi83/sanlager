<?php

namespace LagerApp;

use RuntimeException;
use Throwable;

/**
 * Verarbeitet die POST-Aktionen rund um Kategorien
 * (anlegen/bearbeiten/löschen/sortieren).
 */
class CategoryActions
{
    use ReadsInput;

    public function __construct(
        private CategoryRepository $categories
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
            'create_category' => $this->create($input),
            'update_category' => $this->update($input),
            'delete_category' => $this->delete($input),
            'reorder_categories' => $this->reorder($input),
            default => null,
        };
    }

    private function create(array $input): ActionResult
    {
        $name = $this->string($input, 'name');
        $shortName = $this->string($input, 'short_name');
        $color = $this->string($input, 'color');

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Kategorienamen eingeben.'
            );
        }

        if ($shortName === '') {
            throw new RuntimeException(
                'Bitte ein Kürzel eingeben.'
            );
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new RuntimeException(
                'Ungültige Farbe.'
            );
        }

        $this->categories->create(
            $name,
            mb_strtoupper($shortName),
            $color
        );

        return ActionResult::redirect('?page=categories&message=Kategorie+angelegt');
    }

    private function update(array $input): ActionResult
    {
        $id = $this->int($input, 'id');
        $name = $this->string($input, 'name');
        $shortName = $this->string($input, 'short_name');
        $color = $this->string($input, 'color');

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültige Kategorie.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Kategorienamen eingeben.'
            );
        }

        if ($shortName === '') {
            throw new RuntimeException(
                'Bitte ein Kürzel eingeben.'
            );
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new RuntimeException(
                'Ungültige Farbe.'
            );
        }

        $this->categories->update(
            $id,
            $name,
            mb_strtoupper($shortName),
            $color
        );

        return ActionResult::redirect('?page=categories&message=Kategorie+gespeichert');
    }

    private function delete(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültige Kategorie.'
            );
        }

        if ($this->categories->articleCount($id) > 0) {
            throw new RuntimeException(
                'Die Kategorie kann nicht gelöscht werden, solange Artikel dieser Kategorie zugeordnet sind.'
            );
        }

        $this->categories->delete($id);

        return ActionResult::redirect('?page=categories&message=Kategorie+gelöscht');
    }

    /**
     * Liefert im Gegensatz zu den anderen Aktionen immer JSON zurück
     * (wird per fetch() vom Drag-&-Drop-Skript der Kategorien-Seite
     * aufgerufen), auch im Fehlerfall.
     */
    private function reorder(array $input): ActionResult
    {
        $ids = $input['ids'] ?? null;

        if (!is_array($ids)) {
            return ActionResult::json([
                'success' => false,
                'error' => 'Ungültige Kategorienreihenfolge.'
            ], 400);
        }

        try {
            $this->categories->reorder($ids);
        } catch (Throwable $exception) {
            return ActionResult::json([
                'success' => false,
                'error' => $exception->getMessage()
            ], 400);
        }

        return ActionResult::json([
            'success' => true
        ]);
    }
}
