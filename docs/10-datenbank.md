# 🗄️ Datenbank

Die SanLager-App verwendet **SQLite** als lokale Datenbank.

Die Datenbank liegt unter:

```text
database/database.sqlite
```

Die SQLite-Datei enthält die Artikeldaten, Kategorien, Bestände und Lagerbewegungen der Anwendung.

> **Hinweis:** Die produktive Datenbank wird nicht über Git versioniert. Dadurch bleiben die Daten auf dem jeweiligen System erhalten, wenn der Anwendungscode aktualisiert wird.

## Tabellen

### `articles`

Enthält die Stammdaten der Lagerartikel.

| Feld             | Beschreibung               |
| ---------------- | -------------------------- |
| `id`             | Eindeutige ID des Artikels |
| `article_number` | Artikelnummer              |
| `name`           | Bezeichnung des Artikels   |
| `description`    | Beschreibung               |
| `unit`           | Einheit, z. B. Stück       |
| `minimum_stock`  | Mindestbestand             |
| `category_id`    | Zugehörige Kategorie       |
| `active`         | Status des Artikels        |
| `created_at`     | Erstellungszeitpunkt       |
| `updated_at`     | Letzte Änderung            |

### `article_categories`

Enthält die Kategorien für die Artikel.

| Feld         | Beschreibung                 |
| ------------ | ---------------------------- |
| `id`         | Eindeutige ID der Kategorie  |
| `name`       | Name der Kategorie           |
| `short_name` | Kürzel der Kategorie         |
| `color`      | Farbe der Kategorie          |
| `sort_order` | Reihenfolge in der Anwendung |
| `active`     | Status der Kategorie         |

### Bestands- und Bewegungsdaten

Die Bestandsverwaltung basiert auf den Lagerbewegungen. Dadurch können Aus- und Einbuchungen nachvollzogen und Bestände daraus ermittelt werden.

Die konkreten Tabellen und Beziehungen werden bei Änderungen an der Datenbankstruktur in dieser Dokumentation ergänzt.

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

Änderungen an der Datenbankstruktur werden über Migrationen bzw. die dafür vorgesehenen Datenbankänderungen der Anwendung durchgeführt.

Die produktive SQLite-Datei sollte dabei **nicht gelöscht oder durch eine Version aus Git ersetzt werden**.

