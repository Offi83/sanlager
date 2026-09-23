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

    const modeHint =
        document.getElementById('issue-mode');


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
     * Von/Ziel-Auswahl anpassen. Die Anzeige ist bewusst auffällig, weil
     * beim Scannen der Blick auf der Kamera liegt und nicht auf den
     * Auswahlfeldern darunter.
     */
    function updateSubmitButton() {

        const isIssue =
            targetSelect.value === 'issue';

        submitButton.textContent =
            isIssue
                ? 'Ausbuchen'
                : 'Nach ' + targetLabel() + ' umbuchen';

        modeHint.textContent =
            isIssue
                ? 'Ausbuchen aus ' + sourceLabel()
                : 'Umbuchen: ' + sourceLabel() + ' → ' + targetLabel();

        modeHint.classList.toggle('issue-mode-issue', isIssue);
        modeHint.classList.toggle('issue-mode-transfer', !isIssue);

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
