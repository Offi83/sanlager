<?php

namespace LagerApp;

use RuntimeException;
use Throwable;

/**
 * Verarbeitet die POST-Aktionen rund um Einheiten
 * (anlegen/bearbeiten/löschen/sortieren), siehe UnitRepository.
 */
class UnitActions
{
    use ReadsInput;

    public function __construct(
        private UnitRepository $units
    ) {
    }

    /**
     * Führt die zu $action passende Aktion aus, falls diese Klasse dafür
     * zuständig ist, sonst null (siehe ArticleActions::dispatch()).
     */
    public function dispatch(?string $action, array $input): ?ActionResult
    {
        return match ($action) {
            'create_unit' => $this->save(null, $input),
            'update_unit' => $this->save($this->int($input, 'id'), $input),
            'delete_unit' => $this->delete($input),
            'reorder_units' => $this->reorder($input),
            default => null,
        };
    }

    /**
     * Anlegen ($id = null) oder Bearbeiten. Ohne Mehrzahl gilt die Einzahl.
     */
    private function save(?int $id, array $input): ActionResult
    {
        $name = $this->string($input, 'name');
        $plural = $this->string($input, 'plural');

        if ($name === '') {
            throw new RuntimeException(
                'Bitte die Einheit eingeben (Einzahl, z. B. Rolle).'
            );
        }

        if ($id === null) {
            $this->units->create($name, $plural);

            return ActionResult::redirect('?page=units', 'Einheit angelegt');
        }

        if (!$this->units->find($id)) {
            throw new RuntimeException('Ungültige Einheit.');
        }

        $this->units->update($id, $name, $plural);

        return ActionResult::redirect('?page=units', 'Einheit gespeichert');
    }

    private function delete(array $input): ActionResult
    {
        $this->units->delete($this->int($input, 'id'));

        return ActionResult::redirect('?page=units', 'Einheit gelöscht');
    }

    /**
     * Liefert wie bei Kategorien immer JSON (Drag & Drop per fetch()).
     */
    private function reorder(array $input): ActionResult
    {
        $ids = $input['ids'] ?? null;

        if (!is_array($ids)) {
            return ActionResult::json([
                'success' => false,
                'error' => 'Ungültige Reihenfolge der Einheiten.'
            ], 400);
        }

        try {
            $this->units->reorder($ids);
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
