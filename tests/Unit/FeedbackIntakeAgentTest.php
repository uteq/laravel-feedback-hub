<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Messages\Message;
use Uteq\FeedbackHub\Ai\FeedbackIntakeAgent;

it('exposes a structured schema with reply, ready and summary', function (): void {
    $schema = (new FeedbackIntakeAgent)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['reply', 'ready', 'summary']);

    $summary = $schema['summary']->toArray();

    expect($summary['properties']['type']['enum'])->toBe(['bug', 'suggestion', 'question'])
        ->and(array_keys($summary['properties']))->toBe(['type', 'title', 'description', 'goal', 'expected', 'actual']);
});

it('returns the given history as conversation messages', function (): void {
    $history = [new Message('assistant', 'Waar liep je tegenaan?'), new Message('user', 'De knop doet niks')];

    expect((new FeedbackIntakeAgent($history))->messages())->toBe($history);
});

it('includes page context and asks for goal, expectation and reality', function (): void {
    $instructions = (string) (new FeedbackIntakeAgent([], "URL: https://example.test/facturen\nElement: button.download"))->instructions();

    expect($instructions)
        ->toContain('https://example.test/facturen')
        ->toContain('button.download')
        ->toContain('het doel')
        ->toContain('de gebruiker verwachtte')
        ->not->toContain('laatste beurt');
});

it('pins the reply language to the app locale', function (): void {
    app()->setLocale('nl');

    expect((string) (new FeedbackIntakeAgent)->instructions())->toContain('localecode `nl`');
});

it('demands closure on the final turn', function (): void {
    $instructions = (string) (new FeedbackIntakeAgent([], '', true))->instructions();

    expect($instructions)->toContain('laatste beurt')->toContain('ready');
});
