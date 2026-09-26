/*
 * Buchen-Seite: Kamera-Scanner (html5-qrcode) und Buchungs-Logik
 * (Ausbuchen/Umbuchen/Einlagern, Menge, MHD beim Einlagern), Ton und
 * Vibration als Rückmeldung sowie der Alarm bei abgelaufener Ware. Läuft
 * nur, wenn die zugehörigen Elemente auf der Seite vorhanden sind, siehe
 * Guard-Klausel unten.
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

    const issueForm =
        articleNumber ? articleNumber.form : null;

    const soundButton =
        document.getElementById('issue-sound');

    const alarm =
        document.getElementById('issue-alarm');

    const alarmText =
        document.getElementById('issue-alarm-text');

    const alarmWhere =
        document.getElementById('issue-alarm-where');

    const alarmError =
        document.getElementById('issue-alarm-error');

    const alarmKeep =
        document.getElementById('issue-alarm-keep');

    const alarmSortOut =
        document.getElementById('issue-alarm-sort-out');


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
        || !issueForm
        || !soundButton
        || !alarm
        || !alarmText
        || !alarmWhere
        || !alarmError
        || !alarmKeep
        || !alarmSortOut
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
            showError(
                'Ungültige Menge: „' + quantityInput.value
                    + '“. Menge wurde auf 1 gesetzt – bitte noch einmal scannen.'
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


    /*
     * Rückmeldung per Ton und Vibration (D24): Beim Scannen liegt der
     * Blick auf Kamera oder Ware, nicht auf dem Display.
     *
     *   ok      kurzer hoher Ton
     *   warnung zwei mittlere Töne (MHD bald erreicht)
     *   fehler  zwei tiefe Töne
     *   alarm   auf- und abschwellend, abgelaufene Ware
     *
     * Töne per Web Audio (keine Dateien nötig). Browser erlauben Ton erst
     * nach einer Bedienung der Seite – Tippen, Klicken oder eine Taste
     * (auch der Hand-Scanner tippt) schaltet ihn frei. Ton an/aus wird je
     * Gerät gespeichert; vibriert wird immer (nur Android kann das).
     */
    const sounds = {
        ok: { tones: [[1320, 90]], vibrate: [60] },
        warning: { tones: [[660, 120, 60], [660, 120]], vibrate: [120, 80, 120] },
        error: { tones: [[220, 180, 80], [220, 180]], vibrate: [250, 100, 250] },
        alarm: {
            tones: [[880, 220, 30], [587, 220, 30], [880, 220, 30], [587, 220, 30], [880, 220, 30], [587, 400]],
            vibrate: [400, 150, 400, 150, 400]
        }
    };

    let audio = null;

    function audioContext() {

        const AudioContextClass =
            window.AudioContext || window.webkitAudioContext;

        if (!AudioContextClass) {
            return null;
        }

        if (!audio) {
            audio = new AudioContextClass();
        }

        if (audio.state === 'suspended') {
            audio.resume();
        }

        return audio;

    }

    ['pointerdown', 'keydown'].forEach(function (type) {
        document.addEventListener(type, audioContext, { once: true, capture: true });
    });

    function soundEnabled() {

        try {
            return localStorage.getItem('sanlager-sound') !== 'off';
        } catch (error) {
            return true;
        }

    }

    function updateSoundButton() {

        soundButton.setAttribute('aria-pressed', soundEnabled() ? 'true' : 'false');

    }

    soundButton.hidden = false;
    updateSoundButton();

    soundButton.addEventListener('click', function () {

        try {
            localStorage.setItem('sanlager-sound', soundEnabled() ? 'off' : 'on');
        } catch (error) {
            // Nicht speicherbar (z. B. privates Fenster): bleibt an.
        }

        updateSoundButton();
        signal('ok');
        focusArticleNumber();

    });

    function signal(kind) {

        const sound = sounds[kind];

        if (navigator.vibrate) {
            navigator.vibrate(sound.vibrate);
        }

        const context = soundEnabled() ? audioContext() : null;

        if (!context) {
            return;
        }

        let time = context.currentTime + 0.02;

        sound.tones.forEach(function (tone) {

            const [frequency, duration, pause = 0] = tone;

            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.type = 'square';
            oscillator.frequency.value = frequency;

            // Kurz ein- und ausblenden, sonst knackt es.
            gain.gain.setValueAtTime(0, time);
            gain.gain.linearRampToValueAtTime(0.2, time + 0.01);
            gain.gain.setValueAtTime(0.2, time + duration / 1000 - 0.02);
            gain.gain.linearRampToValueAtTime(0, time + duration / 1000);

            oscillator.connect(gain);
            gain.connect(context.destination);

            oscillator.start(time);
            oscillator.stop(time + duration / 1000);

            time += (duration + pause) / 1000;

        });

    }


    function showError(message) {

        showResult(message, true);
        signal('error');

    }


    /*
     * Eine Buchung (Kamera-Scan oder Artikelnummer im Formular) per
     * fetch, ohne Neuladen: Von/Nach, MHD und Scanner bleiben, und Ton
     * und Alarm funktionieren auch mit dem Hand-Scanner. Wirft einen
     * Fehler mit verständlicher Meldung, wenn nicht gebucht wurde.
     */
    async function book(code) {

        const formData = new FormData();

        formData.append('action', 'issue');
        formData.append('ajax', '1');
        formData.append('article_number', code);
        formData.append('source', sourceSelect.value);
        formData.append('target', targetSelect.value);
        formData.append('quantity', quantityInput.value.trim());
        formData.append('expiry_date', expiryInput.value);
        formData.append('confirm_expiry', confirmExpiry.value);

        const data = await post(formData, 'Buchung fehlgeschlagen.');

        /*
         * Aufeinanderfolgende Buchungen desselben Artikels mit demselben
         * Von/Nach zusammenfassen.
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

        // Meldung einer früheren Buchung mit Neuladen (oben auf der Seite) ist überholt.
        document.querySelectorAll('.container > .alert').forEach(function (element) {
            element.remove();
        });

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

        if (data.expired_batches && data.expired_batches.length > 0) {
            showAlarm(data);
        } else {
            signal(data.expired === true ? 'warning' : 'ok');
        }

        return data;

    }


    async function post(formData, fallbackError) {

        const response =
            await fetch(
                window.location.href,
                {
                    method: 'POST',
                    body: formData
                }
            );

        /*
         * Kommt kein JSON zurück (z. B. abgelaufene Anmeldung,
         * Serverfehler), verständlich melden statt "Unexpected token
         * '<' ...".
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
                data.error || fallbackError
            );

        }

        return data;

    }


    /*
     * Artikelnummer per Hand-Scanner oder Tastatur (Enter): ebenfalls
     * ohne Neuladen buchen. Ohne JavaScript schickt das Formular normal
     * ab (Meldung nach Neuladen, ohne Ton und Alarm).
     */
    issueForm.addEventListener('submit', async function (event) {

        event.preventDefault();

        const code = articleNumber.value.trim();

        if (processing || code === '') {
            return;
        }

        processing = true;

        try {

            await book(code);

            articleNumber.value = '';

        } catch (error) {

            showError(error.message);

        }

        processing = false;

        if (!alarmOpen()) {
            focusArticleNumber();
        }

    });


    /*
     * Alarm (D22a): Beim Aus-/Umbuchen wurde eine abgelaufene Charge
     * genommen (bewusst, das älteste MHD zuerst). Der Alarm muss
     * weggetippt werden: „Aussortieren“ macht aus der Buchung eine
     * Entsorgung und entsorgt auch den Rest dieser Charge am Lagerort
     * (Aktion sort_out_expired), „Trotzdem verwenden“ lässt alles so.
     */
    let alarmData = null;

    function alarmOpen() {

        return alarm.open === true;

    }

    function unitText(quantity, data) {

        return quantity + ' ' + (quantity === 1 ? data.unit : (data.unit_plural || data.unit));

    }

    function showAlarm(data) {

        alarmData = data;

        const batches = data.expired_batches;

        const booked = batches.reduce(function (sum, batch) {
            return sum + batch.quantity;
        }, 0);

        const remaining = batches.reduce(function (sum, batch) {
            return sum + batch.remaining;
        }, 0);

        alarmText.textContent =
            data.article_name + ': ' + unitText(booked, data)
            + ' mit MHD ' + batches.map(function (batch) {
                return batch.expiry_date;
            }).join(', ')
            + ' ' + data.action_label + '.';

        alarmWhere.textContent =
            'in ' + data.source_name
            + (remaining > 0
                ? ' – dort liegen davon noch ' + unitText(remaining, data)
                : '');

        alarmError.hidden = true;
        alarmKeep.disabled = false;
        alarmSortOut.disabled = false;

        signal('alarm');

        if (typeof alarm.showModal === 'function') {

            alarm.showModal();
            alarmText.focus();

        } else if (confirm(alarmText.textContent + '\n\nAussortieren?')) {

            sortOut();

        }

    }

    function closeAlarm() {

        if (alarmOpen()) {
            alarm.close();
        }

        alarmData = null;
        focusArticleNumber();

    }

    /*
     * Enter und Leertaste im Alarm nicht als Knopfdruck werten: Ein
     * Hand-Scanner schickt nach jedem Code ein Enter. Escape schließt
     * ihn auch nicht – bewusst tippen.
     */
    alarm.addEventListener('keydown', function (event) {

        if (['Enter', ' ', 'Escape'].includes(event.key)) {
            event.preventDefault();
        }

    });

    alarm.addEventListener('cancel', function (event) {

        event.preventDefault();

    });

    alarmKeep.addEventListener('click', closeAlarm);

    alarmSortOut.addEventListener('click', sortOut);

    async function sortOut() {

        if (!alarmData) {
            return;
        }

        const data = alarmData;
        const formData = new FormData();

        formData.append('action', 'sort_out_expired');
        formData.append('ajax', '1');
        formData.append('article_id', data.article_id);
        formData.append('source', data.source);
        formData.append('target', data.target);

        data.expired_batches.forEach(function (batch) {
            formData.append('batches[' + batch.batch_id + ']', batch.quantity);
        });

        alarmKeep.disabled = true;
        alarmSortOut.disabled = true;

        try {

            const result = await post(formData, 'Aussortieren fehlgeschlagen.');

            // Die Buchung ist zurückgenommen: nicht mehr mitzählen.
            lastResult = null;
            lastResultCount = 0;

            closeAlarm();
            showResult(result.message);
            signal('ok');

        } catch (error) {

            alarmError.textContent = error.message;
            alarmError.hidden = false;
            alarmKeep.disabled = false;
            alarmSortOut.disabled = false;
            signal('error');

        }

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

                    if (processing || alarmOpen()) {
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

                        await book(code);

                        /*
                         * Gleichen QR-Code für 7 Sekunden
                         * nicht erneut buchen.
                         */
                        lastScannedCode = code;

                        ignoreLastScannedUntil =
                            Date.now() + 7000;

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

                        showError(error.message);

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
