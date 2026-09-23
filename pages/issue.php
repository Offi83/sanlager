<?php

/*
 * Daten für die Seite: Buchen (Von/Nach-Vorbelegung).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$allLocations = [];

/*
 * Sowohl "Von" als auch "Nach" bekommen die volle Liste; welche
 * Kombination gültig ist (Von != Nach), steuert das Frontend
 * (issue-source-Auswahl blendet die gleiche Option im Nach-Select
 * aus) und wird zusätzlich serverseitig in StockActions geprüft.
 */
$allLocations = $locationRepository->all();

/*
 * Von/Nach der vorherigen Buchung beibehalten: nach Erfolg kommen
 * sie über den Redirect (GET, siehe StockActions::issue()), nach
 * einem Fehler aus dem abgeschickten Formular (POST). Ungültige oder
 * inzwischen deaktivierte Lagerorte fallen auf die Standardauswahl
 * (erster Lagerort der Sortierung, Ausbuchen) zurück.
 */
$requestValue = static fn (string $key): string =>
    is_string($_POST[$key] ?? null)
        ? $_POST[$key]
        : (is_string($_GET[$key] ?? null) ? $_GET[$key] : '');

$locationNamesById = array_column($allLocations, 'name', 'id');

$issueSourceId = (int) $requestValue('source');

if (!isset($locationNamesById[$issueSourceId])) {
    $issueSourceId = (int) array_key_first($locationNamesById);
}

$issueTarget = $requestValue('target');

if (
    $issueTarget !== 'issue'
    && (
        !isset($locationNamesById[(int) $issueTarget])
        || (int) $issueTarget === $issueSourceId
    )
) {
    $issueTarget = 'issue';
}

$issueModeText = $issueTarget === 'issue'
    ? 'Ausbuchen aus ' . ($locationNamesById[$issueSourceId] ?? '')
    : 'Umbuchen: ' . ($locationNamesById[$issueSourceId] ?? '')
        . ' → ' . $locationNamesById[(int) $issueTarget];
