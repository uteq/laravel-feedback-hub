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

## OS inbox

The optional OS inbox destination is off by default. Set both environment values to enable it:

```dotenv
FEEDBACK_HUB_OS_INBOX_URL=https://your-os.example/inbox-endpoint
FEEDBACK_HUB_OS_INBOX_KEY=
```

After Telegram, the package posts a version 1 multipart payload with the report summary,
intent, page URL and selector, reporter details, browser information, the last 30 console
errors and network requests, GitHub/Linear links, UUID, reference and creation time.
It attaches the screenshot when available and adds `video_url` when there is a screen recording. The transcript and form state are never sent.
The receiver deduplicates reports on `uuid`, including job retries.

A URL without a key logs a warning and skips delivery. HTTP 4xx responses log a warning
without failing the report; HTTP 5xx responses and connection errors use the job's retries.
The health command checks configuration only for this destination; use test delivery to
exercise the endpoint.

## Screen recording

When the browser can record (`getDisplayMedia` and `MediaRecorder`), the feedback button first asks
"Schermafbeelding" (point at an element, as before) or "Video opnemen". A video starts right away and
asks for this tab only where the browser supports it (`preferCurrentTab`, `displaySurface: 'browser'`,
`monitorTypeSurfaces: 'exclude'`). A small bar shows the time and a Stop button. The widget is wrapped in
`x-persist`, so with Livewire `wire:navigate` the recording keeps running across pages; a full page load
asks for confirmation first. Recording stops on Stop, when the browser stops sharing, or after the maximum
length. Firefox cannot share a single tab; it shares a window or a screen. There the recording is
cut to the page (`mozInnerScreenX/Y` against the window or screen geometry), so side panels and tab titles
stay out; a surface that matches neither stays uncut. The person watches the recording, can record again
or delete it, and sends it. The microphone is
optional: without permission the recording has no sound.

A long recording (say 30 minutes, set `FEEDBACK_HUB_VIDEO_MAX_SECONDS=1800` and raise
`FEEDBACK_HUB_VIDEO_MAX_KILOBYTES` to about 256000) is hundreds of chunks: the widget shows
"Video versturen 45%" and retries a chunk up to five times on a network error, 429 or 5xx.

The recording travels in raw chunks (`POST feedback-hub/video/chunks?offset=…`, default 512 KB), so
nginx's default 1 MB body limit and PHP's `upload_max_filesize` never apply. Chunks are staged per user in
`storage/app/feedback-hub/video-uploads`; the report moves the finished file to the storage disk after a
MIME check (WebM, MP4 or QuickTime). The admin detail plays it from `feedback-hub/{report}/video`
(Range requests supported). GitHub and Linear issues get a link, Telegram mentions it, and the OS inbox
payload gets `video_url` only when there is a recording. The file itself is never sent to a third party.

```dotenv
FEEDBACK_HUB_VIDEO_ENABLED=true
FEEDBACK_HUB_VIDEO_PATH=feedback-hub/videos
FEEDBACK_HUB_VIDEO_MAX_SECONDS=120
FEEDBACK_HUB_VIDEO_MAX_KILOBYTES=30720
FEEDBACK_HUB_VIDEO_CHUNK_KILOBYTES=512
```

Run `php artisan migrate` after updating: version 0.6 adds the `video_*` columns.

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
`notify()` uses `window.Flux.toast` when present. Otherwise it dispatches the
cancelable `feedback-hub:toast` window event, followed by `mortel-toast` unless
the first event was canceled. A host showing its own toast adds `.prevent`,
for example `x-on:feedback-hub:toast.window.prevent="..."`.

## Upgrading to 0.5

Screenshots now draw the page without asking for browser permission. A host with
a published copy keeps the old script (and its browser prompt) until it runs:

```bash
php artisan vendor:publish --tag=feedback-hub-assets --force
```

This publishes both the inspector and `snapdom.mjs` beside it. Alternatively,
import the package script directly, as uteq/os does:

```js
import '../../vendor/uteq/laravel-feedback-hub/resources/js/feedback-inspector.js';
```

A host handling `feedback-hub:toast` itself adds `.prevent` to its Alpine listener
to avoid a second toast from `mortel-toast`.

Only sensitive input fields are blanked in screenshots; text on the page is not;
cross-origin iframes and images without CORS remain empty.

### Optional follow-up window (0.7)

The default widget retains the title, type picker, optional intake chat and close-on-send flow.
Enable the simpler, single-field form and the panel after sending explicitly:

```blade
<x-feedback-hub::floating-button
    :follow-up-url="route('feedback.follow-up')"
    :follow-up-wait-ms="3000"
    context-label="Delivery planning"
    :my-feedback-url="route('feedback.mine')"
    :my-feedback-badge="2"
/>
```

