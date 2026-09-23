<?php

namespace LagerApp;

/**
 * Ergebnis einer erfolgreich verarbeiteten POST-Aktion: entweder eine
 * Weiterleitung (Redirect-nach-POST) oder eine JSON-Antwort (Scanner,
 * Drag & Drop).
 *
 * Die *Actions-Klassen geben dieses Objekt nur zurück, statt selbst
 * Header zu senden oder die Ausführung zu beenden. Erst public/index.php
 * ruft send() auf – dadurch lassen sich die Actions in Tests aufrufen
 * und ihr Ergebnis prüfen.
 */
final class ActionResult
{
    private function __construct(
        public readonly ?string $redirectUrl,
        public readonly ?array $json,
        public readonly int $status
    ) {
    }

    public static function redirect(string $url): self
    {
        return new self($url, null, 302);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(null, $data, $status);
    }

    public function send(): never
    {
        if ($this->redirectUrl !== null) {
            redirect($this->redirectUrl);
        }

        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($this->json);

        exit;
    }
}
