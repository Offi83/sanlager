        <?php /* Leiste wird beim Drucken ausgeblendet, gedruckt wird nur .packlist (app.css). */ ?>
        <div class="page-header">

            <div>

                <a
                    href="?page=location&id=<?= (int) $viewLocation['id'] ?>"
                    class="back-link"
                >
                    ← <?= h($viewLocation['name']) ?>
                </a>

                <h1>Packliste</h1>

                <p>
                    Soll und Ist je Artikel zum Abhaken und Zählen. Die Zählung
                    danach unter „Inventur“ eintragen.
                </p>

            </div>

            <div class="actions">

                <a
                    href="?page=inventory&id=<?= (int) $viewLocation['id'] ?>"
                    class="button button-secondary"
                >
                    Inventur
                </a>

                <button
                    type="button"
                    class="button button-primary"
                    onclick="window.print()"
                >
                    Drucken
                </button>

            </div>

        </div>

        <div class="packlist">

            <div class="packlist-head">

                <h2>
                    Packliste <?= h($viewLocation['name']) ?>
                </h2>

                <span>
                    Stand <?= date('d.m.Y') ?>
                </span>

            </div>

            <?php if (!$checklist): ?>

                <p class="packlist-empty">
                    Für diesen Lagerort ist nichts hinterlegt: kein Bestand und
                    kein Mindestbestand.
                </p>

            <?php else: ?>

                <table>

                    <thead>

                        <tr>
                            <th class="packlist-check-column">✓</th>
                            <th>Artikel</th>
                            <th>Soll</th>
                            <th>Ist (MHD)</th>
                            <th class="packlist-count">Gezählt</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php $currentCategory = null; ?>

                        <?php foreach ($checklist as $row): ?>

                            <?php if ($currentCategory !== ($row['category_name'] ?? '')): ?>

                                <?php $currentCategory = $row['category_name'] ?? ''; ?>

                                <tr class="packlist-category">
                                    <th
                                        colspan="5"
                                        style="border-left-color: <?= h($row['category_color'] ?? '#64748b') ?>;"
                                    >
                                        <?= h($row['category_name'] ?? 'Ohne Kategorie') ?>
                                    </th>
                                </tr>

                            <?php endif; ?>

                            <tr>

                                <td>
                                    <span class="packlist-check"></span>
                                </td>

                                <td>
                                    <strong><?= h($row['article_name']) ?></strong>
                                    <?php if ($row['article_number']): ?>
                                        <span class="packlist-number"><?= h($row['article_number']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="packlist-quantity">
                                    <?= $row['minimum_stock'] !== null
                                        ? h(quantityText($row['minimum_stock'], $row))
                                        : '–' ?>
                                </td>

                                <td>

                                    <?php foreach ($row['batches'] as $batch): ?>

                                        <?php $batchExpiry = expiryInfo($batch['expiry_date']); ?>

                                        <div class="<?= $batchExpiry['class'] === 'expiry-expired' ? 'packlist-expired' : '' ?>">
                                            <?= h(quantityText($batch['quantity'], $row)) ?><?php if ($batch['expiry_date']): ?>,
                                                <?= h(formatDate($batch['expiry_date'])) ?><?= $batchExpiry['class'] === 'expiry-expired'
                                                    ? ' – abgelaufen'
                                                    : '' ?>
                                            <?php endif; ?>
                                        </div>

                                    <?php endforeach; ?>

                                    <?php if (!$row['batches']): ?>
                                        <div>nichts</div>
                                    <?php endif; ?>

                                    <?php if ($row['missing_quantity'] > 0): ?>
                                        <div class="packlist-missing">
                                            fehlt: <?= h(quantityText($row['missing_quantity'], $row)) ?>
                                        </div>
                                    <?php endif; ?>

                                </td>

                                <td class="packlist-count"></td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

            <div class="packlist-signature">
                <span>Geprüft am: ____________________</span>
                <span>Von: ______________________________</span>
            </div>

        </div>
