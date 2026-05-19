<?php

use Illuminate\Support\Env;

it('uses existing project env names as configuration fallbacks', function (): void {
    $repository = Env::getRepository();

    foreach ([
        'GITHUB_TOKEN' => 'github-token',
        'GITHUB_REPOSITORY' => 'uteq/example',
        'LINEAR_API_TOKEN' => 'linear-token',
        'LINEAR_TEAM_ID' => 'team-id',
        'LINEAR_PROJECT_ID' => 'project-id',
        'LINEAR_LABEL_IDS' => 'label-id',
        'TELEGRAM_BOT_TOKEN' => 'telegram-token',
        'TELEGRAM_CHANNEL_CHAT_ID' => '-100123',
    ] as $key => $value) {
        $repository->set($key, $value);
    }

    $config = require __DIR__.'/../../config/feedback-hub.php';

    expect($config['github']['token'])->toBe('github-token')
        ->and($config['github']['repo'])->toBe('uteq/example')
        ->and($config['linear']['token'])->toBe('linear-token')
        ->and($config['linear']['team_id'])->toBe('team-id')
        ->and($config['linear']['project_id'])->toBe('project-id')
        ->and($config['linear']['label_ids'])->toBe('label-id')
        ->and($config['telegram']['bot_token'])->toBe('telegram-token')
        ->and($config['telegram']['chat_id'])->toBe('-100123');
});
