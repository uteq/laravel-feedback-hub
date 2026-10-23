<?php

use Laravel\Ai\Prompts\AgentPrompt;
use Uteq\FeedbackHub\Ai\FeedbackIntakeAgent;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\User;

function chatUser(): User
{
    return User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);
}

function chatPayload(array $overrides = []): array
{
    return array_merge([
        'messages' => [
            ['role' => 'assistant', 'content' => 'Waar liep je tegenaan?'],
            ['role' => 'user', 'content' => 'De downloadknop doet niks'],
        ],
        'url' => 'https://example.test/facturen',
        'element_selector' => 'button.download',
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('feedback-hub.ai.enabled', true);
    config()->set('feedback-hub.ai.key', null);
    config()->set('ai.providers.anthropic.key', 'test-key');
});

it('prompts with the resolved provider without changing the app key', function (?string $dedicatedKey, ?string $appKey, string $provider): void {
    config()->set('feedback-hub.ai.key', $dedicatedKey);
    config()->set('ai.providers.anthropic.key', $appKey);
    FeedbackIntakeAgent::fake([['reply' => 'Ok', 'ready' => false, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload())
        ->assertOk()
        ->assertJsonPath('reply', 'Ok');

    FeedbackIntakeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === $provider
        && $prompt->model === 'claude-haiku-4-5'
        && $prompt->provider->providerCredentials()['key'] === ($dedicatedKey ?: $appKey));

    expect(config('ai.providers.anthropic.key'))->toBe($appKey);

    if ($dedicatedKey !== null && $dedicatedKey !== '') {
        expect(config('ai.providers.feedback-hub.key'))->toBe($dedicatedKey);
    }
})->with([
    'dedicated key only' => ['feedback-test-key', '', 'feedback-hub'],
    'both keys' => ['feedback-test-key', 'app-test-key', 'feedback-hub'],
    'no dedicated key' => [null, 'app-test-key', 'anthropic'],
    'empty dedicated key' => ['', 'app-test-key', 'anthropic'],
]);

it('returns the agent reply while the intent is not yet clear', function (): void {
    FeedbackIntakeAgent::fake([
        ['reply' => 'Wat wilde je downloaden?', 'ready' => false, 'summary' => null],
    ]);

    $this->actingAs(chatUser())
        ->postJson(route('feedback-hub.chat'), chatPayload())
        ->assertOk()
        ->assertExactJson(['reply' => 'Wat wilde je downloaden?', 'ready' => false, 'summary' => null]);

    FeedbackIntakeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $history = $prompt->agent->messages();

        return $prompt->prompt === 'De downloadknop doet niks'
            && count($history) === 1
            && $history[0]->content === 'Waar liep je tegenaan?'
            && str_contains((string) $prompt->agent->instructions(), 'button.download');
    });

    expect(FeedbackReport::query()->count())->toBe(0);
});

it('returns the summary when the agent is ready', function (): void {
    $summary = [
        'type' => 'bug',
        'title' => 'Factuur downloaden lukt niet',
        'description' => 'Klik op download geeft geen reactie.',
        'goal' => 'Factuur als PDF opslaan',
        'expected' => 'Een PDF-download',
        'actual' => 'Niets gebeurt',
    ];

    FeedbackIntakeAgent::fake([['reply' => 'Dank, ik heb het samengevat.', 'ready' => true, 'summary' => $summary]]);

    $this->actingAs(chatUser())
        ->postJson(route('feedback-hub.chat'), chatPayload())
        ->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('summary', $summary);
});

it('gives the final-turn instruction once max_turns user messages are reached', function (): void {
    config()->set('feedback-hub.ai.max_turns', 2);
    FeedbackIntakeAgent::fake([['reply' => 'Klaar.', 'ready' => true, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'messages' => [
            ['role' => 'user', 'content' => 'Eerste'],
            ['role' => 'assistant', 'content' => 'Vraag'],
            ['role' => 'user', 'content' => 'Tweede'],
        ],
    ]))->assertOk();

    FeedbackIntakeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'laatste beurt'));
});

