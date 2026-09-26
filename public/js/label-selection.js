/*
 * Etiketten drucken (?page=labels): "alle 1×" / "keine" je Kategorie
 * setzt die Anzahl aller Artikel dieser Kategorie, die Knöpfe oben
 * (data-label-all) die aller Artikel. Beim Absenden werden
 * leere Felder nicht mitgeschickt, damit die Adresse der Druckansicht
 * nur die gewählten Artikel enthält.
 */

document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.querySelector('.labels-form');

    if (!form) {
        return;
    }

    form.querySelectorAll('button[data-label-category]').forEach(function (button) {

        button.addEventListener('click', function () {

            form.querySelectorAll(
                'input[data-label-category="' + button.dataset.labelCategory + '"]'
            ).forEach(function (input) {
                input.value = button.dataset.labelQuantity;
            });

        });

    });

    form.querySelectorAll('button[data-label-all]').forEach(function (button) {

        button.addEventListener('click', function () {

            form.querySelectorAll('input.labels-quantity').forEach(function (input) {
                input.value = button.dataset.labelAll;
            });

        });

    });

    form.addEventListener('submit', function () {

        form.querySelectorAll('input.labels-quantity').forEach(function (input) {
            input.disabled = input.value.trim() === '' || input.value.trim() === '0';
        });

    });

    /*
     * Zurück zur Seite (z. B. Browser-Zurück aus der Druckansicht): Felder
     * wieder freigeben, sonst wären sie gesperrt.
     */
    window.addEventListener('pageshow', function () {

        form.querySelectorAll('input.labels-quantity').forEach(function (input) {
            input.disabled = false;
        });

    });

});
