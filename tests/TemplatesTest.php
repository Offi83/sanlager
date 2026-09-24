<?php

namespace LagerApp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Vorlagen (templates/) und Seitendaten (pages/) werden per require in
 * public/index.php eingebunden. `use`-Anweisungen gelten in PHP aber nur
 * für die eigene Datei – eine Klasse muss dort deshalb immer mit vollem
 * Namen (\LagerApp\...) angesprochen werden. Sonst gibt es einen Fehler,
 * der womöglich nur in seltenen Fällen auftritt (z. B. in einem
 * ??-Rückfallwert) und den der Seitentest dann nicht sieht.
 */
class TemplatesTest extends TestCase
{
    public function testClassesAreFullyQualified(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/templates/*.php'),
            glob($root . '/templates/*/*.php'),
            glob($root . '/pages/*.php')
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $name = basename(dirname($file)) . '/' . basename($file);

            $this->assertSame([], $this->unqualifiedAppClasses(file_get_contents($file)), $name . ': Klasse ohne \\LagerApp\\ verwendet');
        }
    }

    /**
     * Vorlagen geben nur aus: Daten laden, Eingaben lesen und weiterleiten
     * gehören nach pages/. Eine Weiterleitung aus der Vorlage käme zu spät
     * (der Seitenkopf ist schon gesendet) und klappt nur, solange PHP die
     * Ausgabe zufällig puffert.
     */
    public function testPageTemplatesDoNotLoadDataOrRedirect(): void
    {
        foreach (glob(dirname(__DIR__) . '/templates/pages/*.php') as $file) {
            $code = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/redirect\(|\$_(GET|POST)\b|\$(stock|articles|reports|batches|categories|locationRepository)->/',
                $code,
                'templates/pages/' . basename($file) . ': gehört nach pages/'
            );
        }
    }

    /**
     * Ausblenden per hidden-Attribut (z. B. MHD-Auswahl bei Artikeln ohne
     * MHD) wirkt nur, wenn keine display-Regel es überstimmt.
     */
    public function testHiddenAttributeAlwaysHides(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/public/css/app.css');

        $this->assertMatchesRegularExpression('/\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/', $css);
    }

    /**
     * Findet per Tokenizer unqualifizierte Verwendungen von Klassen aus
     * dem Namensraum LagerApp (`new Foo`, `Foo::BAR`, `Foo::bar()`).
     * Globale Klassen wie DateTimeImmutable funktionieren auch ohne
     * Namensraum und werden nicht gemeldet.
     *
     * @return string[]
     */
    private function unqualifiedAppClasses(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $found = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            $isNew = is_array($previous) && $previous[0] === T_NEW;
            $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;

            if (($isNew || $isStatic) && class_exists('LagerApp\\' . $token[1])) {
                $found[] = $token[1] . ' (Zeile ' . $token[2] . ')';
            }
        }

        return $found;
    }
}
