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

## Privacy

The browser sends field names, types and filled state only. Passwords, hidden fields, tokens, secrets and sensitive URL query values are excluded in the browser and filtered again on the server.
