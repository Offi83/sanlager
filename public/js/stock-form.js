/*
 * Artikeldetailseite: "Bestand buchen"-Formular. Der Vorgang ergibt sich
 * aus Von und Nach (siehe StockActions::stockMove()):
 *
 *   Von "Einlagern"  → Nach Lagerort   = Einlagern
 *   Von Lagerort     → Nach "Ausbuchen" = Ausbuchen
 *   Von Lagerort A   → Nach Lagerort B  = Umbuchen
 *
 * Unsinnige Kombinationen (Einlagern → Ausbuchen, A → A) werden
 * ausgeblendet. Die MHD-Optionen tragen den Bestand je Lagerort
 * (data-stock, JSON {lagerortId: menge}): Beim Ausbuchen/Umbuchen wird
 * angezeigt, was am Von-Lagerort liegt, leere Chargen werden ausgeblendet;
 * beim Einlagern zählt der Gesamtbestand (data-quantity), abgelaufene
 * Chargen (data-expired) sind dann ausgeblendet.
 */

document.addEventListener('DOMContentLoaded', function () {

    const fromSelect =
        document.getElementById('stock_from');

    const toSelect =
        document.getElementById('stock_to');

    const batchSelection =
        document.getElementById('batch_selection');

    const newExpiryField =
        document.getElementById('new-expiry-field');

    const expiryInput =
        document.getElementById('expiry_date');

    const submitButton =
        document.getElementById('submit-stock');

    const batchField =
        document.getElementById('batch-selection-field');

    /*
     * Artikel ohne MHD (Haken "Artikel hat ein MHD" nicht gesetzt).
     */
    const hasExpiry =
        document.getElementById('stock-form')?.dataset.hasExpiry !== '0';


    if (
        !fromSelect
        || !toSelect
        || !batchSelection
    ) {
        return;
    }


    function stockAt(option, locationId) {

        try {

            const stock =
                JSON.parse(option.dataset.stock || '{}');

            return parseInt(stock[locationId] || '0', 10);

        } catch (error) {

            return 0;

        }

    }


    /*
     * Blendet in einem Select Optionen aus und wählt, falls die aktuelle
     * Auswahl dabei verschwindet, die erste noch sichtbare Option.
     */
    function hideOptions(select, isHidden) {

        Array.from(select.options).forEach(function (option) {

            const hidden = isHidden(option);

            option.hidden = hidden;
            option.disabled = hidden;

        });

        if (select.selectedOptions[0]?.disabled) {

            const firstFree =
                Array.from(select.options).find(function (option) {
                    return !option.disabled;
                });

            if (firstFree) {
                select.value = firstFree.value;
            }

        }

    }


    function updateForm() {

        /*
         * Nach: nicht derselbe Lagerort wie Von; "Ausbuchen" ergibt nach
         * "Einlagern" keinen Sinn.
         */
        hideOptions(toSelect, function (option) {

            return option.value === fromSelect.value
                || (fromSelect.value === 'receipt' && option.value === 'issue');

        });

        const isReceipt =
            fromSelect.value === 'receipt';

        const isIssue =
            toSelect.value === 'issue';


        /*
         * MHD-Auswahl.
         */
        hideOptions(batchSelection, function (option) {

            if (option.value === 'new') {
                return !isReceipt;
            }

            if (!option.dataset.label) {
                return false;
            }

            /*
             * Artikel ohne MHD: eingelagert wird immer "ohne MHD".
             */
            if (isReceipt && !hasExpiry) {
                return option.value !== 'none';
            }

            /*
             * Einlagern in eine abgelaufene Charge ergibt keinen Sinn.
             */
            if (isReceipt) {
                return option.dataset.expired === '1';
            }

            return stockAt(option, fromSelect.value) <= 0;

        });

        Array.from(batchSelection.options).forEach(function (option) {

            if (!option.dataset.label) {
                return;
            }

            if (isReceipt) {

                option.textContent =
                    option.value === 'none'
                        ? option.dataset.label
                        : option.dataset.label + ' – Bestand: '
                            + (option.dataset.quantity || '0');

                return;

            }

            option.textContent =
                option.dataset.label + ' – hier: '
                + stockAt(option, fromSelect.value);

        });


        /*
         * Artikel ohne MHD: Auswahl nur zeigen, solange am Von-Lagerort
         * noch alter Bestand mit MHD liegt (sonst ist "Ohne MHD" gewählt).
         */
        if (batchField && !hasExpiry) {
            batchField.hidden = !Array.from(batchSelection.options).some(function (option) {
                return option.value !== 'none' && !option.disabled;
            });
        }

        /*
         * Eingabefeld für neues MHD.
         */
        const needsNewExpiry =
            isReceipt && batchSelection.value === 'new';

        if (newExpiryField) {
            newExpiryField.hidden = !needsNewExpiry;
        }

        if (expiryInput) {
            expiryInput.required = needsNewExpiry;
        }


        /*
         * Button-Beschriftung.
         */
        if (submitButton) {

            submitButton.textContent =
                isReceipt
                    ? 'Einlagern'
                    : isIssue
                        ? 'Ausbuchen'
                        : 'Umbuchen';

        }

    }


    /*
     * Einlagern mit ungewöhnlichem MHD (schon abgelaufen oder mehr als
     * 20 Jahre voraus – so lange halten viele Verbandmittel): meist ein
     * Tippfehler, daher nachfragen. Der
     * Server lehnt solche Daten ohne diese Bestätigung ab, siehe
     * StockActions::assertPlausibleExpiry().
     */
    const form =
        document.getElementById('stock-form');

    const confirmExpiry =
        document.getElementById('confirm_expiry');

    function isoDate(date) {
        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    }

    /*
     * Eingabe als Y-m-d (Datumsfeld) oder d.m.Y (Handeingabe).
     */
    function selectedExpiry() {
        if (batchSelection.value === 'new') {
            const value = expiryInput ? expiryInput.value.trim() : '';
            const german = value.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);

            return german
                ? german[3] + '-' + german[2] + '-' + german[1]
                : value;
        }

        const option = batchSelection.selectedOptions[0];

        return option ? (option.dataset.expiry || '') : '';
    }

    if (form && confirmExpiry) {
        form.addEventListener('submit', function (event) {
            confirmExpiry.value = '0';

            const expiry = selectedExpiry();

            if (fromSelect.value !== 'receipt' || !/^\d{4}-\d{2}-\d{2}$/.test(expiry)) {
                return;
            }

            const today = new Date();
            const inTwentyYears = new Date();
            inTwentyYears.setFullYear(today.getFullYear() + 20);

            const shown = expiry.split('-').reverse().join('.');

            let question = null;

            if (expiry < isoDate(today)) {
                question = 'Das MHD ' + shown + ' ist bereits abgelaufen. Trotzdem einlagern?';
            } else if (expiry > isoDate(inTwentyYears)) {
                question = 'Das MHD ' + shown + ' liegt über 20 Jahre in der Zukunft. Stimmt das Jahr?';
            }

            if (question === null) {
                return;
            }

            if (confirm(question)) {
                confirmExpiry.value = '1';
            } else {
                event.preventDefault();
            }
        });
    }

    fromSelect.addEventListener('change', updateForm);
    toSelect.addEventListener('change', updateForm);
    batchSelection.addEventListener('change', updateForm);


    updateForm();

});
