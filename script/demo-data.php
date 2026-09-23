<?php

/*
|--------------------------------------------------------------------------
| SanLager – Demo-Datenbank für Screenshots und Tests
|--------------------------------------------------------------------------
|
| Legt eine NEUE SQLite-Datenbank mit Beispieldaten an: Lagerorte
| (Hauptlager + Sanitätsrucksäcke), Artikel in allen gängigen Kategorien,
| Chargen mit abgelaufenen/bald ablaufenden MHDs, Mindestbeständen sowie
| Um- und Ausbuchungen von heute.
|
| Aufruf:
|   php script/demo-data.php database/demo.sqlite
|
| Eine bereits vorhandene Datei wird nicht überschrieben. Wird von
| script/screenshots.sh verwendet; die produktive Datenbank bleibt
| unberührt.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../vendor/autoload.php';

use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryRepository;
use LagerApp\Database;
use LagerApp\LocationRepository;
use LagerApp\StockRepository;

if (PHP_SAPI !== 'cli') {
    exit('Nur über die Kommandozeile aufrufbar.');
}

$target = $argv[1] ?? '';

if ($target === '') {
    fwrite(STDERR, "Aufruf: php script/demo-data.php <ziel.sqlite>\n");
    exit(1);
}

if (file_exists($target)) {
    fwrite(STDERR, "Datei existiert bereits, wird nicht überschrieben: {$target}\n");
    exit(1);
}

date_default_timezone_set('Europe/Berlin');

$db = (new Database($target))->connection();

$articles = new ArticleRepository($db);
$batches = new BatchRepository($db);
$categories = new CategoryRepository($db);
$locations = new LocationRepository($db);
$stock = new StockRepository($db);

$categoryIds = array_column($categories->all(), 'id', 'name');

$mainId = (int) $locations->findByName('Hauptlager')['id'];

$bagIds = [];

foreach (['Rucksack 1', 'Rucksack 2', 'Rucksack 3'] as $bag) {
    $bagIds[] = $locations->create($bag, 'Sanitätsrucksack');
}

$day = static fn (string $modifier): string => date('Y-m-d', strtotime($modifier));

/*
 * Artikel: [Nummer, Name, Einheit, Kategorie, Chargen [MHD oder '' => Menge im
 * Hauptlager], Mindestbestand Hauptlager, Soll je Rucksack]
 */
$demoArticles = [
    ['verb-mullbinde-8', 'Mullbinde 8 cm', 'Stück', 'Verbandmaterial', [$day('+3 years') => 40], 20, 4],
    ['verb-vp-m', 'Verbandpäckchen M', 'Stück', 'Verbandmaterial', [$day('+50 days') => 12, $day('+4 years') => 30], 25, 3],
    ['verb-heftpflaster-25', 'Heftpflaster 2,5 cm', 'Rolle', 'Verbandmaterial', ['' => 6], 10, 1],
    ['verb-kompresse-10', 'Kompresse 10 × 10 cm', 'Stück', 'Verbandmaterial', [$day('+2 years') => 100], 50, 10],
    ['verb-dreiecktuch', 'Dreiecktuch', 'Stück', 'Verbandmaterial', ['' => 15], 10, 2],
    ['verb-rettungsdecke', 'Rettungsdecke', 'Stück', 'Verbandmaterial', ['' => 18], 10, 2],
    ['diag-bz-streifen', 'Blutzuckermessstreifen', 'Stück', 'Diagnostik', [$day('-20 days') => 25, $day('+70 days') => 50, $day('+1 year') => 100], 50, 10],
    ['diag-thermo-huelle', 'Thermometer-Schutzhüllen', 'Stück', 'Diagnostik', ['' => 200], 100, 20],
    ['beat-maske-4', 'Beatmungsmaske Gr. 4', 'Stück', 'Beatmung', ['' => 3], 4, 1],
    ['beat-guedel-3', 'Guedeltubus Gr. 3', 'Stück', 'Beatmung', [$day('+2 years') => 6], 4, 1],
    ['inf-vvk-18g', 'Venenverweilkanüle 18G', 'Stück', 'Infusion & Injektion', [$day('-5 days') => 4, $day('+2 years') => 20], 10, 0],
    ['imm-sam-splint', 'SAM Splint', 'Stück', 'Immobilisation', ['' => 5], 3, 1],
    ['hyg-haendedesinf', 'Händedesinfektion 100 ml', 'Flasche', 'Hygiene & Desinfektion', [$day('+30 days') => 8, $day('+2 years') => 20], 10, 1],
    ['hyg-handschuhe-m', 'Einmalhandschuhe M', 'Paar', 'Hygiene & Desinfektion', [$day('+3 years') => 300], 100, 20],
    ['schutz-ffp2', 'FFP2-Maske', 'Stück', 'Schutzausrüstung', [$day('+2 years') => 60], 40, 5],
];

$ids = [];

foreach ($demoArticles as [$number, $name, $unit, $category, $stockByExpiry, $mainMinimum, $bagMinimum]) {
    $articleId = $articles->create($number, $name, '', $unit, (int) $categoryIds[$category]);
    $ids[$number] = $articleId;

    foreach ($stockByExpiry as $expiry => $quantity) {
        $batchId = $expiry === '' ? null : $batches->findOrCreate($articleId, (string) $expiry);

        $stock->move($articleId, $mainId, $quantity, 'receipt', 'Erstausstattung', $batchId);
    }

    /*
     * Soll-Ausstattung nur für Rucksack 1 und 2; Rucksack 3 ist eine
     * Reserve ohne festen Inhalt.
     */
    $minimums = [$mainId => $mainMinimum];

    foreach (array_slice($bagIds, 0, 2) as $bagId) {
        $minimums[$bagId] = $bagMinimum > 0 ? $bagMinimum : null;
    }

    $stock->saveMinimums($articleId, $minimums);
}

/*
 * Rucksack 1 und 2 nach Soll bestücken. Im Hauptlager bleiben dadurch
 * einige Artikel unter Mindestbestand (z. B. Heftpflaster, Beatmungsmaske),
 * damit Warnungen in Artikelliste und Wochenbericht sichtbar sind.
 */
foreach ($demoArticles as [$number, , , , , , $bagMinimum]) {
    foreach (array_slice($bagIds, 0, 2) as $bagId) {
        for ($i = 0; $i < $bagMinimum; $i++) {
            $stock->transferOldest($ids[$number], $mainId, $bagId, 'Rucksack bestückt');
        }
    }
}

/*
 * Entnahmen von heute (Sanitätsdienst).
 */
$issues = [
    'verb-mullbinde-8' => 3,
    'verb-kompresse-10' => 6,
    'verb-heftpflaster-25' => 1,
    'hyg-handschuhe-m' => 12,
    'diag-bz-streifen' => 2,
    'verb-rettungsdecke' => 1,
];

foreach ($issues as $number => $count) {
    for ($i = 0; $i < $count; $i++) {
        $stock->issueOldest($ids[$number], $mainId, 'Sanitätsdienst Stadtfest');
    }
}

echo "Demo-Datenbank angelegt: {$target}\n";
