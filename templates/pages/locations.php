        <div class="page-header">

            <div>

                <h1>Lagerorte</h1>

                <p>
                    Lagerorte für die Bestandsverwaltung anlegen, bearbeiten
                    und sortieren.
                </p>

            </div>

        </div>


        <div class="category-layout">

            <section class="card">

                <div class="card-header">

                    <h2>
                        <?= $editLocation ? 'Lagerort bearbeiten' : 'Neuer Lagerort' ?>
                    </h2>

                </div>

                <form method="post" class="form">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editLocation ? 'update_location' : 'create_location' ?>"
                    >

                    <?php if ($editLocation): ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $editLocation['id'] ?>"
                        >

                    <?php endif; ?>

                    <label>

                        <span>Name</span>

                        <input
                            type="text"
                            name="name"
                            required
                            maxlength="100"
                            value="<?= h($editLocation['name'] ?? '') ?>"
                            placeholder="z. B. Hauptlager"
                        >

                    </label>

                    <label>

                        <span>Beschreibung</span>

                        <textarea
                            name="description"
                            rows="2"
                            placeholder="Optional"
                        ><?= h($editLocation['description'] ?? '') ?></textarea>

                    </label>

                    <div class="form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editLocation ? 'Lagerort speichern' : 'Lagerort anlegen' ?>
                        </button>

                        <?php if ($editLocation): ?>

                            <a
                                href="?page=locations"
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

                    <h2>Lagerorte</h2>

                </div>

                <div class="card-body">

                    <p class="form-help">
                        Lagerorte per Drag &amp; Drop in die gewünschte Reihenfolge ziehen.
                        Diese Reihenfolge bestimmt auch die Auswahl beim Buchen;
                        der oberste Lagerort ist dort vorausgewählt.
                    </p>

                    <?php if (!$locationList): ?>

                        <div class="empty-state compact">
                            Noch keine Lagerorte vorhanden.
                        </div>

                    <?php else: ?>

                        <div
                            id="location-list"
                            class="category-list"
                            data-sortable-list
                            data-sortable-row=".location-sort-row"
                            data-sortable-id-attribute="locationId"
                            data-sortable-action="reorder_locations"
                        >

                            <?php foreach ($locationList as $location): ?>

                                <div
                                    class="location-sort-row"
                                    draggable="true"
                                    data-location-id="<?= (int) $location['id'] ?>"
                                >

                                    <div class="category-drag">
                                        ⋮⋮
                                    </div>

                                    <div class="location-sort-info">

                                        <a href="?page=location&id=<?= (int) $location['id'] ?>">
                                            <strong>
                                                <?= h($location['name']) ?>
                                            </strong>
                                        </a>

                                        <?php if (!empty($location['description'])): ?>

                                            <span>
                                                <?= h($location['description']) ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <div class="category-actions">

                                        <a
                                            href="?page=locations&edit=<?= (int) $location['id'] ?>"
                                            class="button button-secondary small"
                                        >
                                            Bearbeiten
                                        </a>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Lagerort wirklich deaktivieren?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="deactivate_location"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $location['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="button button-danger small"
                                            >
                                                Deaktivieren
                                            </button>

                                        </form>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </div>

        <?php if ($editLocation): ?>

            <div class="card">

                <div class="card-header">
                    <h2>Bestand verschieben</h2>
                </div>

                <div class="card-body">

                    <?php if (!$editLocationHasStock): ?>

                        <p class="form-help">
                            Dieser Lagerort hat aktuell keinen Bestand
                            zum Verschieben.
                        </p>

                    <?php elseif (!$transferTargetLocations): ?>

                        <p class="form-help">
                            Es gibt keinen weiteren Lagerort, an den
                            der Bestand verschoben werden könnte.
                        </p>

                    <?php else: ?>

                        <p class="form-help">
                            Verschiebt den kompletten Bestand von
                            "<?= h($editLocation['name']) ?>" auf einen
                            anderen Lagerort – z. B. um eine Kiste nach
                            einem Dienst wieder vollständig zurück ins
                            Lager zu räumen, ohne jeden Artikel einzeln
                            umbuchen zu müssen.
                        </p>

                        <form
                            method="post"
                            class="form"
                            onsubmit="return confirm('Kompletten Bestand nach ' + this.to_location_id.options[this.to_location_id.selectedIndex].text + ' verschieben?');"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="transfer_all_stock"
                            >

                            <input
                                type="hidden"
                                name="from_location_id"
                                value="<?= (int) $editLocation['id'] ?>"
                            >

                            <label>

                                <span>Nach</span>

                                <select name="to_location_id">

                                    <?php foreach ($transferTargetLocations as $transferTargetLocation): ?>

                                        <option value="<?= (int) $transferTargetLocation['id'] ?>">
                                            <?= h($transferTargetLocation['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </label>

                            <div class="form-actions">

                                <button
                                    type="submit"
                                    class="button button-primary"
                                >
                                    Kompletten Bestand verschieben
                                </button>

                            </div>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>
