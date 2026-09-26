/*
 * Inventur (?page=inventory): Die Zeilen „weiteres MHD gefunden“ sind
 * zunächst ausgeblendet und erscheinen per „+ MHD“ beim jeweiligen
 * Artikel – so bleibt die Liste auf dem kleinen Display kurz. Ohne
 * JavaScript sind sie einfach alle sichtbar.
 *
 * Außerdem werden Felder hervorgehoben, deren Zählung vom erwarteten
 * Bestand abweicht (nur diese werden beim Speichern gebucht).
 */

document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.inventory-found-optional').forEach(function (row) {
        row.hidden = true;
    });

    document.querySelectorAll('.inventory-add').forEach(function (button) {

        const row = document.getElementById(button.dataset.found);

        if (!row) {
            return;
        }

        button.hidden = false;

        button.addEventListener('click', function () {
            row.hidden = false;
            button.hidden = true;
            row.querySelector('input').focus();
        });

    });

    document.querySelectorAll('.inventory-count[data-expected]').forEach(function (input) {

        const mark = function () {
            input.classList.toggle(
                'inventory-changed',
                input.value.trim() !== input.dataset.expected
            );
        };

        input.addEventListener('input', mark);
        mark();

    });

});