All new props are optional. `followUpUrl` defaults to null; `followUpWaitMs` to 3000.
`contextLabel` defaults to the selected element's label plus the document title.
`myFeedbackUrl` adds “Mijn feedback” to the choice menu; `myFeedbackBadge` (integer,
default 0) shows a count when positive, including on the floating button.

With `followUpUrl`, description is required unless a video is attached. The submitted
type is `bug`; title is the first 80 characters of the description, or
`Video-feedback`. Existing server validation still requires title. Closing before
sending preserves the draft during this page session. The trash control discards
the text with an undo action.

The app owns and authenticates the follow-up endpoints. The package adds no routes,
AI calls or configuration keys. After the report endpoint returns HTTP 201 with
`id` (the report UUID), the widget sends JSON `{"report":"<uuid>"}` to
`followUpUrl`, using the same CSRF/session handling as report submission.
Respond with:

```json
{
  "summary": {
    "title": "Delivery time",
    "saw": "Only a date",
    "wants": "Also the time",
    "where": "Delivery planning"
  },
  "questions": [
    {
      "id": "audience",
      "topic": "Who",
      "question": "Who needs to see this?",
      "hint": null,
      "options": ["Everyone", "Only me", "My team"]
    }
  ],
  "edit_url": "/my-feedback/report-uuid",
  "later": false
}
```

`summary` and `edit_url` may be null. Supply zero to two questions, each with
three string options. Text is rendered as text, never HTML; edit links accept
only HTTP(S). The optional boolean `later` defaults to false. HTTP 202 or
`later: true` means follow-up will come via the inbox: the widget finishes with
the inbox notice, preserves any supplied summary and skips immediate questions.
This is an accepted result, not an error. For example, return HTTP 202 with
`{"later":true}`, optionally including `summary` and `edit_url`.
A timeout or failure also finishes immediately with an inbox notice;
the app must arrange any later inbox question. No polling occurs. Closing while
waiting does not cancel the already saved report.

Each answer is a separate JSON POST to `followUpUrl + '/answers'`:
`{"report":"<uuid>","question_id":"audience","answer":"Everyone","skipped":false}`.
A skip sends `answer:null, skipped:true`. Free text is limited to 500 characters.
The next question appears after 250 ms even when saving the answer fails; the
app should retain unanswered questions in its inbox. Authorize report ownership
and validate question IDs and answers in the app.

### Recording activity (0.7 migration required)

Publish the package migrations again and run migrations in every application
database (including tenant databases where applicable):

```sh
php artisan vendor:publish --tag=feedback-hub-migrations
php artisan migrate
```

The new migration adds nullable `activity` (JSON, array cast) and
`recording_seconds` (decimal seconds). Recording uploads submit up to 500 entries:

```json
{"t":1.2,"type":"click","label":"Save","selector":"#save","path":"/deliveries"}
```

Each new recording begins with a `start` entry:
`{"t":0,"type":"start","label":"Page title","selector":"","path":"/deliveries"}`.
It preserves the starting page even when navigation (including Back) happens
before the first click. Its label is the redacted document title, limited to
60 characters. A `start` entry is optional for compatibility with older clients;
when supplied, it must be first and may occur at most once. It counts toward the
500-entry limit.

Other types are `click` and `navigate` (history changes and Livewire navigation).
Times are seconds since recording started, rounded to one decimal. Labels use
control text, aria-label or a field label, never field values (max 60 characters).
Selectors are limited to 200 characters and paths to 500, without query or hash.
Clicks inside the widget and recording controls are ignored. The server validates
limits, removes query/hash from paths and sanitizes strings. Unknown activity keys
are rejected. Activity is stored separately from `session_data` and shown on the
admin detail page; it is never added to the GitHub issue body.

The follow-up URL **must be same-origin** (same scheme, host and port as the
current page). The widget rejects external POST endpoints before fetching;
CSRF headers and retries are only used on the current origin. This does not
change the follow-up JSON contract.

The component emits page-local JSON outside its persisted wrapper, so
`contextLabel`, `myFeedbackBadge` and `myFeedbackUrl` refresh on
`livewire:navigated`. Hosts may update any subset without navigating:

```js
window.dispatchEvent(new CustomEvent('feedback-hub:update', {
    detail: { contextLabel: 'Delivery planning', myFeedbackBadge: 3, myFeedbackUrl: '/my-feedback' }
}));
```

A retained draft is offered as “Verder met je tekst” in the choice menu, alongside
new screenshot/video and My feedback. Its original URL, page title, context label,
element label and viewport stay with it across navigation; updates apply to new
feedback. Choosing a new screenshot or recording starts a new draft. Closing
during submission/upload does not cancel delivery: success is announced by toast
and follow-up runs without reopening the window.
