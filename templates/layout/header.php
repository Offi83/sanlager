<?php
/*
 * Seitenkopf: HTML-Head, Navigation und Meldungen. Erwartet $page,
 * $navCounts, $message, $messageType und $error aus public/index.php.
 *
 * Die Navigation hat vier Bereiche. Kontrolle und Verwaltung fassen
 * mehrere Seiten zusammen; deren Übersichtsseiten zeigen darunter
 * Reiter (kein Aufklappmenü – auf dem Touchdisplay bräuchte das einen
 * Tipp mehr und verdeckt den Inhalt). Detailseiten (Artikel, Lagerort,
 * Etikett, ...) markieren nur ihren Bereich; sie haben einen Zurück-Link.
 *
 * Die Zahlen an Kontrolle und den Reitern zeigen, wo etwas zu tun ist:
 * abgelaufene Chargen bzw. Artikel unter Mindestbestand.
 */

$navSections = [
    [
        'label' => 'Buchen',
        'href' => '?page=issue',
        'pages' => ['issue'],
        'tabs' => [],
    ],
    [
        'label' => 'Heute',
        'href' => '?page=today_issues',
        'pages' => ['today_issues'],
        'tabs' => [],
    ],
    [
        'label' => 'Kontrolle',
        'href' => '?page=expiry',
        'pages' => ['expiry', 'restock'],
        'tabs' => ['expiry' => 'MHD', 'restock' => 'Auffüllen'],
    ],
    [
        'label' => 'Verwaltung',
        'href' => '?page=articles',
        'pages' => [
            'articles', 'article', 'new_article', 'edit_article', 'label', 'labels',
            'categories', 'units', 'locations', 'location', 'packlist', 'inventory',
        ],
        'tabs' => [
            'articles' => 'Artikel',
            'categories' => 'Kategorien',
            'units' => 'Einheiten',
            'locations' => 'Lagerorte',
        ],
    ],
];

$navBadge = static fn (int $count): string => $count > 0
    ? ' <span class="nav-badge">' . $count . '</span>'
    : '';
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
        alt="SanLager"
    >
    <div class="brand-text">
        <strong>SanLager</strong>
        <span>Materialverwaltung</span>
    </div>
</a>

<nav class="main-nav">
    <?php foreach ($navSections as $section): ?>
        <a
            href="<?= $section['href'] ?>"
            class="<?= in_array($page, $section['pages'], true) ? 'active' : '' ?>"
        >
            <?= h($section['label']) ?><?= $navBadge(array_sum(
                array_intersect_key($navCounts, $section['tabs'])
            )) ?>
        </a>
    <?php endforeach; ?>
</nav>

    </div>

</header>

<main class="container">

    <?php foreach ($navSections as $section): ?>

        <?php if (isset($section['tabs'][$page])): ?>

            <nav class="sub-nav" aria-label="<?= h($section['label']) ?>">
                <?php foreach ($section['tabs'] as $tabPage => $tabLabel): ?>
                    <a
                        href="?page=<?= $tabPage ?>"
                        class="<?= $tabPage === $page ? 'active' : '' ?>"
                    >
                        <?= h($tabLabel) ?><?= $navBadge($navCounts[$tabPage] ?? 0) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

        <?php endif; ?>

    <?php endforeach; ?>

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
