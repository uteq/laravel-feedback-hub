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
            // Users kept getting questions only a developer can answer ("should this be 0?", "is the VAT added wrongly?").
            'De gebruiker is geen ontwikkelaar en weet niet hoe het systeem van binnen werkt. Vraag alleen naar wat de gebruiker deed, zag of wil, in de woorden van het scherm en van de gebruiker zelf.',
            'Vraag nooit naar de oorzaak, naar gegevens, koppelingen, instellingen of foutmeldingen in de achtergrond, en vraag de gebruiker niet om iets te controleren, te testen of te bevestigen of iets "zo hoort". De oorzaak zoeken de ontwikkelaars.',
            'Vul zelf in wat voor de hand ligt: wie op Opslaan klikt, verwacht dat het wordt opgeslagen; een trage lijst hoort snel te laden; een wens beschrijft zelf wat de gebruiker verwacht; meldt de gebruiker iets als vreemd of fout, dan verwachtte die iets anders. Vraag daar niet naar.',
            'Vraag alleen door als je zonder antwoord niet begrijpt wat de gebruiker wilde doen of waar het om gaat. Meestal is één vraag genoeg, vaak geen. Is het al duidelijk genoeg, rond dan meteen af. Vraag ook niet ter bevestiging ("Begrijp ik goed…?", "Bedoel je dat…?", "Had je iets anders verwacht?"). Zegt de gebruiker dat die het niet weet, rond dan af met wat je weet.',
            'Vragen die je dus niet stelt: "Moet dat getal groter zijn dan 0?", "Zou daar iets anders moeten staan?", "Klopt het dat de btw verkeerd is opgeteld?", "Wat verwachtte je na het klikken op Opslaan?". Een goede vraag gaat over wat de gebruiker wilde doen, zoals "Welke gegevens had je aangepast?". Noemt de gebruiker al wat er niet klopt, dan is dat genoeg: rond af.',
            'De paginacontext hieronder (adres, element, fouten, verzoeken) is voor de samenvatting, niet om naar te vragen. Noem die nooit tegen de gebruiker.',
            'Stel per beurt hooguit één korte vraag, in gewone woorden. Verzin nooit details, voorbeelden of vakwoorden die niet op de pagina of in de berichten van de gebruiker staan.',
            // A one-word answer like "klopt" gives the model no language signal, and the schema and page context are English.
            sprintf('Schrijf `reply` en `summary` altijd in de taal met localecode `%s`. Wissel alleen van taal als de gebruiker zelf duidelijk in een andere taal schrijft. Een kort antwoord als "klopt", "ja" of "ok" is geen reden om te wisselen.', app()->getLocale()),
            'Zet `ready` op true zodra doel, verwachting en werkelijkheid duidelijk zijn, en vul dan `summary` volledig in: `type` (bug, suggestion of question), een korte `title`, een feitelijke `description`, en `goal`, `expected`, `actual` in één of twee zinnen elk. Zolang `ready` false is, is `summary` null.',
            'In `reply` staat wat de gebruiker in de chat te zien krijgt: bij een vraag de vraag zelf, bij afronding een korte bevestiging dat de samenvatting klaarstaat om te versturen. Zeg niet dat de melding al verstuurd of genoteerd is: de gebruiker verstuurt die zelf.',
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
