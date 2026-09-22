/*
 * Artikeldetailseite: "Bestand buchen"-Formular. Blendet je nach
 * Vorgang (Einlagern/Entnehmen) und MHD-Auswahl die passenden Felder
 * ein/aus und passt die Button-Beschriftung an.
 */

document.addEventListener('DOMContentLoaded', function () {

    const movementType =
        document.getElementById('movement_type');

    const batchSelection =
        document.getElementById('batch_selection');

    const newExpiryField =
        document.getElementById('new-expiry-field');

    const expiryInput =
        document.getElementById('expiry_date');

    const submitButton =
        document.getElementById('submit-stock');


    if (
        !movementType
        || !batchSelection
    ) {
        return;
    }


    function updateForm() {

        const movement =
            movementType.value;

        let selection =
            batchSelection.value;


        /*
         * "Neues MHD" nur beim Einlagern erlauben.
         */
        const newOption =
            batchSelection.querySelector(
                'option[value="new"]'
            );

        if (newOption) {

            const isIssue =
                movement === 'issue';

            newOption.disabled = isIssue;
            newOption.hidden = isIssue;

            if (
                isIssue
                && selection === 'new'
            ) {

                batchSelection.value = 'none';

                selection = 'none';
            }

        }


        /*
         * Bei Entnahme nur MHDs
         * mit positivem Bestand anzeigen.
         */
        Array.from(
            batchSelection.options
        ).forEach(function (option) {

            if (
                option.value === ''
                || option.value === 'none'
                || option.value === 'new'
            ) {
                return;
            }

            const quantity =
                parseInt(
                    option.dataset.quantity || '0',
                    10
                );

            option.hidden =
                movement === 'issue'
                && quantity <= 0;
        });


        /*
         * Eingabefeld für neues MHD.
         */
        newExpiryField.hidden =
            !(
                movement === 'receipt'
                && selection === 'new'
            );


        if (expiryInput) {

            expiryInput.required =
                movement === 'receipt'
                && selection === 'new';
        }


        /*
         * Button-Beschriftung.
         */
        if (submitButton) {

            submitButton.textContent =
                movement === 'receipt'
                    ? 'Einlagern'
                    : 'Entnehmen';
        }

    }


    movementType.addEventListener(
        'change',
        updateForm
    );

    batchSelection.addEventListener(
        'change',
        updateForm
    );


    updateForm();

});
