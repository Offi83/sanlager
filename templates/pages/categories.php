        <div class="page-header">

            <div>

                <h1>Kategorien</h1>

                <p>
                    Kategorien für die Artikelverwaltung verwalten und sortieren.
                </p>

            </div>

        </div>


        <div class="category-layout">

            <section class="card">

                <div class="card-header">

                    <h2>
                        <?= $editCategory ? 'Kategorie bearbeiten' : 'Neue Kategorie' ?>
                    </h2>

                </div>

                <form method="post" class="form">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editCategory ? 'update_category' : 'create_category' ?>"
                    >

                    <?php if ($editCategory): ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $editCategory['id'] ?>"
                        >

                    <?php endif; ?>


                    <label>

                        <span>Name</span>

                        <input
                            type="text"
                            name="name"
                            required
                            maxlength="100"
                            value="<?= h($editCategory['name'] ?? '') ?>"
                            placeholder="z. B. Verbandmaterial"
                        >

                    </label>


                    <label>

                        <span>Kürzel</span>

                        <input
                            type="text"
                            name="short_name"
                            required
                            maxlength="20"
                            value="<?= h($editCategory['short_name'] ?? '') ?>"
                            placeholder="z. B. VM"
                        >

                    </label>


                    <label>

                        <span>Farbe</span>

                        <div class="category-color-input">

                            <input
                                type="color"
                                name="color"
                                value="<?= h($editCategory['color'] ?? '#d71920') ?>"
                                title="Kategorie-Farbe auswählen"
                            >

                            <span>
                                Farbe der Kategorie
                            </span>

                        </div>

                    </label>


                    <div class="form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editCategory ? 'Kategorie speichern' : 'Kategorie anlegen' ?>
                        </button>

                        <?php if ($editCategory): ?>

                            <a
                                href="?page=categories"
                                class="button button-secondary"
                            >
                                Abbrechen
                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>


            <section class="card">

                <div class="card-header">

                    <h2>Kategorien</h2>

                </div>

                <div class="card-body">

                    <p class="form-help">
                        Kategorien per Drag &amp; Drop in die gewünschte Reihenfolge ziehen.
                    </p>


                    <div
                        id="category-list"
                        class="category-list"
                        data-sortable-list
                        data-sortable-row=".category-row"
                        data-sortable-id-attribute="categoryId"
                        data-sortable-action="reorder_categories"
                    >

                        <?php foreach ($categoryList as $category): ?>

                            <div
                                class="category-row"
                                draggable="true"
                                data-category-id="<?= (int) $category['id'] ?>"
                            >

                                <div class="category-drag">
                                    ⋮⋮
                                </div>


                                <div
                                    class="category-color"
                                    style="background-color: <?= h($category['color']) ?>"
                                ></div>


                                <div class="category-info">

                                    <strong>
                                        <?= h($category['name']) ?>
                                    </strong>

                                    <span>
                                        <?= h($category['short_name']) ?>
                                    </span>

                                </div>


                                <div class="category-actions">

                                    <a
                                        href="?page=categories&edit=<?= (int) $category['id'] ?>"
                                        class="button button-secondary small"
                                    >
                                        Bearbeiten
                                    </a>


                                    <form
                                        method="post"
                                        onsubmit="return confirm('Kategorie wirklich löschen?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_category"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $category['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-danger small"
                                        >
                                            Löschen
                                        </button>

                                    </form>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </section>

        </div>
