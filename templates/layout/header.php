<?php
/*
 * Seitenkopf: HTML-Head, Navigation und Meldungen. Erwartet $page,
 * $message, $messageType und $error aus public/index.php.
 */
?>
<!DOCTYPE html>
<html lang="de">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>SanLager</title>

    <link
        rel="stylesheet"
        href="/css/app.css"
    >

</head>

<body>

<header class="topbar">

    <div class="topbar-inner">

<a href="?page=issue" class="brand">
    <img
        src="/images/pflaster.svg"
        alt="DRK Bereitschaften"
    >
    <div class="brand-text">
        <strong>SanLager</strong>
        <span>Materialverwaltung</span>
    </div>
</a>

<nav>
    <a href="?page=issue" class="<?= $page === 'issue' ? 'active' : '' ?>">
        Buchen
    </a>
    <a href="?page=today_issues" class="<?= $page === 'today_issues' ? 'active' : '' ?>">
        Heute ausgebucht
    </a>
    <a href="?page=expiry" class="<?= $page === 'expiry' ? 'active' : '' ?>">
        MHD-Übersicht
    </a>
    <a href="?page=articles" class="<?= $page === 'articles' ? 'active' : '' ?>">
        Artikel
    </a>
    <a href="?page=categories" class="<?= $page === 'categories' ? 'active' : '' ?>">
        Kategorien
    </a>
    <a href="?page=locations" class="<?= $page === 'locations' ? 'active' : '' ?>">
        Lagerorte
    </a>
</nav>

    </div>

</header>

<main class="container">

    <?php if ($message): ?>

        <div class="alert <?= $messageType ?>">
            <?= h($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert error">
            <?= h($error) ?>
        </div>

    <?php endif; ?>
