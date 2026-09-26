# 🗄️ Datenbank

Die SanLager-App verwendet **SQLite** als lokale Datenbank.

Die Datenbank liegt standardmäßig unter:

```text
database/database.sqlite
```

Ein anderer Ort lässt sich in der `.env` über `DB_DATABASE` einstellen (relativ zum Projektordner oder absolut).

Die SQLite-Datei enthält die Artikeldaten, Kategorien, Lagerorte, Chargen und Lagerbewegungen der Anwendung.

> **Hinweis:** Die produktive Datenbank wird nicht über Git versioniert. Dadurch bleiben die Daten auf dem jeweiligen System erhalten, wenn der Anwendungscode aktualisiert wird.

## Tabellen

### `articles`

Enthält die Stammdaten der Lagerartikel.

| Feld             | Beschreibung               |
| ---------------- | -------------------------- |
| `id`             | Eindeutige ID des Artikels |
| `article_number` | Artikelnummer (eindeutig)  |
| `name`           | Bezeichnung des Artikels   |
| `description`    | Beschreibung                |
| `unit_id`        | Einheit, siehe `units`     |
| `category_id`    | Zugehörige Kategorie       |
| `has_expiry`     | 1 = Artikel hat ein MHD, 0 = nicht (z. B. Mullbinden): Beim Buchen entfällt dann die MHD-Auswahl |
| `active`         | Status des Artikels (Soft-Delete beim Löschen) |
| `created_at`     | Erstellungszeitpunkt       |

### `units`

Einheiten der Artikel, gepflegt unter Verwaltung → Einheiten. Artikel wählen ihre Einheit aus dieser Liste (kein Freitext), damit nicht „Packung“, „Packungen“ und „Pckg.“ nebeneinander entstehen.

| Feld         | Beschreibung                                         |
| ------------ | ---------------------------------------------------- |
| `id`         | Eindeutige ID der Einheit                            |
| `name`       | Einzahl, z. B. „Rolle“ (eindeutig)                   |
| `plural`     | Mehrzahl, z. B. „Rollen“ – angezeigt bei Mengen ≠ 1  |
| `sort_order` | Reihenfolge in der Auswahl                           |

Löschen geht nur, solange kein aktiver Artikel die Einheit verwendet. Migration 011 hat die bis dahin frei eingetippten Einheiten übernommen.

### `article_categories`

Enthält die Kategorien für die Artikel.

| Feld         | Beschreibung                 |
| ------------ | ---------------------------- |
| `id`         | Eindeutige ID der Kategorie  |
| `name`       | Name der Kategorie (eindeutig) |
| `short_name` | Kürzel der Kategorie         |
| `color`      | Farbe der Kategorie          |
| `sort_order` | Reihenfolge in der Anwendung, per Drag & Drop änderbar |
| `active`     | Status der Kategorie         |

### `storage_locations`

Enthält die Lagerorte (z. B. Hauptlager, Fahrzeuge, Außenlager).

| Feld          | Beschreibung                 |
| ------------- | ----------------------------- |
| `id`          | Eindeutige ID des Lagerorts  |
| `name`        | Name des Lagerorts (eindeutig) |
| `description` | Beschreibung, optional       |
| `sort_order`  | Reihenfolge in der Anwendung, per Drag & Drop änderbar |
| `active`      | Status des Lagerorts (Soft-Delete beim Deaktivieren) |

Der **erste Lagerort in der festgelegten Reihenfolge** (`sort_order`, per Drag & Drop auf der Lagerorte-Seite) ist der Standard-Lagerort: Er ist auf der Buchen-Seite als „Von“ (beim Einlagern als „Nach“) und auf der Artikelseite als Ziel beim Einlagern vorausgewählt, jeweils frei umstellbar. Der Name spielt dafür keine Rolle – ein Umbenennen ändert nichts. Bei einer neuen Datenbank legt Migration 005 dafür den Lagerort `Hauptlager` an. Es muss stets mindestens ein aktiver Lagerort vorhanden sein.

### `article_location_minimums`

