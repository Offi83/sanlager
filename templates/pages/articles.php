        <div class="page-header">

            <div>
                <h1>Artikel</h1>

                <p>
                    Sanitätsmaterial und aktueller Bestand
                </p>
            </div>

            <a
                class="button button-primary"
                href="?page=new_article"
            >
                + Artikel anlegen
            </a>

        </div>

        <div class="card article-search-card">
        <div class="card-header"><h2>Suche Artikel</h2></div>
        <div class="card-body">
        <form
            method="get"
            class="search-form"
        >

            <input
                type="hidden"
                name="page"
                value="articles"
            >

            <input
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Artikel suchen ..."
                autofocus
            >

            <select
                name="category"
                class="category-filter"
            >

                <option value="0">
                    Alle Kategorien
                </option>

                <?php foreach ($categoryList as $category): ?>

                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= h($category['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                type="submit"
                class="button"
            >
                Suchen
            </button>

            <?php if ($search !== ''): ?>

                <a
                    href="?page=articles"
                    class="button button-secondary"
                >
                    Zurücksetzen
                </a>

            <?php endif; ?>

        </form>
        </div>
        </div>

        <div class="card">

            <?php if (!$articleList): ?>

                <div class="empty-state">

                    <h2>
                        Keine Artikel gefunden
                    </h2>

                    <p>
                        Legen Sie den ersten Artikel an.
                    </p>

                    <a
                        href="?page=new_article"
                        class="button button-primary"
                    >
                        Artikel anlegen
                    </a>

                </div>

            <?php else: ?>

                <table class="table-with-article-number">

                    <thead>

                    <tr>
                        <th>Artikel</th>
                        <th>Artikelnummer</th>
                        <th>Bestand</th>
                    </tr>

                    </thead>

                    <tbody>

                    <?php
                    $currentCategoryId = null;
                    ?>

                    <?php foreach ($articleList as $item): ?>

                        <?php if (
                            $selectedCategoryId === null
                            && $currentCategoryId !== (int) ($item['category_id'] ?? 0)
                        ): ?>

                            <?php
                            $currentCategoryId = (int) ($item['category_id'] ?? 0);
                            ?>

                            <tr
                                class="article-category-row"
                                style="background-color: <?= h($item['category_color'] ?? '#64748b') ?>;"
                            >
                                <th colspan="3">
                                    <span class="article-category-name">
                                        <?= h($item['category_name'] ?? 'Ohne Kategorie') ?>
                                    </span>
                                </th>
                            </tr>

                        <?php endif; ?>

                        <?php
                        $summary = $articleStockSummaries[(int) $item['id']]
                            ?? \LagerApp\StockRepository::EMPTY_SUMMARY;

                        $total = $summary['total'];
                        $expiredStock = $summary['expired'];
                        $isLow = $summary['is_low'];
                        ?>

                        <tr>

                            <td>

                                <a
                                    href="?page=article&id=<?= (int) $item['id'] ?>"
                                    class="article-link"
                                >
                                    <strong>
                                        <?= h($item['name']) ?>
                                    </strong>
                                </a>

                                <?php if ($item['description']): ?>

                                    <small>
                                        <?= h($item['description']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= h($item['article_number']) ?>
                            </td>

                            <td class="article-stock-cell">

                                <a
                                    href="?page=article&id=<?= (int) $item['id'] ?>"
                                    class="article-stock-link"
                                >
                                    <strong class="<?= $isLow ? 'stock-low' : '' ?>">
                                        <?= $total ?>
                                    </strong>
                                    <?= h(unitText($total, $item)) ?>
                                </a>

                                <?php if ($expiredStock > 0): ?>

                                    <small class="expired-stock-warning">
                                        MHD: <?= $expiredStock ?> abgelaufen
                                    </small>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>
