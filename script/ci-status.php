<?php

/*
|--------------------------------------------------------------------------
| Testergebnis eines Commits auf GitHub (GitHub Actions)
|--------------------------------------------------------------------------
|
| Aufruf durch script/pull.sh vor dem Update:
|
|   php script/ci-status.php <commit>
|
| Gibt eine Zeile für den Menschen aus. Exit-Code:
|   0 = Tests grün
|   1 = Tests rot
|   2 = läuft noch oder unbekannt (kein Internet, kein GitHub, kein Lauf)
|
| Fragt die öffentliche GitHub-Schnittstelle ohne Anmeldung ab; das
| Repository ergibt sich aus `git remote get-url origin`.
|--------------------------------------------------------------------------
*/

$commit = $argv[1] ?? '';

if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "Aufruf: php script/ci-status.php <commit-sha>\n");
    exit(2);
}

$remote = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' remote get-url origin 2> /dev/null'));

if (!preg_match('#github\.com[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $matches)) {
    echo "Testergebnis unbekannt: origin ist kein GitHub-Repository.\n";
    exit(2);
}

$response = @file_get_contents(
    'https://api.github.com/repos/' . $matches[1] . '/actions/runs?head_sha=' . $commit,
    false,
    stream_context_create(['http' => [
        'header' => "User-Agent: SanLager-pull\r\nAccept: application/vnd.github+json\r\n",
        'timeout' => 10,
    ]])
);

$runs = is_string($response) ? (json_decode($response, true)['workflow_runs'] ?? null) : null;

if (!is_array($runs)) {
    echo "Testergebnis unbekannt: GitHub nicht erreichbar.\n";
    exit(2);
}

if ($runs === []) {
    echo "Testergebnis unbekannt: Für diesen Stand gibt es auf GitHub keinen Testlauf.\n";
    exit(2);
}

// Neuester Lauf zuerst; bei mehreren Workflows zählt jeder.
$failed = array_filter($runs, static fn (array $run): bool => $run['status'] === 'completed' && $run['conclusion'] !== 'success');
$pending = array_filter($runs, static fn (array $run): bool => $run['status'] !== 'completed');

if ($failed !== []) {
    echo "Tests ROT – dieser Stand hat Fehler (siehe GitHub → Actions).\n";
    exit(1);
}

if ($pending !== []) {
    echo "Tests laufen noch – in ein paar Minuten noch einmal versuchen.\n";
    exit(2);
}

echo "Tests grün.\n";
exit(0);
