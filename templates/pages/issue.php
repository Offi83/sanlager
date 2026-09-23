        <div class="issue-page">

            <div class="page-header">

                <div>

                    <h1>Buchen</h1>

                    <p>
                        Artikelnummer scannen oder eingeben, Von und
                        Nach wählen. Es wird automatisch ein
                        Stück mit dem ältesten MHD ausgebucht oder an
                        den gewählten Lagerort umgebucht.
                    </p>

                </div>

            </div>

            <div class="card issue-card">

                <div class="issue-toolbar">

                    <button
                        type="button"
                        class="button button-primary issue-scan-button"
                        id="issue-start-scan"
                        hidden
                    >
                        Scanner starten
                    </button>

                    <?php /* Richtung der Buchung, gut sichtbar direkt über dem Kamerabild – wird von booking.js aktualisiert. */ ?>
                    <div
                        id="issue-mode"
                        class="issue-mode <?= $issueTarget === 'issue' ? 'issue-mode-issue' : 'issue-mode-transfer' ?>"
                        aria-live="polite"
                    ><?= h($issueModeText) ?></div>

                </div>

                <div
                    id="issue-scanner"
                    class="issue-scanner"
                    hidden
                ></div>

                <div
                    id="issue-scan-status"
                    class="issue-scan-status"
                    hidden
                ></div>

                <div
                    id="issue-scan-result"
                    class="scan-result"
                    hidden
                ></div>

                <form
                    method="post"
                    class="issue-form"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="issue"
                    >

                    <label class="issue-article-field">

                        <span>
                            Artikelnummer
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            id="issue-article-number"
                            autocomplete="off"
                            spellcheck="false"
                            autofocus
                            required
                        >

                    </label>

                    <label class="issue-target-field">

                        <span>
                            Von
                        </span>

                        <select
                            name="source"
                            id="issue-source"
                        >

                            <?php foreach ($allLocations as $sourceLocation): ?>

                                <option
                                    value="<?= (int) $sourceLocation['id'] ?>"
                                    <?= (int) $sourceLocation['id'] === $issueSourceId ? 'selected' : '' ?>
                                >
                                    <?= h($sourceLocation['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <label class="issue-target-field">

                        <span>
                            Nach
                        </span>

                        <select
                            name="target"
                            id="issue-target"
                        >

                            <option value="issue" <?= $issueTarget === 'issue' ? 'selected' : '' ?>>
                                Ausbuchen
                            </option>

                            <?php foreach ($allLocations as $targetLocation): ?>

                                <option
                                    value="<?= (int) $targetLocation['id'] ?>"
                                    <?= (string) $targetLocation['id'] === $issueTarget ? 'selected' : '' ?>
                                >
                                    <?= h($targetLocation['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <button
                        type="submit"
                        class="button button-primary"
                        id="issue-submit"
                    >
                        Ausbuchen
                    </button>

                </form>

            </div>

        </div>