Enthält die Mindestbestände eines Artikels, jeweils bezogen auf einen einzelnen Lagerort (z. B. die Soll-Ausstattung eines Sanitätsrucksacks oder die Nachbestückungs-Reserve im Hauptlager).

| Feld            | Beschreibung                                              |
| --------------- | ----------------------------------------------------------- |
| `id`            | Eindeutige ID des Eintrags                                 |
| `article_id`    | Betroffener Artikel                                        |
| `location_id`   | Betroffener Lagerort                                       |
| `minimum_stock` | Mindestbestand für diesen Artikel an diesem Lagerort       |

Nicht jeder Artikel/Lagerort-Kombination muss ein Mindestbestand hinterlegt sein: Nur explizit gepflegte Kombinationen (je Kombination höchstens ein Eintrag, siehe `UNIQUE(article_id, location_id)`) werden überwacht und lösen bei Unterschreitung eine Warnung aus. Ein leeres Feld oder 0 bedeutet „nicht überwacht“; gespeichert werden nur Werte ab 1.

### `batches`

Enthält die Chargen (Mindesthaltbarkeitsdaten, MHD) je Artikel.

| Feld           | Beschreibung                                |
| -------------- | -------------------------------------------- |
| `id`           | Eindeutige ID der Charge                     |
| `article_id`   | Zugehöriger Artikel                          |
| `batch_number` | Chargennummer, optional (aktuell ungenutzt) |
| `expiry_date`  | Mindesthaltbarkeitsdatum, optional           |
| `created_at`   | Erstellungszeitpunkt                         |

Bestand **ohne MHD** hat keine Charge: Die Bewegungen tragen dann `batch_id = NULL` (siehe `BatchRepository::findOrCreate()`). Je Artikel und MHD gibt es höchstens eine Charge; aufgebrauchte Chargen bleiben erhalten, erscheinen aber nicht mehr in der MHD-Auswahl.

### `stock_movements`

Die Bestandsverwaltung basiert ausschließlich auf dieser Bewegungs-Tabelle. Der aktuelle Bestand eines Artikels an einem Lagerort ergibt sich stets aus der Summe seiner Bewegungen – es gibt keine separate Bestandstabelle.

| Feld            | Beschreibung                                  |
| --------------- | ---------------------------------------------- |
| `id`            | Eindeutige ID der Bewegung                     |
| `article_id`    | Betroffener Artikel                            |
| `batch_id`      | Betroffene Charge (MHD), optional              |
| `location_id`   | Betroffener Lagerort                           |
| `quantity`      | Menge, positiv (Zugang) oder negativ (Abgang) |
| `movement_type` | Art der Bewegung, siehe unten                  |
| `note`          | Freitext-Notiz, optional                       |
| `transfer_id`   | Verbindet Abgang und Zugang einer Umbuchung (sonst leer) |
| `created_at`    | Zeitpunkt der Bewegung (UTC; „heute“ rechnet SanLager in `APP_TIMEZONE` um) |

Mögliche Werte für `movement_type`:

| Wert           | Bedeutung                                                   |
| -------------- | ------------------------------------------------------------ |
| `receipt`      | Einlagerung (Zugang)                                         |
| `issue`        | Ausbuchung/Entnahme (Abgang) – zählt in den Ausbuchungen auf „Heute“   |
| `issue_reversal` | Rücknahme einer Ausbuchung (Zugang) – über „Rückgängig“ auf „Heute“, wird dort und im Wochenbericht abgezogen |
| `disposal`     | Entsorgung einer abgelaufenen Charge (Abgang) – zählt **nicht** als Ausbuchung/Verbrauch |
| `disposal_reversal` | Rücknahme einer Entsorgung (Zugang) |
| `correction`   | Bestandskorrektur – derzeit von keiner Funktion erzeugt, vorgesehen für eine Inventur |
| `transfer_out` | Abgang durch Umbuchung an einen anderen Lagerort              |
| `transfer_in`  | Zugang durch Umbuchung von einem anderen Lagerort              |
| `transfer_reversal_out` / `transfer_reversal_in` | Rücknahme einer Umbuchung: Abgang am ursprünglichen Ziel, Zugang an der ursprünglichen Quelle |

