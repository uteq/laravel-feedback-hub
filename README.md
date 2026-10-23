# Laravel Feedback Hub

Reusable Laravel feedback widget for UTEQ projects. It stores feedback locally, then creates GitHub and Linear issues and sends a Telegram notification.

## Install

Use a path repository while developing locally:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-feedback-hub",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "uteq/laravel-feedback-hub": "*"
    }
}
```

Then run:

```bash
composer update uteq/laravel-feedback-hub
php artisan feedback-hub:install
php artisan migrate
```

Add the inspector asset to `resources/js/app.js`:

```js
import './vendor/feedback-hub/feedback-inspector';
```

Add the widget inside an authenticated layout:

```blade
<x-feedback-hub::floating-button />
```

Pass `:floating="false"` to hide the round button and open the widget from your own control with `window.dispatchEvent(new CustomEvent('open-feedback-hub'))`.

## Configuration

Set these values in `.env`:

```dotenv
FEEDBACK_HUB_PROJECT="Project name"
FEEDBACK_HUB_GITHUB_TOKEN=
FEEDBACK_HUB_GITHUB_REPO=owner/repo
FEEDBACK_HUB_GITHUB_LABELS=feedback
FEEDBACK_HUB_GITHUB_ASSIGNEES=
FEEDBACK_HUB_LINEAR_TOKEN=
FEEDBACK_HUB_LINEAR_TEAM_ID=
FEEDBACK_HUB_LINEAR_PROJECT_ID=
FEEDBACK_HUB_LINEAR_LABEL_IDS=
FEEDBACK_HUB_TELEGRAM_BOT_TOKEN=
FEEDBACK_HUB_TELEGRAM_CHAT_ID=
```

Existing project env names also work as fallbacks: `GITHUB_API_TOKEN`, `GITHUB_TOKEN`, `GH_TOKEN`, `GITHUB_REPO`, `GITHUB_REPOSITORY`, `LINEAR_API_KEY`, `LINEAR_API_TOKEN`, `LINEAR_TOKEN`, `LINEAR_TEAM_ID`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHANNEL_CHAT_ID` and `TELEGRAM_CHAT_ID`.

For admin routes, publish the config and set middleware:

```php
'admin_middleware' => ['web', 'auth', 'role:admin'],
```

## Checks

```bash
php artisan feedback-hub:health
php artisan feedback-hub:health --live
php artisan feedback-hub:test-delivery
php artisan feedback-hub:test-delivery --send
php artisan feedback-hub:telegram-discover
php artisan feedback-hub:telegram-resolve @public_channel
php artisan feedback-hub:telegram-resolve --chat=-100123
php artisan feedback-hub:telegram-test "Feedback Hub test"
```

If discovery shows no channel, add the bot as channel admin, post once in the channel, or forward a channel post to the bot.

## Telegram channel setup

1. Create a dedicated Telegram channel or group for feedback notifications.
2. Add the bot from `FEEDBACK_HUB_TELEGRAM_BOT_TOKEN` as a member. For channels, make it an admin so it can post.
3. For public channels, run `php artisan feedback-hub:telegram-resolve @public_channel`.
4. For private channels or groups, post once or forward a post to the bot, then run `php artisan feedback-hub:telegram-discover`.
5. Set the returned ID as `FEEDBACK_HUB_TELEGRAM_CHAT_ID`.
6. Run `php artisan feedback-hub:health --live` and `php artisan feedback-hub:test-delivery --send`.

## Privacy

The browser sends field names, types and filled state only. Passwords, hidden fields, tokens, secrets and sensitive URL query values are excluded in the browser and filtered again on the server.

## AI intake chat

Install the optional AI SDK to use the intake chat backend:

```bash
composer require laravel/ai:^0.4
```

Set `FEEDBACK_HUB_AI_ENABLED=true` and `ANTHROPIC_API_KEY` in your environment.
Optionally configure `FEEDBACK_HUB_AI_PROVIDER` (default `anthropic`),
`FEEDBACK_HUB_AI_MODEL` (default `claude-haiku-4-5`) and
`FEEDBACK_HUB_AI_MAX_TURNS` (default `6`). Without a provider key, the chat
falls back to the form.

Set `FEEDBACK_HUB_AI_KEY` to use a dedicated API key for the feedback chat.
It uses the configured provider's settings without changing the app's provider
key. When empty, the chat continues to use the app's key (such as `ANTHROPIC_API_KEY`).

Authenticated clients can POST their message history and page URL to
`feedback-hub.chat`. The response contains a reply and, when ready, a structured
summary. Submit that summary with `intent` and `transcript` to `feedback-hub.store`.

When the chat is available, the widget opens in chat mode, shows the summary
for review and submits it with `intent` and `transcript`. Without the chat, or
when a chat turn fails, it shows the plain form.

### Reusing the widget logic

The Alpine behaviour lives in the view `feedback-hub::partials.widget-script`.
It renders one `<script>` per page that defines
`window.FeedbackHubWidget(config)`, a factory for the Alpine component object
(`config`: `endpoint`, `chatEndpoint`, `csrfEndpoint`, `chatEnabled`). A widget
with its own markup includes the partial, spreads the factory into its own
`Alpine.data` and overrides only the UI hooks:

```blade
@include('feedback-hub::partials.widget-script')

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('feedbackHubWidget', (config) => ({
            ...window.FeedbackHubWidget(config),
            openModal() { /* show your modal */ },
            closeModal() { /* hide your modal */ },
            notify(heading, text, variant) { /* show your toast */ },
        }));
    });
</script>
```

By default `openModal()` and `closeModal()` toggle a `showModal` boolean, and
`notify()` uses `window.Flux.toast` with a `feedback-hub:toast` window event as
fallback.
