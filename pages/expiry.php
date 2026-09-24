<?php

/*
 * Daten für die Seite: MHD-Übersicht.
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$expiringBatches = [];
$expiredCount = 0;
$expiringSoonCount = 0;

/*
 * Vorlaufzeit wie im Wochenbericht (REPORT_EXPIRY_DAYS, Standard 90).
 */
$expiryDays = expiryWarningDays();

$expiringBatches = $reports->getExpiringBatches($expiryDays);

foreach ($expiringBatches as $row) {

    $rowExpiry = expiryInfo($row['expiry_date']);

    if ($rowExpiry['class'] === 'expiry-expired') {
        $expiredCount++;
    } elseif ($rowExpiry['class'] === 'expiry-warning') {
        $expiringSoonCount++;
    }
}
