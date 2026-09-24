        <div class="page-header">

            <div>

                <h1>Einheiten</h1>

                <p>
                    Einheiten für die Artikel, jeweils in Einzahl und Mehrzahl
                    („1 Rolle“, „5 Rollen“).
                </p>

            </div>

        </div>


        <div class="category-layout">

            <section class="card">

                <div class="card-header">

                    <h2>
                        <?= $editUnit ? 'Einheit bearbeiten' : 'Neue Einheit' ?>
                    </h2>

                </div>

                <form method="post" class="form">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editUnit ? 'update_unit' : 'create_unit' ?>"
                    >

                    <?php if ($editUnit): ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $editUnit['id'] ?>"
                        >

                    <?php endif; ?>


                    <label>

                        <span>Einzahl</span>

                        <input
                            type="text"
                            name="name"
                            required
                            maxlength="50"
                            value="<?= h($editUnit['name'] ?? '') ?>"
                            placeholder="z. B. Rolle"
                        >

                    </label>


                    <label>

                        <span>Mehrzahl</span>

                        <input
                            type="text"
                            name="plural"
                            maxlength="50"
                            value="<?= h($editUnit['plural'] ?? '') ?>"
                            placeholder="z. B. Rollen – leer: wie Einzahl"
                        >

                    </label>

                    <?php if ($editUnit): ?>

                        <p class="form-help">
                            Die Änderung gilt für alle Artikel mit dieser Einheit.
                        </p>

                    <?php endif; ?>


                    <div class="form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editUnit ? 'Einheit speichern' : 'Einheit anlegen' ?>
                        </button>

                        <?php if ($editUnit): ?>

                            <a
                                href="?page=units"
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

                    <h2>Einheiten</h2>

                </div>

                <div class="card-body">

                    <p class="form-help">
                        Per Drag &amp; Drop sortieren – in dieser Reihenfolge
                        erscheinen sie bei den Artikeln zur Auswahl.
                    </p>


                    <div
                        id="unit-list"
                        class="category-list"
                        data-sortable-list
                        data-sortable-row=".location-sort-row"
                        data-sortable-id-attribute="unitId"
                        data-sortable-action="reorder_units"
                    >

                        <?php foreach ($unitList as $unit): ?>

                            <div
                                class="location-sort-row"
                                draggable="true"
                                data-unit-id="<?= (int) $unit['id'] ?>"
                            >

                                <div class="category-drag">
                                    ⋮⋮
                                </div>

                                <div class="location-sort-info">

                                    <strong>
                                        <?= h($unit['name']) ?>
                                        <?php if ($unit['plural'] !== $unit['name']): ?>
                                            / <?= h($unit['plural']) ?>
                                        <?php endif; ?>
                                    </strong>

                                    <span>
                                        <?= (int) $unit['article_count'] === 1
                                            ? '1 Artikel'
                                            : (int) $unit['article_count'] . ' Artikel' ?>
                                    </span>

                                </div>

                                <div class="category-actions">

                                    <a
                                        href="?page=units&edit=<?= (int) $unit['id'] ?>"
                                        class="button button-secondary small"
                                    >
                                        Bearbeiten
                                    </a>

                                    <?php if ((int) $unit['article_count'] === 0): ?>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Einheit wirklich löschen?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_unit"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $unit['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="button button-danger small"
                                            >
                                                Löschen
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </section>

        </div>