Eine Umbuchung („Nach“ ist ein Lagerort – auf der Buchen- oder der Artikelseite) erzeugt immer **zwei** zusammengehörige Bewegungen (`transfer_out` am Quell- und `transfer_in` am Ziel-Lagerort, mit derselben `batch_id`), damit das MHD beim Zielort erhalten bleibt. Umbuchungen zählen bewusst nicht als Ausbuchung, da kein Material verbraucht wird.

Buchungen werden nie gelöscht. Versehentliche Ausbuchungen, Umbuchungen und Entsorgungen werden über „Rückgängig“ auf der Seite „Heute“ durch eine **Gegenbuchung** (`issue_reversal`, `transfer_reversal_out`/`_in`, `disposal_reversal`, jeweils gleiche Charge) ausgeglichen; das ist nur für Buchungen des aktuellen Tages und höchstens bis zur heute gebuchten Menge möglich. Eine Umbuchung lässt sich nur zurücknehmen, solange das Material noch am Ziel liegt.

Die beiden Hälften einer Umbuchung (`transfer_out` + `transfer_in` bzw. die Rücknahme-Varianten) tragen dieselbe `transfer_id` und werden gemeinsam in einer Transaktion gespeichert (`StockRepository::transferPair()`); darüber ordnet `getTodayTransfers()` sie einander zu. Umbuchungen aus der Zeit vor Migration 009 hat die Migration anhand der damaligen Regel (Zugang = ID des Abgangs + 1) nachträglich verknüpft. Abgelaufene Chargen lassen sich in MHD-Übersicht, Artikel- und Lagerort-Detailseite mit „Entsorgen“ vollständig entnehmen (`disposal`).

### `schema_migrations`

Verwaltungstabelle der Migrationen (siehe [Änderungen an der Datenbank](#änderungen-an-der-datenbank)).

| Feld         | Beschreibung                                         |
| ------------ | ---------------------------------------------------- |
| `migration`  | Dateiname der Migration, z. B. `011_units.sql`       |
| `applied_at` | Zeitpunkt der Ausführung (UTC)                       |

## Datenbank lokal prüfen

Die Datenbank kann mit dem SQLite-Kommandozeilenprogramm geöffnet werden:

```bash
sqlite3 database/database.sqlite
```

Alle Tabellen anzeigen:

```sql
.tables
```

Die Struktur einer Tabelle anzeigen:

```sql
.schema articles
```

Datenbank verlassen:

```sql
.quit
```

## Änderungen an der Datenbank

Änderungen an der Datenbankstruktur werden über Migrationen im Verzeichnis

```text
database/migrations/
```

durchgeführt. Jede Migration ist eine eigene, fortlaufend nummerierte SQL-Datei (z. B. `007_location_sort_order.sql`).

Beim Start prüft SanLager automatisch (`src/Database.php`), welche Migrationen bereits angewendet wurden (Tabelle `schema_migrations`), und führt nur die noch fehlenden aus. Ein manueller Migrationsbefehl ist nicht nötig; `script/pull.sh` ruft nach einem Update `bin/migrate.php` auf, damit Fehler sofort sichtbar werden. Prüfen und Ausführen einer Migration laufen unter einer Schreibsperre – öffnen zwei Geräte die Seite gleichzeitig, wird jede Migration trotzdem nur einmal ausgeführt.

Damit das nicht bei jedem Seitenaufruf Zeit kostet, merkt sich SanLager die Nummer der neuesten angewendeten Migration in der Datenbank selbst (`PRAGMA user_version`). Entspricht sie der höchsten Nummer unter `database/migrations/`, wird der Abgleich übersprungen. Jede Migrationsdatei braucht deshalb eine **eindeutige Nummer am Anfang des Dateinamens**; neue Migrationen erhalten immer eine höhere Nummer als alle bestehenden.

Die produktive SQLite-Datei sollte dabei **nicht gelöscht oder durch eine Version aus Git ersetzt werden**.
