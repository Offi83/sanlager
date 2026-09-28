    <?php if ($labelConfig->usesPrinter()): ?>

        <?php /* Etikettendrucker: Vorschau des Bildes, das an den Drucker geht, und Drucken. */ ?>

        <div class="page-header">

            <div>

                <a
                    href="?page=article&id=<?= (int) $article['id'] ?>"
                    class="back-link"
                >
                    ← <?= h($article['name']) ?>
                </a>

                <h1>Etikett drucken</h1>

                <p>
                    Etikettendrucker: <?= h($labelConfig->description()) ?>
                </p>

            </div>

        </div>

        <div class="card">

            <div class="card-body label-printer">

                <img
                    src="<?= h('?page=label_image&id=' . (int) $article['id']) ?>"
                    alt="Vorschau des Etiketts für <?= h($article['name']) ?>"
                    class="label-preview"
                    style="aspect-ratio: <?= (int) $labelConfig->lengthMm ?> / <?= (int) $labelConfig->widthMm ?>"
                >

                <form method="post">

                    <input type="hidden" name="action" value="print_labels">
                    <input type="hidden" name="return" value="label">

                    <?php /* Je Klick ein Etikett – mehrere über die Sammeletiketten. */ ?>
                    <input type="hidden" name="qty[<?= (int) $article['id'] ?>]" value="1">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Drucken
                    </button>

                </form>

            </div>

        </div>

    <?php else: ?>

        <?php foreach ($labelSheets as $labelSheet): ?>
            <?= renderLabelSheet($labelSheet) ?>
        <?php endforeach; ?>

    <?php endif; ?>
