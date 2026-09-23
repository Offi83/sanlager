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
 * beim Einlagern zählt der Gesamtbestand (data-quantity).
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

            if (!option.dataset.label || isReceipt) {
                return false;
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
         * Eingabefeld für neues MHD.
         */
        const needsNewExpiry =
            isReceipt && batchSelection.value === 'new';

        newExpiryField.hidden = !needsNewExpiry;

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


    fromSelect.addEventListener('change', updateForm);
    toSelect.addEventListener('change', updateForm);
    batchSelection.addEventListener('change', updateForm);


    updateForm();

});
