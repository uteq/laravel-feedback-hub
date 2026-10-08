<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;
use Uteq\FeedbackHub\Http\Controllers\StoreFeedbackReportController;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;
use Uteq\FeedbackHub\Tests\Support\User;

// mortelos/feedback builds on these hooks; this pins the contract here.

class ExtendedProcessJob extends ProcessFeedbackReportJob {}

class ExtendedStoreController extends StoreFeedbackReportController
{
    protected function validationRules(): array
    {
        return array_merge(parent::validationRules(), ['extra' => ['required', 'string']]);
    }

    protected function processJob(): string
    {
        return ExtendedProcessJob::class;
    }

    protected function additionalAttributes(array $validated, FeedbackPayloadSanitizer $sanitizer): array
    {
        return ['project' => $sanitizer->redactString($validated['extra'])];
    }
}

it('lets a subclass add validation, attributes and its own job to the store flow', function (): void {
    Queue::fake();
    Route::middleware('web')->post('_extended-store', ExtendedStoreController::class);
    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $payload = ['type' => 'bug', 'title' => 'Knop werkt niet', 'url' => 'https://example.test'];

    $this->actingAs($user)->postJson('_extended-store', $payload)->assertUnprocessable()->assertJsonValidationErrors('extra');

    $this->actingAs($user)->postJson('_extended-store', $payload + ['extra' => 'Klant token=geheim'])->assertCreated();

    expect(FeedbackReport::query()->sole()->project)->toBe('Klant token=[filtered]');
    Queue::assertPushed(ExtendedProcessJob::class);
});

it('lets a subclass add context to the issue and Telegram bodies', function (): void {
    $builder = new class(app(FeedbackPayloadSanitizer::class)) extends IssueBodyBuilder
    {
        protected function appendIssueContext(array &$lines, FeedbackReport $report): void
        {
            $lines[] = 'Issue-context';
        }

        protected function appendTelegramContext(array &$lines, FeedbackReport $report): void
        {
            $lines[] = 'Telegram-context';
        }
    };

    $report = FeedbackReport::query()->create(['type' => 'bug', 'title' => 'Titel', 'page_url' => 'https://example.test']);

    expect($builder->build($report))->toContain('Issue-context')
        ->and($builder->telegramMessage($report))->toContain('Telegram-context');
});

it('lets a subclass supply the Telegram credentials', function (): void {
    config(['feedback-hub.telegram.bot_token' => null, 'feedback-hub.telegram.chat_id' => null]);

    $client = new class extends TelegramFeedbackClient
    {
        protected function botToken(): string
        {
            return 'tenant-token';
        }

        protected function chatId(): string
        {
            return 'tenant-room';
        }
    };

    expect($client->isConfigured())->toBeTrue();
});
