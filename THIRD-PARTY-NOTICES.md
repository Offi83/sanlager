# Fremdbestandteile

SanLager selbst steht unter der **GNU General Public License v3.0** (GPL-3.0-only), siehe [LICENSE](LICENSE).

Folgende Bestandteile stammen von Dritten und stehen unter eigenen, mit der GPL-3.0 vereinbaren Lizenzen.

## Im Repository mitgeliefert

| Bestandteil | Verwendung | Lizenz | Lizenztext |
|---|---|---|---|
| [html5-qrcode](https://github.com/mebjas/html5-qrcode) – Copyright 2020 Minhaz | Kamera-Scanner auf der Buchen-Seite (`public/js/vendor/html5-qrcode.min.js`) | Apache-2.0 | [licenses/html5-qrcode-Apache-2.0.txt](licenses/html5-qrcode-Apache-2.0.txt) |
| [Lucide](https://lucide.dev) – Symbole „undo-2“, „trash-2“ (letzteres ursprünglich aus [Feather](https://feathericons.com), Cole Bemis), „volume-2“ und „volume-x“ | Rückgängig- und Entsorgen-Buttons, Ton an/aus beim Buchen (`icon()` in `src/helpers.php`) | ISC bzw. MIT | [licenses/lucide-ISC.txt](licenses/lucide-ISC.txt) |
| `public/images/pflaster.svg` | Logo in der Kopfzeile | gemeinfrei | – |

## Über Composer bezogen

Diese Bibliotheken liegen nicht im Repository, sondern werden per `composer install` geladen; ihre Lizenzdateien befinden sich dann jeweils unter `vendor/<paket>/`. Eine aktuelle Liste liefert:

```bash
composer licenses --no-dev
```

| Paket | Zweck | Lizenz |
|---|---|---|
| vlucas/phpdotenv (inkl. graham-campbell/result-type, phpoption/phpoption) | `.env`-Konfiguration | BSD-3-Clause, MIT, Apache-2.0 |
| endroid/qr-code (inkl. bacon/bacon-qr-code, dasprid/enum) | QR-Codes für Artikel und Etiketten | MIT, BSD-2-Clause |
| symfony/mailer, symfony/mime u. a. Symfony-Komponenten | Versand des Wochenberichts | MIT |
| egulias/email-validator, doctrine/lexer | Prüfung von E-Mail-Adressen | MIT |
| psr/* | Schnittstellen-Standards | MIT |

Nur für die Entwicklung (nicht auf dem Produktivserver): phpunit/phpunit (BSD-3-Clause) und Abhängigkeiten.
