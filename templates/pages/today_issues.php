        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>Heute</h1>

                    <p>
                        Übersicht aller heutigen Ausbuchungen, Umbuchungen und Entsorgungen –
                        mit Rückgängig.
                    </p>

                </div>

                <div class="actions">

                    <a
                        href="?page=today_issues"
                        class="button"
                    >
                        Aktualisieren
                    </a>

                </div>

            </div>

            <div class="card">

                <div class="today-summary">

                    <?php if ($todayIssuedSummary === ''): ?>

                        <span>
                            Heute noch nichts ausgebucht
                        </span>

                    <?php else: ?>

                        <strong>
                            <?= h($todayIssuedSummary) ?>
                        </strong>

                        <span>
                            ausgebucht
                        </span>

                    <?php endif; ?>

                    <?php if ($todayDisposedSummary !== ''): ?>

                        <span class="today-disposed">
                            + <?= h($todayDisposedSummary) ?> entsorgt
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <div class="card">

                <?php if (!$todayIssues): ?>

                    <p class="empty-state compact">
                        Heute wurden noch keine Artikel ausgebucht oder entsorgt.
                    </p>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="table-with-article-number table-cards">

                            <thead>

                                <tr>


                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Menge</th>
                                    <th>MHD</th>
                                    <th>Lagerort</th>
                                    <th>Rückgängig</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($todayIssues as $movement): ?>

                                    <tr>



                                        <td>
                                            <strong>
                                                <?= h($movement['article_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(
                                                $movement['article_number']
                                                    ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(quantityText((int) $movement['quantity'], $movement)) ?>
                                            </strong>

                                            <?php if ($movement['kind'] === 'disposal'): ?>

                                                <span class="disposed-badge">
                                                    entsorgt
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= h(formatExpiry(
                                                $movement['expiry_date'],
                                                (int) $movement['has_expiry'] === 1
                                            )) ?>
                                        </td>

                                        <td>
                                            <?= h($movement['location_name']) ?>
                                        </td>

                                        <td class="undo-cell">
                                            <?= renderUndoForm(
                                                $movement['kind'] === 'disposal' ? 'undo_disposal' : 'undo_issue',
                                                [
                                                    'article_id' => (int) $movement['article_id'],
                                                    'batch_id' => (int) $movement['batch_id'],
                                                    'location_id' => (int) $movement['location_id'],
                                                ],
                                                (int) $movement['quantity'],
                                                quantityText((int) $movement['quantity'], $movement) . ' '
                                                    . $movement['article_name'] . ' wieder in '
                                                    . $movement['location_name'] . ' einbuchen'
                                                    . ($movement['kind'] === 'disposal' ? ' (Entsorgung rückgängig)?' : '?')
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($todayTransfers): ?>

                <h2 class="today-section-title">
                    Heute umgebucht
                </h2>

                <div class="card">

                    <div class="table-wrapper">

                        <table class="table-cards">

                            <thead>

                                <tr>
                                    <th>Artikel</th>
                                    <th>Menge</th>
                                    <th>MHD</th>
                                    <th>Von → Nach</th>
                                    <th>Rückgängig</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($todayTransfers as $transfer): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= h($transfer['article_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(quantityText((int) $transfer['quantity'], $transfer)) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(formatExpiry(
                                                $transfer['expiry_date'],
                                                (int) $transfer['has_expiry'] === 1
                                            )) ?>
                                        </td>

                                        <td>
                                            <?= h($transfer['from_location_name']) ?>
                                            →
                                            <?= h($transfer['to_location_name']) ?>
                                        </td>

                                        <td class="undo-cell">
                                            <?= renderUndoForm(
                                                'undo_transfer',
                                                [
                                                    'article_id' => (int) $transfer['article_id'],
                                                    'batch_id' => (int) $transfer['batch_id'],
                                                    'location_id' => (int) $transfer['from_location_id'],
                                                    'to_location_id' => (int) $transfer['to_location_id'],
                                                ],
                                                (int) $transfer['quantity'],
                                                quantityText((int) $transfer['quantity'], $transfer) . ' '
                                                    . $transfer['article_name'] . ' von ' . $transfer['to_location_name']
                                                    . ' zurück nach ' . $transfer['from_location_name'] . ' buchen?'
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endif; ?>


        </div>
