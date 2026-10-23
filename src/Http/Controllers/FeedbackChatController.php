<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;
use Uteq\FeedbackHub\Ai\FeedbackIntakeAgent;
use Uteq\FeedbackHub\Support\FeedbackAiAvailability;
use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;

final class FeedbackChatController extends Controller
{
    public function __invoke(Request $request, FeedbackPayloadSanitizer $sanitizer): JsonResponse
    {
        abort_unless((bool) config('feedback-hub.enabled'), 404);
        abort_unless(FeedbackAiAvailability::available(), 404);

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:5000'],
            'url' => ['required', 'url', 'max:2048'],
            'element_selector' => ['nullable', 'string', 'max:500'],
            'screenshot' => ['nullable', 'string', 'max:8000000', 'regex:/^data:image\/(png|jpeg|webp);base64,/'],
            'console_errors' => ['nullable', 'array', 'max:50'],
            'network_requests' => ['nullable', 'array', 'max:50'],
        ]);
        $history = array_values(array_filter($validated['messages'], 'is_array'));

        $messages = array_map(
            fn (array $message): Message => new Message((string) ($message['role'] ?? ''), $sanitizer->redactString((string) ($message['content'] ?? ''))),
            $history,
        );

        $current = array_pop($messages);

        if ($current === null || $current->role->value !== 'user') {
            throw ValidationException::withMessages(['messages' => 'The last message must come from the user.']);
        }

        $userTurns = count(array_filter($history, fn (array $message): bool => ($message['role'] ?? null) === 'user'));
        $maxTurns = is_numeric(config('feedback-hub.ai.max_turns')) ? (int) config('feedback-hub.ai.max_turns') : 0;

        // Hard stop: the client is stateless, so without this a caller could keep the model talking.
        if ($userTurns > $maxTurns) {
            return response()->json(['fallback' => true]);
        }

        $isFinalTurn = $userTurns >= $maxTurns;

        $agent = new FeedbackIntakeAgent(
            messages: array_values($messages),
            pageContext: $this->pageContext($validated, $sanitizer),
            isFinalTurn: $isFinalTurn,
        );

        try {
            $response = $agent->prompt(
                $current->content ?? '',
                $this->attachments($validated['screenshot'] ?? null),
                (string) config('feedback-hub.ai.provider'),
                (string) config('feedback-hub.ai.model'),
            );
        } catch (Throwable $exception) {
            report($exception);

            // Feedback must never be blocked by the LLM: the widget shows the classic form.
            return response()->json(['fallback' => true]);
        }

        if (! $response instanceof StructuredAgentResponse) {
            return response()->json(['fallback' => true]);
        }

        $structured = $response->structured;

        return response()->json([
            'reply' => is_string($structured['reply'] ?? null) || is_numeric($structured['reply'] ?? null) ? (string) $structured['reply'] : '',
            'ready' => ($structured['ready'] ?? false) === true,
            'summary' => is_array($structured['summary'] ?? null) ? $structured['summary'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function pageContext(array $validated, FeedbackPayloadSanitizer $sanitizer): string
    {
        $lines = ['URL: '.$sanitizer->redactString((string) ($validated['url'] ?? ''))];

        $selector = $validated['element_selector'] ?? null;
        if ($selector !== null && $selector !== '') {
            $lines[] = 'Element: '.$sanitizer->redactString($selector);
        }

        foreach (['console_errors' => 'Console errors', 'network_requests' => 'Network requests'] as $key => $label) {
            if (! empty($validated[$key])) {
                $lines[] = $label.': '.Str::limit((string) json_encode($sanitizer->sanitize($validated[$key]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 4000);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<Image>
     */
    private function attachments(?string $dataUri): array
    {
        if (! $dataUri || ! preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/s', $dataUri, $matches)) {
            return [];
        }

        // ponytail: the screenshot travels with every turn (~0.5 MB, ~1.5k tokens on
        // Haiku). If that ever hurts, cache it server-side behind an encrypted token.
        return [Image::fromBase64($matches[2], 'image/'.$matches[1])];
    }
}
