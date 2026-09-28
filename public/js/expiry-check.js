/*
 * Rückfrage zu einem ungewöhnlichen MHD beim Einlagern – gemeinsam für die
 * Buchen-Seite (booking.js) und „Bestand buchen“ auf der Artikelseite
 * (stock-form.js). Dieselben Grenzen prüft der Server, siehe
 * StockActions::assertPlausibleExpiry(): Ein MHD in der Vergangenheit oder
 * mehr als 20 Jahre voraus (viele Verbandmittel halten so lange) kann
 * stimmen, muss aber bestätigt werden (confirm_expiry=1).
 */

/**
 * @param {string} expiry MHD als JJJJ-MM-TT
 * @returns {string|null} Rückfrage, oder null wenn das MHD unauffällig ist
 */
function expiryQuestion(expiry) {

    if (!/^\d{4}-\d{2}-\d{2}$/.test(expiry)) {
        return null;
    }

    const isoDate = function (date) {
        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    };

    const today = new Date();
    const inTwentyYears = new Date();
    inTwentyYears.setFullYear(today.getFullYear() + 20);

    const shown = expiry.split('-').reverse().join('.');

    if (expiry < isoDate(today)) {
        return 'Das MHD ' + shown + ' ist bereits abgelaufen. Trotzdem einlagern?';
    }

    if (expiry > isoDate(inTwentyYears)) {
        return 'Das MHD ' + shown + ' liegt über 20 Jahre in der Zukunft. Stimmt das Jahr?';
    }

    return null;

}
