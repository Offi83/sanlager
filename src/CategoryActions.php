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
    public function __construct(
        private CategoryRepository $categories
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
            'create_category' => $this->create(),
            'update_category' => $this->update(),
            'delete_category' => $this->delete(),
            'reorder_categories' => $this->reorder(),
            default => null,
        };
    }

    private function create(): void
    {
        $name = trim($_POST['name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $color = trim($_POST['color'] ?? '');

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

        redirect('?page=categories&message=Kategorie+angelegt');
    }

    private function update(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $color = trim($_POST['color'] ?? '');

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

        redirect('?page=categories&message=Kategorie+gespeichert');
    }

    private function delete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

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

        redirect('?page=categories&message=Kategorie+gelöscht');
    }

    /**
     * Liefert im Gegensatz zu den anderen Aktionen immer JSON zurück
     * (wird per fetch() vom Drag-&-Drop-Skript der Kategorien-Seite
     * aufgerufen) und beendet die Ausführung selbst.
     */
    private function reorder(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $ids = $_POST['ids'] ?? [];

            if (!is_array($ids)) {
                throw new RuntimeException(
                    'Ungültige Kategorienreihenfolge.'
                );
            }

            $this->categories->reorder($ids);

            echo json_encode([
                'success' => true
            ]);
        } catch (Throwable $exception) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'error' => $exception->getMessage()
            ]);
        }

        exit;
    }
}
