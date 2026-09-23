    <div class="label-print-page">

        <?php for ($i = 0; $i < 8; $i++): ?>

            <div class="label">

                <div class="label-category">
                    <?= h($article['category_name'] ?? 'Sonstiges') ?>
                </div>

                <div class="label-content">

                    <div class="label-text">

                        <div class="label-name">
                            <?= h($article['name']) ?>
                        </div>

                        <?php if (!empty($article['article_number'])): ?>

                            <div class="label-number">
                                <?= h($article['article_number']) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <?php
                    $labelQrCode = null;

                    if (!empty($article['article_number'])) {
                        $labelQrGenerator = new \LagerApp\QrCodeGenerator();

                        $labelQrCode = $labelQrGenerator->generate(
                            $article['article_number']
                        );
                    }
                    ?>

                    <?php if ($labelQrCode !== null): ?>

                        <div class="label-qr">
                            <?= $labelQrCode ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endfor; ?>

    </div>
