<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * Short intake conversation that turns a symptom ("button does nothing") into
 * an intent: what the user tried to do, what they expected, what happened.
 * Stateless: the caller passes the full history each turn.
 */
final class FeedbackIntakeAgent implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<Message>  $messages  History without the current user turn.
     */
    public function __construct(
        private array $messages = [],
        private string $pageContext = '',
        private bool $isFinalTurn = false,
    ) {}

    public function instructions(): string
    {
        $lines = [
            'Je bent de feedback-intake van een webapplicatie. De gebruiker meldt een probleem, wens of vraag.',
            'Jouw taak: achterhaal in zo min mogelijk beurten (1) het doel: wat probeerde de gebruiker te bereiken, (2) wat de gebruiker verwachtte, en (3) wat er werkelijk gebeurde.',
            'Stel per beurt precies één korte vraag. Verzin nooit details die de gebruiker niet gaf.',
            // A one-word answer like "klopt" gives the model no language signal, and the schema and page context are English.
            sprintf('Schrijf `reply` en `summary` altijd in de taal met localecode `%s`. Wissel alleen van taal als de gebruiker zelf duidelijk in een andere taal schrijft. Een kort antwoord als "klopt", "ja" of "ok" is geen reden om te wisselen.', app()->getLocale()),
            'Zet `ready` op true zodra doel, verwachting en werkelijkheid duidelijk zijn, en vul dan `summary` volledig in: `type` (bug, suggestion of question), een korte `title`, een feitelijke `description`, en `goal`, `expected`, `actual` in één of twee zinnen elk. Zolang `ready` false is, is `summary` null.',
            'In `reply` staat wat de gebruiker in de chat te zien krijgt: bij een vraag de vraag zelf, bij afronding een korte bevestiging dat de samenvatting klaarstaat.',
        ];

        if ($this->pageContext !== '') {
            $lines[] = "Context van de pagina waar de gebruiker feedback gaf:\n{$this->pageContext}";
        }

        if ($this->isFinalTurn) {
            $lines[] = 'Dit is de laatste beurt. Zet `ready` op true en vul `summary` in met wat je nu weet; markeer ontbrekende onderdelen als onbekend in plaats van door te vragen.';
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->required(),
            'ready' => $schema->boolean()->required(),
            'summary' => $schema->object([
                'type' => $schema->string()->enum(['bug', 'suggestion', 'question'])->required(),
                'title' => $schema->string()->max(255)->required(),
                'description' => $schema->string()->required(),
                'goal' => $schema->string()->required(),
                'expected' => $schema->string()->required(),
                'actual' => $schema->string()->required(),
            ])->nullable(),
        ];
    }
}
