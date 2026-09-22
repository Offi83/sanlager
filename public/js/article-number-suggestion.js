/*
 * "Artikel anlegen"-Formular: schlägt beim Tippen automatisch eine
 * Artikelnummer aus Kategorie-Kürzel und Artikelname vor, solange der
 * Benutzer den Vorschlag nicht selbst überschrieben hat.
 *
 * Gilt ausschließlich für create_article, nicht für update_article
 * (Bearbeiten-Formular wird hier bewusst nicht angesprochen).
 */

document.addEventListener('DOMContentLoaded', function () {

    const actionInput =
        document.querySelector(
            'input[name="action"][value="create_article"]'
        );

    if (!actionInput) {
        return;
    }


    const form =
        actionInput.closest('form');

    if (!form) {
        return;
    }


    const nameInput =
        form.querySelector(
            'input[name="name"]'
        );

    const articleNumberInput =
        form.querySelector(
            'input[name="article_number"]'
        );

    const categorySelect =
        form.querySelector(
            'select[name="category_id"]'
        );


    if (
        !nameInput
        || !articleNumberInput
        || !categorySelect
    ) {
        return;
    }


    /*
     * Hier merken wir uns den zuletzt automatisch
     * erzeugten Wert.
     *
     * Solange der Benutzer diesen Wert nicht verändert,
     * darf die Anwendung ihn aktualisieren.
     */
    let generatedValue = '';


    function slugify(value) {

        return value
            .trim()
            .toLowerCase()

            /*
             * Deutsche Umlaute vor normalize() umwandeln,
             * damit daraus ae/oe/ue statt nur a/o/u wird.
             */
            .replace(/ä/g, 'ae')
            .replace(/ö/g, 'oe')
            .replace(/ü/g, 'ue')
            .replace(/ß/g, 'ss')

            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')

            /*
             * Alles außer Buchstaben und Zahlen
             * wird zu einem Bindestrich.
             */
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

    }


    function updateArticleNumberSuggestion() {

        /*
         * Wenn der Benutzer die automatisch erzeugte
         * Artikelnummer selbst geändert hat, nichts
         * mehr automatisch überschreiben.
         */
        if (
            articleNumberInput.value !== ''
            && articleNumberInput.value !== generatedValue
        ) {
            return;
        }


        const selectedOption =
            categorySelect.options[
                categorySelect.selectedIndex
            ];


        const shortName =
            selectedOption
                ? selectedOption.dataset.shortName || ''
                : '';


        const name =
            nameInput.value.trim();


        const categoryPart =
            slugify(shortName);


        const namePart =
            slugify(name);


        let suggestion = '';


        if (categoryPart && namePart) {

            suggestion =
                categoryPart
                + '-'
                + namePart;

        } else if (categoryPart) {

            suggestion = categoryPart;

        } else {

            suggestion = namePart;

        }


        generatedValue =
            suggestion;


        articleNumberInput.value =
            suggestion;

    }


    /*
     * Artikelname geändert:
     * Artikelnummer-Vorschlag aktualisieren.
     */
    nameInput.addEventListener(
        'input',
        updateArticleNumberSuggestion
    );


    /*
     * Kategorie geändert:
     * Artikelnummer-Vorschlag aktualisieren.
     */
    categorySelect.addEventListener(
        'change',
        updateArticleNumberSuggestion
    );


    /*
     * Initialen Vorschlag erzeugen.
     */
    updateArticleNumberSuggestion();

});
