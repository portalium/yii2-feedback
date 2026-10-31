# BugPin widget

`widget.js` is built from the source of `@arantic/bugpin-widget` 1.1.5 (MIT, see `LICENSE`;
https://github.com/aranticlabs/bugpin, `src/widget` + `src/shared`) with `captureOnOpen.patch` applied:

- `captureOnOpen` option: the launcher captures the page at once and opens the annotation editor; after
  saving, the form opens with the screenshot attached. Without the option the widget works as before.
- The media files are sent as `media[]` instead of `media`, because PHP keeps only the last file of repeated
  `media` fields.
- Faster 'visible' captures: off-screen absolute/fixed elements are skipped, in-flow elements below or right
  of the viewport are kept as empty boxes; the inlined font CSS is built once after start and reused;
  `capturePixelRatio` option (Suite passes 1). `launcherOffset` option: launcher distance from its corner in px.
  On a 9,000-node test page: 6.4 s → 1.5 s.
- Page API: `BugPin.capture()` captures and opens the editor (as the launcher does); `BugPin.hideLauncher()`
  hides the launcher for pages that open the widget from their own control.
- Attributes with names XML cannot hold (a stray `."` in a template) are dropped from the clone; one of them made
  the SVG invalid and the capture failed.
- Small screens: the annotation area is no longer at least 700 px wide (the image was placed off screen); the
  launcher is hidden while the editor or the form is open.
- Only the CSS properties in `CAPTURE_STYLE_PROPERTIES` are copied per node (html-to-image copies all ~675,
  CSS variables included, into a 100 MB SVG); the CSS variables are set once on the capture root. On a Suite
  flow page: 7.5 s → 1.8 s, the image differs from the full copy in under 0.06 % of pixels (dark and light).

Rebuild (Node 20.19+):

```bash
cp -R <bugpin>/src/widget <bugpin>/src/shared build/src/
cd build/src/widget && patch -p1 < captureOnOpen.patch
npm install --ignore-scripts && npx vite build   # dist/widget.js
```

The receiving endpoints live in `controllers/api/WidgetController.php`; `bugpin.js` starts the widget.
