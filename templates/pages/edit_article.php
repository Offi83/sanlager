        <div class="edit-article-page">

        <?php

        $articleId = (int) ($_GET['id'] ?? 0);

        $editArticle =
            $articles->find($articleId);

        if (!$editArticle) {
            redirect('?page=articles');
        }

        $editArticleStock = $stock->getPhysicalStock($articleId);

        ?>

        <div class="page-header">

            <div>

                <h1>
                    Artikel bearbeiten
                </h1>

                <p>
                    <?= h($editArticle['name']) ?>
                </p>

            </div>

            <div class="actions">

                <a
                    href="?page=label&id=<?= (int) $editArticle['id'] ?>"
                    class="button"
                    target="_blank"
                >
                    Etikett drucken
                </a>

                <a
                    href="?page=article&id=<?= (int) $editArticle['id'] ?>"
                    class="button button-secondary"
                >
                    Abbrechen
                </a>

            </div>

        </div>


        <div class="card">

            <form
                method="post"
                class="form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="update_article"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int) $editArticle['id'] ?>"
                >


                <div class="form-grid">

                    <label>

                        <span>
                            Artikelname *
                        </span>

                        <input
                            type="text"
                            name="name"
                            value="<?= h($editArticle['name']) ?>"
                            required
                        >

                    </label>


                    <label>

                        <span>
                            Artikelnummer
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            value="<?= h($editArticle['article_number']) ?>"
                            required
                        >

                    </label>



            <label>

                <span>
                    Kategorie
                </span>

                <select
                    name="category_id"
                    required
                >

                    <?php foreach ($categoryList as $category): ?>

                        <option
                            value="<?= (int) $category['id'] ?>"
                            <?= (int) $editArticle['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= h($category['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                    </label>


                    <label>

                        <span>
                            Einheit
                        </span>

                        <input
                            type="text"
                            name="unit"
                            value="<?= h($editArticle['unit']) ?>"
                        >

                    </label>

                </div>


                <label>

                    <span>
                        Beschreibung
                    </span>

                    <textarea
                        name="description"
                        rows="3"
                    ><?= h($editArticle['description']) ?></textarea>

                </label>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Speichern
                    </button>

                    <a
                        href="?page=article&id=<?= (int) $editArticle['id'] ?>"
                        class="button button-secondary"
                    >
                        Abbrechen
                    </a>

                </div>

            </form>

        </div>


        <div class="card danger-zone">

            <div class="card-header">

                <h2>
                    Artikel löschen
                </h2>

            </div>

            <div class="card-body">

                <?php if ($editArticleStock > 0): ?>

                    <p>
                        Löschen ist erst möglich, wenn kein Bestand mehr vorhanden ist.
                        Aktuell:
                        <strong>
                            <?= $editArticleStock ?>
                            <?= h($editArticle['unit']) ?>
                        </strong>
                        (abgelaufene Chargen eingeschlossen) – bitte zuerst
                        <a href="?page=article&id=<?= (int) $editArticle['id'] ?>">auf der Artikelseite</a>
                        ausbuchen oder entsorgen.
                    </p>

                <?php else: ?>

                    <p>
                        Der Artikel wird aus der normalen
                        Artikelübersicht entfernt.
                    </p>

                <?php endif; ?>

                <form
                    method="post"
                    onsubmit="return confirm('Artikel wirklich löschen?');"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="deactivate_article"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int) $editArticle['id'] ?>"
                    >

                    <button
                        type="submit"
                        class="button button-danger"
                        <?= $editArticleStock > 0 ? 'disabled' : '' ?>
                    >
                        Artikel löschen
                    </button>

                </form>

            </div>

        </div>

        </div>


    
