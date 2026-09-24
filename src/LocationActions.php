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
    use ReadsInput;

    public function __construct(
        private LocationRepository $locations,
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
            'create_location' => $this->create($input),
            'update_location' => $this->update($input),
            'deactivate_location' => $this->deactivate($input),
            'reorder_locations' => $this->reorder($input),
            default => null,
        };
    }

    private function create(array $input): ActionResult
    {
        $name = $this->string($input, 'name');
        $description = $this->string($input, 'description');

        if ($name === '') {
            throw new RuntimeException(
                'Bitte einen Namen für den Lagerort eingeben.'
            );
        }

        $reactivated = $this->locations->isDeactivatedName($name);

        $this->locations->create($name, $description);

        return ActionResult::redirect(
            '?page=locations',
            $reactivated
                ? 'Lagerort „' . $name . '“ war deaktiviert und ist wieder aktiv'
                : 'Lagerort angelegt'
        );
    }

    private function update(array $input): ActionResult
    {
        $id = $this->int($input, 'id');
        $name = $this->string($input, 'name');
        $description = $this->string($input, 'description');

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

        return ActionResult::redirect('?page=locations', 'Lagerort gespeichert');
    }

    private function deactivate(array $input): ActionResult
    {
        $id = $this->int($input, 'id');

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

        return ActionResult::redirect('?page=locations', 'Lagerort deaktiviert');
    }

    /**
     * Liefert im Gegensatz zu den anderen Aktionen immer JSON zurück
     * (wird per fetch() vom Drag-&-Drop-Skript der Lagerorte-Seite
     * aufgerufen), auch im Fehlerfall.
     */
    private function reorder(array $input): ActionResult
    {
        $ids = $input['ids'] ?? null;

        if (!is_array($ids)) {
            return ActionResult::json([
                'success' => false,
                'error' => 'Ungültige Lagerortreihenfolge.'
            ], 400);
        }

        try {
            $this->locations->reorder($ids);
        } catch (Throwable $exception) {
            return ActionResult::json([
                'success' => false,
                'error' => userMessage($exception)
            ], 400);
        }

        return ActionResult::json([
            'success' => true
        ]);
    }
}
