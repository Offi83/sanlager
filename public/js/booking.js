/*
 * Buchen-Seite: Kamera-Scanner (html5-qrcode) und Buchungs-Logik
 * (Ausbuchen/Umbuchen). Läuft nur, wenn die zugehörigen Elemente auf der
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


    if (
        !scanButton
        || !scannerElement
        || !scanStatus
        || !scanResult
        || !articleNumber
        || !sourceSelect
        || !targetSelect
        || !submitButton
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


    function updateSubmitButton() {

        submitButton.textContent =
            targetSelect.value === 'issue'
                ? 'Ausbuchen'
                : 'Nach ' + targetLabel() + ' umbuchen';

    }


    /*
     * Im Ziel-Select darf nicht derselbe Lagerort wie im Von-Select
     * stehen (Umbuchen an denselben Ort ergibt keinen Sinn). Die
     * "Ausbuchen"-Option bleibt davon unberührt.
     */
    function updateTargetOptions() {

        const sourceValue = sourceSelect.value;
        let selectionWasHidden = false;

        Array.from(targetSelect.options).forEach(function (option) {

            if (option.value === 'issue') {
                return;
            }

            const isSameAsSource =
                option.value === sourceValue;

            option.hidden = isSameAsSource;
            option.disabled = isSameAsSource;

            if (isSameAsSource && option.selected) {
                selectionWasHidden = true;
            }

        });

        if (selectionWasHidden) {
            targetSelect.value = 'issue';
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


                        const response =
                            await fetch(
                                window.location.href,
                                {
                                    method: 'POST',
                                    body: formData
                                }
                            );


                        const data =
                            await response.json();


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
                         * desselben Artikels mit demselben Ziel
                         * zusammenfassen.
                         */
                        const resultKey =
                            code + '|' + sourceSelect.value + '|' + targetSelect.value;

                        if (lastResult === resultKey) {

                            lastResultCount++;

                        } else {

                            lastResult = resultKey;
                            lastResultCount = 1;

                        }


                        showResult(
                            data.article_name
                            + ' – '
                            + lastResultCount
                            + ' '
                            + data.unit
                            + ' '
                            + data.action_label
                            + ' – MHD '
                            + data.expiry_date,
                            data.expired === true
                        );


                        showStatus(
                            'Bereit für den nächsten Scan.'
                        );


                    } catch (error) {

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
     * Beim Öffnen der Seite ist das Feld sofort aktiv.
     * Dadurch kann ein Hardware-Barcode-Scanner direkt
     * scannen und mit Enter absenden.
     */
    focusArticleNumber();

});
