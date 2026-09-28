<?php

namespace LagerApp;

use Throwable;

/**
 * POST-Aktion "neue Reihenfolge" für das Drag & Drop (sortable-list.js)
 * der Kategorien, Lagerorte und Einheiten, siehe SortOrder.
 */
trait SortOrderAction
{
    /**
     * Speichert die Reihenfolge aus `ids` über $reorder. Liefert anders als
     * die übrigen Aktionen immer JSON, auch im Fehlerfall – das Skript
     * ruft sie per fetch() auf.
     *
     * @param callable(array): void $reorder z. B. CategoryRepository::reorder()
     */
    private function reorderResult(array $input, callable $reorder, string $invalidMessage): ActionResult
    {
        $ids = $input['ids'] ?? null;

        if (!is_array($ids)) {
            return ActionResult::json([
                'success' => false,
                'error' => $invalidMessage,
            ], 400);
        }

        try {
            $reorder($ids);
        } catch (Throwable $exception) {
            return ActionResult::json([
                'success' => false,
                'error' => userMessage($exception),
            ], 400);
        }

        return ActionResult::json([
            'success' => true,
        ]);
    }
}