it('passes the screenshot as an image attachment', function (): void {
    FeedbackIntakeAgent::fake([['reply' => 'Ok', 'ready' => false, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'screenshot' => 'data:image/png;base64,'.base64_encode('fake-image'),
    ]))->assertOk();

    FeedbackIntakeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1
        && $prompt->attachments->first()->mimeType() === 'image/png');
});

it('redacts secrets before anything reaches the model', function (): void {
    FeedbackIntakeAgent::fake([['reply' => 'Ok', 'ready' => false, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'messages' => [['role' => 'user', 'content' => 'Mijn token=abc123 werkt niet']],
        'url' => 'https://example.test/x?api_key=zzz',
        'console_errors' => [['message' => 'Bearer secret-token failed']],
    ]))->assertOk();

    FeedbackIntakeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $instructions = (string) $prompt->agent->instructions();

        return $prompt->prompt === 'Mijn token=[filtered] werkt niet'
            && ! str_contains($instructions, 'zzz')
            && ! str_contains($instructions, 'secret-token');
    });
});

it('falls back to the form when the agent throws', function (): void {
    FeedbackIntakeAgent::fake([fn () => throw new RuntimeException('provider down')]);

    $this->actingAs(chatUser())
        ->postJson(route('feedback-hub.chat'), chatPayload())
        ->assertOk()
        ->assertExactJson(['fallback' => true]);
});

it('is not found when chat is unavailable', function (): void {
    config()->set('ai.providers.anthropic.key', null);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload())->assertNotFound();
});

it('rejects a history that does not end with a user message', function (): void {
    FeedbackIntakeAgent::fake();

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'messages' => [['role' => 'assistant', 'content' => 'Hoi']],
    ]))->assertUnprocessable();
});

it('bounds the history size', function (): void {
    FeedbackIntakeAgent::fake();

    $user = chatUser();
    $tooMany = array_fill(0, 21, ['role' => 'user', 'content' => 'x']);

    $this->actingAs($user)->postJson(route('feedback-hub.chat'), chatPayload(['messages' => $tooMany]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('messages');

    $this->actingAs($user)->postJson(route('feedback-hub.chat'), chatPayload([
        'messages' => [['role' => 'user', 'content' => str_repeat('x', 5001)]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('messages.0.content');
});

it('requires authentication', function (): void {
    $this->postJson(route('feedback-hub.chat'), chatPayload())->assertUnauthorized();
});

it('throttles the chat endpoint', function (): void {
    FeedbackIntakeAgent::fake(fn () => ['reply' => 'Ok', 'ready' => false, 'summary' => null]);

    $user = chatUser();

    foreach (range(1, 30) as $i) {
        $this->actingAs($user)->postJson(route('feedback-hub.chat'), chatPayload())->assertOk();
    }

    $this->actingAs($user)->postJson(route('feedback-hub.chat'), chatPayload())->assertStatus(429);
});

it('is not found when the module is disabled', function (): void {
    config()->set('feedback-hub.enabled', false);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload())->assertNotFound();
});

it('rejects a screenshot with a disallowed mime type', function (): void {
    FeedbackIntakeAgent::fake();

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'screenshot' => 'data:image/gif;base64,'.base64_encode('fake-image'),
    ]))->assertUnprocessable()->assertJsonValidationErrors('screenshot');
});

it('stops calling the model once the turn limit is exceeded', function (): void {
    config()->set('feedback-hub.ai.max_turns', 1);
    FeedbackIntakeAgent::fake([['reply' => 'Nog een vraag', 'ready' => false, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'messages' => [
            ['role' => 'user', 'content' => 'Eerste'],
            ['role' => 'assistant', 'content' => 'Vraag'],
            ['role' => 'user', 'content' => 'Tweede'],
        ],
    ]))->assertOk()->assertExactJson(['fallback' => true]);

    FeedbackIntakeAgent::assertNeverPrompted();
});

it('caps console and network context sent to the model', function (): void {
    FeedbackIntakeAgent::fake([['reply' => 'Ok', 'ready' => false, 'summary' => null]]);

    $this->actingAs(chatUser())->postJson(route('feedback-hub.chat'), chatPayload([
        'console_errors' => array_fill(0, 50, ['message' => str_repeat('x', 5000)]),
    ]))->assertOk();

    FeedbackIntakeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => strlen((string) $prompt->agent->instructions()) < 10000);
});
