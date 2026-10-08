<?php

namespace Uteq\FeedbackHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;

class StoreFeedbackReportController extends Controller
{
    public function __invoke(Request $request, FeedbackPayloadSanitizer $sanitizer): JsonResponse
    {
        abort_unless((bool) config('feedback-hub.enabled'), 404);

        $validated = $request->validate($this->validationRules());

        $user = $request->user();
        $screenshot = $this->storeScreenshot($validated['screenshot'] ?? null);

        $report = ($this->reportModel())::query()->create(array_merge([
            'project' => (string) config('feedback-hub.project'),
            'type' => $validated['type'],
            'title' => $sanitizer->redactString($validated['title']),
            'description' => isset($validated['description']) ? $sanitizer->redactString($validated['description']) : null,
            'page_url' => $sanitizer->redactString($validated['url']),
            'element_selector' => isset($validated['element_selector']) ? $sanitizer->redactString($validated['element_selector']) : null,
            'element_rect' => $sanitizer->sanitize($validated['element_rect'] ?? null),
            'session_data' => $sanitizer->sanitize($validated['session_data'] ?? null),
            'console_errors' => $sanitizer->sanitize($validated['console_errors'] ?? null),
            'network_requests' => $sanitizer->sanitize($validated['network_requests'] ?? null),
            'form_state' => $sanitizer->sanitize($validated['form_state'] ?? null),
            'screenshot_disk' => $screenshot['disk'] ?? null,
            'screenshot_path' => $screenshot['path'] ?? null,
            'screenshot_mime' => $screenshot['mime'] ?? null,
            'screenshot_size' => $screenshot['size'] ?? null,
            'reporter_type' => $user ? $user->getMorphClass() : null,
            'reporter_id' => $user?->getKey(),
            'reporter_name' => $user?->name,
            'reporter_email' => $user?->email,
        ], $this->additionalAttributes($validated, $sanitizer)));

        ($this->processJob())::dispatch($report)->onQueue((string) config('feedback-hub.queue'));

        return response()->json([
            'success' => true,
            'reference' => $report->reference,
            'id' => $report->uuid,
        ], 201);
    }

    /** @return array<string, mixed> */
    protected function validationRules(): array
    {
        return [
            'type' => ['required', Rule::in(['bug', 'suggestion', 'question'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'url' => ['required', 'url', 'max:2048'],
            'element_selector' => ['nullable', 'string', 'max:500'],
            'element_rect' => ['nullable', 'array'],
            'screenshot' => ['nullable', 'string'],
            'session_data' => ['nullable', 'array'],
            'console_errors' => ['nullable', 'array'],
            'network_requests' => ['nullable', 'array'],
            'form_state' => ['nullable', 'array'],
        ];
    }

    /** @return class-string<FeedbackReport> */
    protected function reportModel(): string
    {
        return FeedbackReport::class;
    }

    /** @return class-string */
    protected function processJob(): string
    {
        return ProcessFeedbackReportJob::class;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function additionalAttributes(array $validated, FeedbackPayloadSanitizer $sanitizer): array
    {
        return [];
    }

    /**
     * @return array{disk: string, path: string, mime: string, size: int}|null
     */
    private function storeScreenshot(?string $dataUri): ?array
    {
        if (! $dataUri || ! preg_match('/^data:image\/(png|jpeg|webp);base64,/', $dataUri, $matches)) {
            return null;
        }

        $binary = base64_decode((string) preg_replace('/^data:image\/\w+;base64,/', '', $dataUri), true);
        if ($binary === false) {
            return null;
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $disk = (string) config('feedback-hub.storage_disk');
        $path = trim((string) config('feedback-hub.screenshot_path'), '/').'/'.uniqid('report_', true).'.'.$extension;

        Storage::disk($disk)->put($path, $binary);

        return [
            'disk' => $disk,
            'path' => $path,
            'mime' => 'image/'.$matches[1],
            'size' => strlen($binary),
        ];
    }
}
