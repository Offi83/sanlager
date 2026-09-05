<?php

require_once __DIR__ . '/../vendor/autoload.php';

use LagerApp\Database;

$database = new Database(
    __DIR__ . '/../database/database.sqlite'
);

$db = $database->connection();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DRK Lager-App</title>
</head>
<body>

<h1>DRK Lager-App</h1>

<p>Die Anwendung läuft.</p>

<p>
    PHP: <?= htmlspecialchars(PHP_VERSION) ?>
</p>

<p>
    SQLite:
    <?= htmlspecialchars(
        $db->query('SELECT sqlite_version()')->fetchColumn()
    ) ?>
</p>

</body>
</html>
