<?php

namespace LagerApp;

use Closure;
use RuntimeException;

/**
 * Verarbeitet die POST-Aktion „print_labels“: Etiketten an den
 * Etikettendrucker schicken (nur mit LABEL_OUTPUT=printer, siehe
 * LabelConfig). A4-Bögen druckt dagegen der Browser selbst.
 *
 * Eingabe wie bei den Sammeletiketten: qty[<Artikel-ID>] = Anzahl
 * (0–99, nur aktive Artikel). return=label kehrt zum Einzeletikett
 * zurück, sonst geht es mit derselben Auswahl zu den Sammeletiketten.
 */
class LabelActions
{
    use ReadsInput;

    public const MAX_PER_ARTICLE = 99;

    /**
     * @param array<string, mixed> $env          in der Anwendung $_ENV
     * @param Closure|null         $runner       nur für Tests, siehe LabelPrinter
     * @param Closure|null         $isExecutable nur für Tests, siehe LabelPrinter
     */
    public function __construct(
        private ArticleRepository $articles,
        private array $env,
        private ?Closure $runner = null,
        private ?Closure $isExecutable = null
    ) {
    }

    public function dispatch(?string $action, array $input): ?ActionResult
    {
        return match ($action) {
            'print_labels' => $this->print($input),
            default => null,
        };
    }

    private function print(array $input): ActionResult
    {
        $config = LabelConfig::fromEnv($this->env);

        if (!$config->usesPrinter()) {
            throw new RuntimeException(
                'Kein Etikettendrucker eingerichtet (LABEL_OUTPUT=printer in der .env, siehe Installationsanleitung).'
            );
        }

        $quantities = self::quantities($this->array($input, 'qty'), $this->articles->all());

        if ($quantities === []) {
            throw new RuntimeException('Bitte bei mindestens einem Artikel eine Anzahl eintragen.');
        }

        $labels = [];

        foreach ($quantities as $articleId => $quantity) {
            array_push($labels, ...array_fill(0, $quantity, $this->articles->findActive($articleId)));
        }

        $confirmed = (new LabelPrinter($config, $this->runner, $this->isExecutable))->print($labels);

        $count = count($labels);
        $message = ($count === 1 ? '1 Etikett' : $count . ' Etiketten')
            . ($confirmed ? ' gedruckt.' : ' an den Drucker gesendet.');

        $articleId = array_key_first($quantities);

        $url = $this->string($input, 'return') === 'label' && count($quantities) === 1
            ? '?page=label&id=' . $articleId
            : '?page=labels&' . http_build_query(['qty' => $quantities]);

        return ActionResult::redirect($url, $message);
    }

    /**
     * Anzahl je Artikel aus qty[<ID>]: nur aktive Artikel (in der
     * Reihenfolge der Artikelliste), 1–99, alles andere wird ignoriert.
     * Auch für die Sammeletiketten-Seite (pages/labels.php).
     *
     * @param array<array-key, mixed> $requested
     * @param array<int, array<string, mixed>> $activeArticles ArticleRepository::all()
     * @return array<int, int> Artikel-ID => Anzahl
     */
    public static function quantities(array $requested, array $activeArticles): array
    {
        $quantities = [];

        foreach ($activeArticles as $article) {
            $raw = $requested[$article['id']] ?? '';
            $quantity = is_string($raw) && ctype_digit(trim($raw))
                ? min((int) $raw, self::MAX_PER_ARTICLE)
                : 0;

            if ($quantity > 0) {
                $quantities[(int) $article['id']] = $quantity;
            }
        }

        return $quantities;
    }
}
