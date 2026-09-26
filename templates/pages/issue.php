        <div class="issue-page">

            <div class="page-header">

                <div>

                    <h1>Buchen</h1>

                    <p>
                        Von und Nach wählen, bei Bedarf die Menge
                        ändern, dann Artikelnummer scannen oder
                        eingeben. Beim Aus- und Umbuchen wird
                        automatisch das älteste MHD genommen.
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

                    <div class="issue-mode-row">

                        <?php /* Richtung der Buchung, gut sichtbar direkt über dem Kamerabild – wird von booking.js aktualisiert. */ ?>
                        <div
                            id="issue-mode"
                            class="issue-mode <?= $issueModeClass ?>"
                            aria-live="polite"
                        ><?= h($issueModeText) ?></div>

                        <?php /* Ton an/aus (je Gerät gespeichert), erst mit JavaScript sichtbar – booking.js. */ ?>
                        <button
                            type="button"
                            class="button button-secondary icon-button issue-sound-button"
                            id="issue-sound"
                            aria-pressed="true"
                            aria-label="Ton beim Scannen"
                            title="Ton beim Scannen an/aus"
                            hidden
                        >
                            <span class="issue-sound-on"><?= icon('volume') ?></span>
                            <span class="issue-sound-off"><?= icon('volume-off') ?></span>
                        </button>

                    </div>

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

                <?php
                /*
                 * Alarm, wenn beim Aus-/Umbuchen eine abgelaufene Charge
                 * gebucht wurde (booking.js). Muss weggetippt werden; der
                 * Fokus liegt auf dem Text, damit ein Enter vom Hand-Scanner
                 * keinen der Knöpfe auslöst.
                 */
                ?>
                <dialog
                    id="issue-alarm"
                    class="issue-alarm"
                    aria-labelledby="issue-alarm-title"
                >

                    <h2 id="issue-alarm-title">ABGELAUFEN</h2>

                    <p
                        id="issue-alarm-text"
                        tabindex="-1"
                        autofocus
                    ></p>

                    <p>
                        Nicht verwenden! „Aussortieren“ nimmt die Buchung
                        zurück und entsorgt diese Charge
                        <span id="issue-alarm-where"></span>.
                    </p>

                    <p
                        id="issue-alarm-error"
                        class="issue-alarm-error"
                        hidden
                    ></p>

                    <div class="issue-alarm-actions">

                        <button
                            type="button"
                            class="button button-secondary"
                            id="issue-alarm-keep"
                        >
                            Trotzdem verwenden
                        </button>

                        <button
                            type="button"
                            class="button button-danger icon-button"
                            id="issue-alarm-sort-out"
                        >
                            <?= icon('trash') ?> Aussortieren
                        </button>

                    </div>

                </dialog>

                <form
                    method="post"
                    class="issue-form"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="issue"
                    >

                    <?php /* Zeile 1: was gebucht wird. Zeile 2: wohin. */ ?>
                    <div class="issue-row">

                        <?php /* Menge gilt nur für die nächste Buchung, danach wieder 1 (booking.js, Redirect ohne Menge). */ ?>
                        <div class="issue-quantity-field">

                            <label for="issue-quantity">
                                Menge
                            </label>

                            <div class="quantity-stepper">

                                <button
                                    type="button"
                                    class="button button-secondary"
                                    id="issue-quantity-minus"
                                    aria-label="Menge verringern"
                                >−</button>

                                <input
                                    type="text"
                                    name="quantity"
                                    id="issue-quantity"
                                    value="1"
                                    inputmode="numeric"
                                    pattern="[0-9]{1,3}"
                                    maxlength="3"
                                    autocomplete="off"
                                    required
                                >

                                <button
                                    type="button"
                                    class="button button-secondary"
                                    id="issue-quantity-plus"
                                    aria-label="Menge erhöhen"
                                >+</button>

                            </div>

                        </div>

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

                        <?php /* Nur beim Einlagern (booking.js blendet ein/aus). Gilt für alle folgenden Scans. */ ?>
                        <label
                            class="issue-expiry-field"
                            id="issue-expiry-field"
                            <?= $issueIsReceipt ? '' : 'hidden' ?>
                        >

                            <span>
                                MHD
                            </span>

                            <input
                                type="date"
                                name="expiry_date"
                                id="issue-expiry"
                                value="<?= h($issueExpiry) ?>"
                            >

                        </label>

                    </div>

                    <div class="issue-row">

                        <label class="issue-target-field">

                            <span>
                                Von
                            </span>

                            <select
                                name="source"
                                id="issue-source"
                            >

                                <option value="receipt" <?= $issueIsReceipt ? 'selected' : '' ?>>
                                    Einlagern
                                </option>

                                <?php foreach ($allLocations as $sourceLocation): ?>

                                    <option
                                        value="<?= (int) $sourceLocation['id'] ?>"
                                        <?= !$issueIsReceipt && (int) $sourceLocation['id'] === $issueSourceId ? 'selected' : '' ?>
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
                            <?= $issueIsReceipt ? 'Einlagern' : 'Ausbuchen' ?>
                        </button>

                    </div>

                    <input
                        type="hidden"
                        name="confirm_expiry"
                        id="issue-confirm-expiry"
                        value="<?= $issueExpiryConfirmed ? '1' : '0' ?>"
                    >


                </form>

            </div>

        </div>
