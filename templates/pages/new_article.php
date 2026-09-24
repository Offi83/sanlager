        <div class="new-article-page">

        <div class="page-header">

            <div>

                <a
                    href="?page=articles"
                    class="back-link"
                >
                    ← Artikel
                </a>

                <h1>Artikel anlegen</h1>

                <p>
                    Neuen Lagerartikel erfassen
                </p>

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
                    value="create_article"
                >

                <div class="form-grid">

                    <label>

                        <span>
                            Artikelname *
                        </span>

                        <input
                            type="text"
                            name="name"
                            required
                            autofocus
                        >

                    </label>


                    <label>

                        <span>
                            Artikelnummer *
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            id="new-article-number"
                            required
                        >

                        <small class="form-hint">
                            Vorschlag wird aus Kategorie und Artikelname erzeugt.
                        </small>

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
                                data-short-name="<?= h($category['short_name']) ?>"
                                <?= (int) $category['id'] === $newArticleCategoryId ? 'selected' : '' ?>
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

                        <select
                            name="unit_id"
                            required
                        >
                            <?php foreach ($unitList as $unit): ?>
                                <option
                                    value="<?= (int) $unit['id'] ?>"
                                >
                                    <?= h($unit['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    </label>

                </div>


                <label>

                    <span>
                        Beschreibung
                    </span>

                    <textarea
                        name="description"
                        rows="3"
                    ></textarea>

                </label>

                <?php /* Verstecktes 0 davor: Nicht angekreuzt kommt sonst gar nichts an. */ ?>
                <input
                    type="hidden"
                    name="has_expiry"
                    value="0"
                >

                <label class="checkbox-field">

                    <input
                        type="checkbox"
                        name="has_expiry"
                        value="1"
                        checked
                    >

                    <span>
                        Artikel hat ein MHD
                        <small class="form-hint">
                            Ohne Haken (z. B. Mullbinden) entfällt die MHD-Auswahl beim Buchen.
                        </small>
                    </span>

                </label>


                <div class="form-actions">

                    <?php
                    /*
                     * Zwei Wege nach dem Anlegen: gleich den nächsten Artikel
                     * (Kategorie bleibt gewählt; auch per Enter) oder den neuen
                     * Artikel öffnen – zum Einlagern, Mindestbestand, Etikett.
                     */
                    ?>
                    <button
                        type="submit"
                        name="after"
                        value="next"
                        class="button button-primary"
                    >
                        Anlegen &amp; nächster Artikel
                    </button>

                    <button
                        type="submit"
                        name="after"
                        value="open"
                        class="button button-secondary"
                    >
                        Anlegen &amp; öffnen
                    </button>

                    <a
                        href="?page=articles"
                        class="button button-secondary"
                    >
                        Abbrechen
                    </a>

                </div>

            </form>

        </div>

        </div>
