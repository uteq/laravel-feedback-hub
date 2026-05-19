<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Uteq\FeedbackHub\Clients\GitHubFeedbackClient;
use Uteq\FeedbackHub\Clients\LinearFeedbackClient;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;

class FeedbackHubHealthCommand extends Command
{
    protected $signature = 'feedback-hub:health {--live : Call GitHub, Linear and Telegram APIs}';

    protected $description = 'Validate Feedback Hub configuration.';

    public function handle(
        GitHubFeedbackClient $github,
        LinearFeedbackClient $linear,
        TelegramFeedbackClient $telegram,
    ): int {
        $ok = true;

        $this->line('Feedback Hub');
        $this->line('Project: '.config('feedback-hub.project'));
        $this->line('Route prefix: '.config('feedback-hub.route_prefix'));

        $ok = $this->checkIntegration('GitHub', (bool) config('feedback-hub.github.enabled'), $github->isConfigured()) && $ok;
        $ok = $this->checkIntegration('Linear', (bool) config('feedback-hub.linear.enabled'), $linear->isConfigured()) && $ok;
        $ok = $this->checkIntegration('Telegram', (bool) config('feedback-hub.telegram.enabled'), $telegram->isConfigured()) && $ok;

        if ($this->option('live')) {
            $ok = $this->checkGitHubLive($github) && $ok;
            $ok = $this->checkLinearLive($linear) && $ok;
            $ok = $this->checkTelegramLive($telegram) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function checkIntegration(string $name, bool $enabled, bool $configured): bool
    {
        if (! $enabled) {
            $this->components->warn("{$name}: disabled");

            return true;
        }

        if (! $configured) {
            $this->components->error("{$name}: missing configuration");

            return false;
        }

        $this->components->info("{$name}: configured");

        return true;
    }

    private function checkGitHubLive(GitHubFeedbackClient $github): bool
    {
        if (! (bool) config('feedback-hub.github.enabled')) {
            return true;
        }

        if (! $github->isConfigured()) {
            return false;
        }

        $repo = trim((string) config('feedback-hub.github.repo'), '/');
        $response = Http::withToken((string) config('feedback-hub.github.token'))
            ->acceptJson()
            ->get("https://api.github.com/repos/{$repo}");

        if (! $response->successful()) {
            $this->components->error('GitHub live check failed: HTTP '.$response->status());

            return false;
        }

        if (! (bool) $response->json('has_issues')) {
            $this->components->error('GitHub live check failed: issues are disabled for '.$repo);

            return false;
        }

        $permissions = $response->json('permissions');
        if (is_array($permissions) && ! $this->hasGitHubRepoAccess($permissions)) {
            $this->components->error('GitHub live check failed: token has no repository access for '.$repo);

            return false;
        }

        foreach ($this->csv((string) config('feedback-hub.github.labels')) as $label) {
            $labelResponse = Http::withToken((string) config('feedback-hub.github.token'))
                ->acceptJson()
                ->get("https://api.github.com/repos/{$repo}/labels/".rawurlencode($label));

            if (! $labelResponse->successful()) {
                $this->components->error("GitHub label check failed: {$label}");

                return false;
            }
        }

        $this->components->info('GitHub live check passed for '.$repo);

        return true;
    }

    private function checkLinearLive(LinearFeedbackClient $linear): bool
    {
        if (! (bool) config('feedback-hub.linear.enabled')) {
            return true;
        }

        if (! $linear->isConfigured()) {
            return false;
        }

        $variables = [
            'teamId' => (string) config('feedback-hub.linear.team_id'),
        ];

        $projectId = (string) config('feedback-hub.linear.project_id');
        $projectQuery = '';
        $projectVariable = '';

        if (filled($projectId)) {
            $variables['projectId'] = $projectId;
            $projectVariable = ', $projectId: String!';
            $projectQuery = ' project(id: $projectId) { id name }';
        }

        $response = Http::withToken((string) config('feedback-hub.linear.token'))
            ->acceptJson()
            ->post('https://api.linear.app/graphql', [
                'query' => "query FeedbackHubConfig(\$teamId: String!{$projectVariable}) { viewer { id name } team(id: \$teamId) { id name key }{$projectQuery} }",
                'variables' => $variables,
            ]);

        if (! $response->successful() || $response->json('errors')) {
            $this->components->error('Linear live check failed');

            return false;
        }

        if (! $response->json('data.team.id')) {
            $this->components->error('Linear team check failed');

            return false;
        }

        if (filled($projectId) && ! $response->json('data.project.id')) {
            $this->components->error('Linear project check failed');

            return false;
        }

        $this->components->info('Linear live check passed for team '.$response->json('data.team.name'));

        return true;
    }

    /**
     * @param  array<string, mixed>  $permissions
     */
    private function hasGitHubRepoAccess(array $permissions): bool
    {
        foreach (['admin', 'maintain', 'push', 'triage', 'pull'] as $permission) {
            if (($permissions[$permission] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function checkTelegramLive(TelegramFeedbackClient $telegram): bool
    {
        if (! (bool) config('feedback-hub.telegram.enabled')) {
            return true;
        }

        if (! $telegram->isConfigured()) {
            return false;
        }

        $result = $telegram->getMe();
        if (! ($result['ok'] ?? false)) {
            $this->components->error('Telegram live check failed');

            return false;
        }

        $this->components->info('Telegram live check passed as @'.$result['username']);

        $chat = $telegram->getChat();
        if (! ($chat['ok'] ?? false)) {
            $this->components->error('Telegram chat check failed');

            return false;
        }

        $this->components->info('Telegram chat check passed'.(($chat['title'] ?? '') !== '' ? ': '.$chat['title'] : ''));

        return true;
    }
}
