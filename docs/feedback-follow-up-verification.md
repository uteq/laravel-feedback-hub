# Feedback follow-up implementation verification · review round 1

Branch: feat/feedback-follow-up. New commit after 9f06037, no amend, push, tag
or pull request. The README follow-up and answers JSON contracts are unchanged.

## Review fixes

1. Activity/duration migration tolerates repeat up/down and a partially present
   schema; repeat-run, preservation and partial-schema tests follow the intent
   migration tests.
2. Drafts pin their original URL, title, label, element label and viewport across
   Livewire navigation. The menu stays available, with “Verder met je tekst”.
   Choosing a new screenshot or recording intentionally starts a fresh draft.
3. Closing during report POST or video upload preserves delivery, announces
   successful delivery and starts follow-up silently. Reopening while sending
   returns to the existing submission, preventing another send.
4. Page-local JSON outside x-persist updates contextLabel, myFeedbackBadge and
   myFeedbackUrl on livewire:navigated. feedback-hub:update accepts partial
   updates; existing draft context stays pinned.
5. Retained history wrappers have an active flag, so wrappers held by another
   script cannot keep logging after recording stops.
6. Click labels use only interactive control textContent, normalized and capped
   at 60 characters. Non-interactive clicks have an empty label and retain the
   selector. Provisional titles collapse whitespace.
7. External POST endpoints are rejected before fetch; no CSRF header is sent
   outside the current origin. README documents same-origin follow-up URLs.
8. Extra browser coverage exercises a genuinely late response, follow-up 419
   retry, report/question IDs in both request bodies, Escape during a question,
   both prop-update paths, original draft context, all closing methods during
   POST, closing during chunk upload and retained history wrappers.
9. Admin activity output escapes HTML and tolerates missing keys.
10. Visual fixes include the exact video subtitle; selected-element outline;
    screenshot aim/trash controls; paperclip/context; plane and spinner in send;
    focus ring; pulsing waiting dots; white 46px answer rows and selected colour;
    pencil link; video processing line; inbox icon; and the red warning block.
    Screenshot bounds are recalculated after layout and resizing.

## Visual verification

22 fresh actual-component captures at 1440×900 and 390×844, exported to the
paths below. The screenshot pass checks the outline width in all screenshot
form states, focus in editable states, the second question's count and text,
and the video-specific completion line. Question 1 and 2 narrow images have
different content and hashes. The video fixture is a real MediaRecorder blob;
the completion state retains its video URL and sentVideo flag. Separate browser
tests submit an actual recording through the upload/report/follow-up flow.

Remaining deliberate differences from the prototype:

- The existing widget fonts/weights remain, as explicitly allowed in review.
- The surrounding page, media and summary are synthetic fixture content,
  rather than Sijperda customer data. The screenshot image keeps the existing
  viewport containment; its selected element has the requested 2px teal outline.
- The existing native video player and visible re-record/remove actions remain;
  replacing them with the prototype's simulated media controls would change
  the existing video interaction beyond these review requests.
- Static PNGs capture one frame of the real waiting/spinner animations.
  No remaining requested form words or controls are intentionally omitted.

## Files changed in review round 1

- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/README.md
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/database/migrations/2026_11_20_000001_add_activity_to_feedback_hub_reports_table.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/docs/feedback-follow-up-verification.md
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/resources/views/admin/show.blade.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/resources/views/components/floating-button.blade.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/resources/views/partials/widget-script.blade.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/tests/Browser/FeedbackFollowUpTest.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/tests/Browser/FeedbackVideoTest.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/tests/Feature/AdminFeedbackReportTest.php
- /home/jesse/worktrees/laravel-feedback-hub-feat-feedback-follow-up/tests/Feature/FeedbackActivityMigrationTest.php


## Actual verification output

Command: composer test -- --display-skipped

```text
Tests:    3 skipped, 189 passed (899 assertions)
Duration: 37.91s
```

The three skipped tests are existing optional stancl integration tests:
“Optional stancl integration requires the stancl provider.”

Command: vendor/bin/pint --test

```text
{"tool":"pint","result":"passed"}
```

Command: FEEDBACK_SCREENSHOTS=1 vendor/bin/pest tests/Browser/FeedbackFollowUpTest.php

```text
✓ it lets the user re-point and remove a screenshot while retaining t… 0.49s
Tests:    18 passed (127 assertions)
Duration: 19.08s
```

git diff --check: exit 0.

## Screenshot artifacts

- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-fout-versturen-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-fout-versturen-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-keuze-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-keuze-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-video-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-video-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-zonder-vragen-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-klaar-zonder-vragen-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-versturen-bezig-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-versturen-bezig-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vertellen-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vertellen-video-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vertellen-video-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vertellen-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vraag-1-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vraag-1-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vraag-2-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-vraag-2-wide.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-wachten-op-vragen-narrow.png
- /home/jesse/worktrees/sijperda-os-feedback-fase1-ontwerp/shots/build-t1-wachten-op-vragen-wide.png
