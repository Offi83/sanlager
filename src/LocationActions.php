<?php

namespace LagerApp;

use RuntimeException;
use Throwable;

/**
 * Verarbeitet die POST-Aktionen rund um Lagerorte
 * (anlegen/bearbeiten/deaktivieren/sortieren).
 */
class LocationActions
{
    public function __construct(
        private LocationRepository $locations,
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
            'create_location' => $this->create(),
            'update_location' => $this->update(),
            'deactivate_location' => $this->deactivate(),
            'reorder_locations' => $this->reorder(),
            default => null,
        };
    }

    private function create(): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Namen für den Lagerort eingeben.'
            );
        }

        $this->locations->create($name, $description);

        redirect('?page=locations&message=Lagerort+angelegt');
    }

    private function update(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültiger Lagerort.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Namen für den Lagerort eingeben.'
            );
        }

        $this->locations->update($id, $name, $description);

        redirect('?page=locations&message=Lagerort+gespeichert');
    }

    private function deactivate(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(
                'Ungültiger Lagerort.'
            );
        }

        if ($this->stock->locationHasStock($id)) {
            throw new RuntimeException(
                'Der Lagerort kann nicht deaktiviert werden, solange dort noch Bestand vorhanden ist.'
            );
        }

        $this->locations->deactivate($id);

        redirect('?page=locations&message=Lagerort+deaktiviert');
    }

    /**
     * Liefert im Gegensatz zu den anderen Aktionen immer JSON zurück
     * (wird per fetch() vom Drag-&-Drop-Skript der Lagerorte-Seite
     * aufgerufen) und beendet die Ausführung selbst.
     */
    private function reorder(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $ids = $_POST['ids'] ?? [];

            if (!is_array($ids)) {
                throw new RuntimeException(
                    'Ungültige Lagerortreihenfolge.'
                );
            }

            $this->locations->reorder($ids);

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
