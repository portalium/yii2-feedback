# portalium/yii2-feedback

In-app bug reports for a Portalium (Yii2) project. Signed-in users get a button; one click captures the page
and opens an annotation editor (arrows, boxes, pen, text, blur), then a short form. Reports are stored on the
server and mailed with the screenshot attached.

- The widget is [BugPin](https://github.com/aranticlabs/bugpin)'s MIT-licensed widget, built from source with
  `src/assets/bugpin/captureOnOpen.patch` (see `src/assets/bugpin/README.md`). BugPin's own server (AGPL) is not
  used: this package answers the two requests the widget makes.
- Each report carries the page URL, browser, device and viewport, the last console errors and failed network
  requests, and the annotated screenshot.
- Nothing is loaded while the key setting is empty.

## Installation

```bash
composer require portalium/yii2-feedback
```

1. Register the module in **both** `web/config/main.php` (adds the button) and `api/config/main.php`
   (the endpoints) → `'modules'`:
   ```php
   'feedback' => ['class' => 'portalium\feedback\Module'],
   ```
   Web module options: `position` (`bottom-right` default, `bottom-left`, `top-right`, `top-left`) and
   `launcherOffset` (px from that corner, e.g. `['right' => 32, 'bottom' => 88]` to sit above another floating
   button; default 20 px).
2. Settings:
   ```bash
   php portalium migrate --migrationPath=@vendor/portalium/yii2-feedback/src/migrations --interactive=0
   ```
3. Set the settings (Settings → Feedback):

| Setting | Value |
|---|---|
| `feedback::key` | 16-64 characters, letters, digits, `_` and `-` (e.g. `openssl rand -hex 16`). Empty = off. |
| `feedback::mail` | Addresses the reports are mailed to, separated by commas. Empty = no mail. |

The key is visible in the page source; that is how the widget works. Reports are limited to 10 per IP in
10 minutes (needs the `cache` component), at most 5 files of 10 MB each (PNG, JPEG, WebP, MP4, WebM, checked by
content).

## Endpoints

The widget builds its URLs from the site root, so the API application must answer on `/api/...`:

| Request | Answer |
|---|---|
| `GET /api/widget/config/<key>` | `{success, config}`: launcher and dialog settings, enabled features; 404 for another key |
| `POST /api/widget/submit` | header `x-api-key`, multipart `data` (JSON) + `media[]` files → `{success, reportId}`; 400/401/429 as `{success: false, error}` |

Reports are written to `@runtime/feedback/<id>/` (`report.json` + `media-<n>.<ext>`) of the API application.

## Screenshots

Captures are DOM renders (html-to-image), not pixels from the screen. The patched widget is tuned for large pages:
only the CSS properties that affect rendering are copied per node, CSS variables once on the root, off-screen
elements are skipped, fonts are inlined once. On a 6,000-node page this takes about 1.8 s instead of 7.5 s.
Video, `<canvas>` content and cross-origin images may come out empty.

## Testing

```bash
vendor/bin/phpunit vendor/portalium/yii2-feedback/tests
```
