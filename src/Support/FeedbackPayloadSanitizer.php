<?php

namespace Uteq\FeedbackHub\Support;

class FeedbackPayloadSanitizer
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->sanitizeArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            if ($this->isSensitiveKey($key)) {
                $clean[$key] = '[filtered]';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = mb_substr($this->sanitizeString($value), 0, 500);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (['password', 'passwd', 'token', 'secret', 'authorization', 'cookie', 'csrf', '_token', 'api_key', 'apikey', 'key'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace_callback(
            '/([?&])([^=&#]*(?:password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey|key)[^=&#]*)=([^&#]*)/i',
            fn (array $matches): string => $matches[1].$matches[2].'=[filtered]',
            $value,
        ) ?? $value;

        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[filtered]', $value) ?? $value;

        return preg_replace(
            '/\b(password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey)\s*[:=]\s*([^\s,;&#]+)/i',
            '$1=[filtered]',
            $value,
        ) ?? $value;
    }
}
