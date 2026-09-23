<?php

namespace LagerApp;

/**
 * Typsicheres Auslesen von Formularwerten für die *Actions-Klassen.
 *
 * Werte, die nicht den erwarteten Typ haben (z. B. ein manipuliertes
 * `name[]=x` statt `name=x`), werden wie ein fehlendes Feld behandelt,
 * statt einen TypeError auszulösen.
 */
trait ReadsInput
{
    /**
     * Getrimmter Text, oder $default wenn das Feld fehlt bzw. kein Text ist.
     */
    private function string(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Ganzzahl, oder 0 wenn das Feld fehlt bzw. kein Text ist.
     */
    private function int(array $input, string $key): int
    {
        return (int) $this->string($input, $key, '0');
    }

    /**
     * Liste/Map, oder [] wenn das Feld fehlt bzw. kein Array ist.
     */
    private function array(array $input, string $key): array
    {
        $value = $input[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
