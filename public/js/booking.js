/*
 * Buchen-Seite: Kamera-Scanner (html5-qrcode) und Buchungs-Logik
 * (Ausbuchen/Umbuchen/Einlagern, Menge, MHD beim Einlagern). Läuft nur, wenn die zugehörigen Elemente auf der
 * Seite vorhanden sind, siehe Guard-Klausel unten.
 */

document.addEventListener('DOMContentLoaded', function () {

    const scanButton =
        document.getElementById('issue-start-scan');

    const scannerElement =
        document.getElementById('issue-scanner');

    const scanStatus =
        document.getElementById('issue-scan-status');

    const scanResult =
        document.getElementById('issue-scan-result');

    const articleNumber =
        document.getElementById('issue-article-number');

    const sourceSelect =
        document.getElementById('issue-source');

    const targetSelect =
        document.getElementById('issue-target');

    const submitButton =
        document.getElementById('issue-submit');

    const modeHint =
        document.getElementById('issue-mode');

    const quantityInput =
        document.getElementById('issue-quantity');

    const quantityMinus =
        document.getElementById('issue-quantity-minus');

    const quantityPlus =
        document.getElementById('issue-quantity-plus');

    const expiryField =
        document.getElementById('issue-expiry-field');

    const expiryInput =
        document.getElementById('issue-expiry');

    const confirmExpiry =
        document.getElementById('issue-confirm-expiry');


    if (
        !scanButton
        || !scannerElement
        || !scanStatus
        || !scanResult
        || !articleNumber
        || !sourceSelect
        || !targetSelect
        || !submitButton
        || !modeHint
        || !quantityInput
        || !quantityMinus
        || !quantityPlus
        || !expiryField
        || !expiryInput
        || !confirmExpiry
    ) {
        return;
    }


    function targetLabel() {

        const option =
            targetSelect.options[targetSelect.selectedIndex];

        return option
            ? option.textContent.trim()
            : 'Ausbuchen';

    }


    function sourceLabel() {

        const option =
            sourceSelect.options[sourceSelect.selectedIndex];

        return option
            ? option.textContent.trim()
            : '';

    }


    /*
     * Button-Text und Modus-Anzeige über dem Kamerabild an die aktuelle
     * Von/Nach-Auswahl anpassen. Die Anzeige ist bewusst auffällig, weil
     * beim Scannen der Blick auf der Kamera liegt und nicht auf den
     * Auswahlfeldern darunter.
     */
    function isReceipt() {

        return sourceSelect.value === 'receipt';

    }


    function updateSubmitButton() {

        const receipt =
            isReceipt();

        const isIssue =
            !receipt && targetSelect.value === 'issue';

        submitButton.textContent =
            // Kurz, damit der Button mit dem MHD-Feld in eine Zeile passt;
            // das Ziel steht in der Anzeige oben.
            receipt
                ? 'Einlagern'
                : isIssue
                    ? 'Ausbuchen'
                    : 'Nach ' + targetLabel() + ' umbuchen';

        modeHint.textContent =
            receipt
                ? 'Einlagern in ' + targetLabel()
                : isIssue
                    ? 'Ausbuchen aus ' + sourceLabel()
                    : 'Umbuchen: ' + sourceLabel() + ' → ' + targetLabel();

        modeHint.classList.toggle('issue-mode-receipt', receipt);
        modeHint.classList.toggle('issue-mode-issue', isIssue);
        modeHint.classList.toggle('issue-mode-transfer', !receipt && !isIssue);

        // MHD nur beim Einlagern.
        expiryField.hidden = !receipt;

    }


    /*
     * Im Nach-Select darf nicht derselbe Lagerort wie im Von-Select
     * stehen (Umbuchen an denselben Ort ergibt keinen Sinn). Beim
     * Einlagern entfällt "Ausbuchen", dann ist der erste Lagerort das
     * Ziel.
     */
    function updateTargetOptions() {

        const sourceValue = sourceSelect.value;
        const receipt = isReceipt();
        let selectionWasHidden = false;

        Array.from(targetSelect.options).forEach(function (option) {

            const hidden =
                option.value === 'issue'
                    ? receipt
                    : option.value === sourceValue;

            option.hidden = hidden;
            option.disabled = hidden;

            if (hidden && option.selected) {
                selectionWasHidden = true;
            }

        });

        if (selectionWasHidden) {

            const firstFree =
                Array.from(targetSelect.options).find(function (option) {
                    return !option.disabled;
                });

            targetSelect.value = firstFree ? firstFree.value : 'issue';

        }

        updateSubmitButton();

    }


    sourceSelect.addEventListener(
        'change',
        updateTargetOptions
    );

    targetSelect.addEventListener(
        'change',
        updateSubmitButton
    );

    updateTargetOptions();


    let scanner = null;
    let scanning = false;
    let processing = false;

    let lastScannedCode = null;
    let ignoreLastScannedUntil = 0;

    let lastResult = null;
    let lastResultCount = 0;


    /*
     * Von/Nach umgestellt (z. B. nach "Kein Bestand an diesem Lagerort"):
     * denselben Code sofort wieder annehmen, statt die Sperre abzuwarten.
     */
    function releaseScanLock() {

        ignoreLastScannedUntil = 0;

    }

    sourceSelect.addEventListener('change', releaseScanLock);
    targetSelect.addEventListener('change', releaseScanLock);


    /*
     * Menge: gilt nur für die nächste Buchung, danach wieder 1. Mit
     * − / + bleibt der Cursor im Feld Artikelnummer, damit ein
     * Hand-Scanner dort weiterschreibt; nach Eintippen springt Enter
     * dorthin zurück (statt schon ohne Artikel abzuschicken).
     */
    function quantity() {

        return /^\d{1,3}$/.test(quantityInput.value.trim())
            ? parseInt(quantityInput.value.trim(), 10)
            : 0;

    }

    function setQuantity(value) {

        quantityInput.value =
            String(Math.min(999, Math.max(1, value)));

        // Menge geändert: denselben Code gleich wieder annehmen.
        releaseScanLock();

    }

    quantityMinus.addEventListener('click', function () {

        setQuantity((quantity() || 1) - 1);
        focusArticleNumber();

    });

    quantityPlus.addEventListener('click', function () {

        setQuantity(quantity() + 1);
        focusArticleNumber();

    });

    quantityInput.addEventListener('input', releaseScanLock);

    quantityInput.addEventListener('keydown', function (event) {

        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        if (quantity() < 1) {

            /*
             * Meist ein Scan, der im Mengenfeld gelandet ist.
             */
            showResult(
                'Ungültige Menge: „' + quantityInput.value
                    + '“. Menge wurde auf 1 gesetzt – bitte noch einmal scannen.',
                true
            );

            setQuantity(1);

        }

        focusArticleNumber();

    });


    /*
     * Einlagern mit ungewöhnlichem MHD (schon abgelaufen oder mehr als
     * 20 Jahre voraus): gleich beim Eintragen nachfragen, nicht erst beim
     * Scannen. Die Bestätigung gilt, bis das MHD geändert wird; der
     * Server lehnt solche Daten ohne sie ab (confirm_expiry, siehe
     * StockActions::assertPlausibleExpiry()).
     */
    function isoDate(date) {

        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');

    }

    expiryInput.addEventListener('change', function () {

        confirmExpiry.value = '0';
        releaseScanLock();

        const expiry = expiryInput.value;

        if (!/^\d{4}-\d{2}-\d{2}$/.test(expiry)) {
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
            expiryInput.value = '';
            expiryInput.focus();
        }

    });


    function focusArticleNumber() {

        setTimeout(function () {

            articleNumber.focus();
            articleNumber.select();

        }, 50);

    }


    function showStatus(message) {

        scanStatus.textContent = message;
        scanStatus.hidden = false;

    }


    function showResult(message, error = false) {

        scanResult.textContent = message;
        scanResult.hidden = false;

        scanResult.classList.toggle(
            'error',
            error
        );

    }


    async function stopScanner() {

        if (!scanner) {
            return;
        }

        try {

            if (scanning) {
                await scanner.stop();
            }

            scanner.clear();

        } catch (error) {

            console.warn(
                'Scanner konnte nicht gestoppt werden:',
                error
            );

        }

        scanner = null;
        scanning = false;

        scannerElement.hidden = true;

        scanButton.textContent =
            'Scanner starten';

        focusArticleNumber();

    }


    async function startScanner() {

        if (typeof Html5Qrcode === 'undefined') {

            showStatus(
                'Scanner-Bibliothek konnte nicht geladen werden.'
            );

            return;
        }


        scannerElement.hidden = false;

        scanButton.textContent =
            'Scanner beenden';

        showStatus(
            'Kamera wird gestartet …'
        );


        scanner = new Html5Qrcode(
            'issue-scanner'
        );


        try {

            await scanner.start(
                {
                    facingMode: 'environment'
                },
                {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 250
                    }
                },
                async function (decodedText) {

                    if (processing) {
                        return;
                    }


                    const code =
                        decodedText.trim();


                    if (
                        code === lastScannedCode
                        && Date.now() < ignoreLastScannedUntil
                    ) {
                        return;
                    }


                    processing = true;

                    showStatus(
                        'Buchung läuft …'
                    );


                    try {

                        const formData =
                            new FormData();

                        formData.append(
                            'action',
                            'issue'
                        );

                        formData.append(
                            'ajax',
                            '1'
                        );

                        formData.append(
                            'article_number',
                            code
                        );

                        formData.append(
                            'source',
                            sourceSelect.value
                        );

                        formData.append(
                            'target',
                            targetSelect.value
                        );

                        formData.append(
                            'quantity',
                            quantityInput.value.trim()
                        );

                        formData.append(
                            'expiry_date',
                            expiryInput.value
                        );

                        formData.append(
                            'confirm_expiry',
                            confirmExpiry.value
                        );


                        const response =
                            await fetch(
                                window.location.href,
                                {
                                    method: 'POST',
                                    body: formData
                                }
                            );


                        /*
                         * Kommt kein JSON zurück (z. B. abgelaufene
                         * Anmeldung, Serverfehler), verständlich melden
                         * statt "Unexpected token '<' ...".
                         */
                        const data =
                            await response.json().catch(function () {
                                return {
                                    success: false,
                                    error: 'Unerwartete Antwort vom Server ('
                                        + response.status
                                        + '). Bitte Seite neu laden.'
                                };
                            });


                        if (!data.success) {

                            throw new Error(
                                data.error
                                    || 'Buchung fehlgeschlagen.'
                            );

                        }


                        /*
                         * Gleichen QR-Code für 7 Sekunden
                         * nicht erneut buchen.
                         */
                        lastScannedCode = code;

                        ignoreLastScannedUntil =
                            Date.now() + 7000;


                        /*
                         * Aufeinanderfolgende Buchungen
                         * desselben Artikels mit demselben Von/Nach
                         * zusammenfassen.
                         */
                        const resultKey =
                            code + '|' + sourceSelect.value + '|' + targetSelect.value
                            + '|' + (isReceipt() ? expiryInput.value : '');

                        if (lastResult === resultKey) {

                            lastResultCount += data.quantity;

                        } else {

                            lastResult = resultKey;
                            lastResultCount = data.quantity;

                        }

                        // Menge gilt nur für diese eine Buchung.
                        quantityInput.value = '1';


                        showResult(
                            data.article_name
                            + ' – '
                            + lastResultCount
                            + ' '
                            // Einzahl nur bei genau 1 ("1 Rolle", "2 Rollen").
                            + (lastResultCount === 1 ? data.unit : (data.unit_plural || data.unit))
                            + ' '
                            + data.action_label
                            // Artikel ohne MHD: keine Angabe.
                            + (data.expiry_date ? ' – MHD ' + data.expiry_date : ''),
                            data.expired === true
                        );


                        showStatus(
                            'Bereit für den nächsten Scan.'
                        );


                    } catch (error) {

                        /*
                         * Auch nach einem Fehler denselben Code kurz
                         * sperren: Er liegt meist noch vor der Kamera,
                         * sonst ginge nach jeder Antwort sofort die
                         * nächste (gleich scheiternde) Anfrage raus.
                         */
                        lastScannedCode = code;

                        ignoreLastScannedUntil =
                            Date.now() + 3000;

                        showResult(
                            error.message,
                            true
                        );

                        showStatus(
                            'Fehler – nächster Scan möglich.'
                        );

                    }


                    processing = false;

                },
                function () {
                    // Kein QR-Code erkannt.
                }
            );


            scanning = true;

            showStatus(
                'QR-Code vor die Kamera halten.'
            );


        } catch (error) {

            console.error(
                'Scanner konnte nicht gestartet werden:',
                error
            );

            await stopScanner();

            showStatus(
                'Kamera konnte nicht gestartet werden.'
            );

        }

    }


    scanButton.addEventListener(
        'click',
        async function () {

            if (scanning) {

                await stopScanner();

                showStatus(
                    'Scanner beendet.'
                );

                return;
            }

            await startScanner();

        }
    );


    /*
     * "Scanner starten" nur anzeigen, wenn dieses Gerät eine Kamera hat
     * (im HTML standardmäßig ausgeblendet, damit er beim Laden nicht kurz
     * aufblitzt). Ohne Kamera – z. B. am Pi-Terminal mit Hand-Scanner –
     * bleibt mehr Platz für das Formular.
     *
     * Kamerazugriff gibt es nur auf sicheren Seiten (HTTPS/localhost).
     * Vor der ersten Kamerafreigabe liefern Browser keine Gerätenamen,
     * verraten aber, ob überhaupt eine Kamera ("videoinput") existiert –
     * das reicht hier. iOS/iPadOS meldet das vor der Freigabe nicht
     * zuverlässig; dort hat praktisch jedes Gerät eine Kamera, daher
     * wird der Button dort immer angezeigt.
     */
    const isAppleMobile =
        /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);


    async function hasCamera() {

        if (
            !window.isSecureContext
            || !navigator.mediaDevices
            || !navigator.mediaDevices.enumerateDevices
        ) {
            return false;
        }

        if (isAppleMobile) {
            return true;
        }

        try {

            const devices =
                await navigator.mediaDevices.enumerateDevices();

            return devices.some(function (device) {
                return device.kind === 'videoinput';
            });

        } catch (error) {

            return false;

        }

    }


    async function updateScanButtonVisibility() {

        /*
         * Während eines laufenden Scans nie ausblenden, sonst ließe sich
         * der Scanner nicht mehr beenden.
         */
        if (scanning) {
            return;
        }

        scanButton.hidden = !(await hasCamera());

    }


    updateScanButtonVisibility();

    /*
     * USB-Kamera ein- oder ausgesteckt: Button ohne Neuladen anpassen.
     */
    if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {

        navigator.mediaDevices.addEventListener(
            'devicechange',
            updateScanButtonVisibility
        );

    }


    /*
     * Beim Öffnen der Seite ist das Feld sofort aktiv.
     * Dadurch kann ein Hardware-Barcode-Scanner direkt
     * scannen und mit Enter absenden.
     */
    focusArticleNumber();

});
