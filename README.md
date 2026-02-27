# Kirby Service Worker

Service worker caching plugin for Kirby CMS focused on cache behavior and strategy tuning.

It is designed for site performance (faster repeat visits, reduced network usage, and better resilience offline).

## Features

- Registers a service worker at `/sw.js`
- Optional registration script at `/sw-register.js` or inline
- Runtime caching strategies:
  - `cacheFirst`
  - `networkFirst`
  - `staleWhileRevalidate`
  - `networkOnly`
- Rule-based caching by URL pattern
- Optional precache list
- Optional offline page fallback
- Cached pages retrieval via `postMessage` (`GET_CACHED_PAGES`)
- Built-in offline SVG fallback for images
- Optional debug logs in the browser console (`option('debug')`)

## Requirements

- PHP `>= 8.2`
- Kirby `^3.0 || ^4.0 || ^5.0`

## Installation

### Composer

```bash
composer require sylvainallignol/service-worker
```

### Manual (ZIP)

1. Download the latest release ZIP from GitHub
2. Unzip into `site/plugins/kirby-service-worker`

## Quick start

1. Install the plugin.
2. Register `/sw.js` in your frontend. You can either add the provided snippet in your layout (usually before `</body>`):

```php
<?php snippet('sw-register') ?>
```

Or use your own JavaScript registration code.

3. Optionally configure plugin options in `site/config/config.php`.

## Configuration

Use the `sylvainallignol.service-worker` option key:

```php
<?php
return [
  'sylvainallignol.service-worker' => [
    'enabled' => true,
    'version' => null,
    'registerType' => 'external',
    'precache' => [
      '/',
      '/assets/css/app.css',
      [
        'path' => '/assets/js/app.js',
        'stamp' => true,
      ],
    ],
    'offlineFallback' => '/offline',
    'offlineSVG' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">...</svg>',
    'defaultStrategy' => 'networkFirst',
    'rules' => [
      [
        'urlPattern' => '\\.(png|jpg|jpeg|svg|webp|avif|woff|woff2|eot|ttf|otf)$',
        'strategy' => 'cacheFirst',
        'cacheName' => 'images',
      ],
      [
        'urlPattern' => '\\.(js|css)$',
        'strategy' => 'cacheFirst',
        'cacheName' => 'assets',
      ],
    ],
  ],
];
```

### Options

- `enabled` (`bool`): enable/disable the plugin.
- `version` (`string|null`): cache version suffix. If `null`, a hash based on options is generated.
- `registerType` (`external|inline`): registration script output mode for `snippet('sw-register')`.
- `precache` (`array`): list of assets to cache at install time. Supported item formats:
  - `string`: direct URL/path (example: `'/assets/css/app.css'`).
  - `array`: `['path' => '/assets/js/app.js', 'stamp' => true|false]`.
    - `path` is required.
    - `stamp` is optional; see [Kirby Cache Stamp integration](#kirby-cache-stamp-integration) below.
- `offlineFallback` (`string|null`): path to a cached offline page used for navigation requests when offline.
- `offlineSVG` (`string`): SVG returned as fallback for offline image requests.
- `defaultStrategy` (`cacheFirst|networkFirst|staleWhileRevalidate|networkOnly|destruct`): default fetch strategy.
- `rules` (`array`): runtime caching rules with `urlPattern`, `strategy`, and optional `cacheName`.

### Offline page recommendation

It is strongly recommended to create a dedicated offline page (for example `/offline`) and set it as `offlineFallback`.

When a navigation request fails because the user is offline, the service worker returns this page instead of an empty/error response. This gives users a clear message, keeps your UX consistent, and can provide useful fallback content such as key links, contact info, or retry actions.

### Retrieving cached pages via message

The service worker can return the list of cached navigation pages through `postMessage`.

- Send `{ type: 'GET_CACHED_PAGES' }` to the active service worker.
- Listen for `{ type: 'CACHED_PAGES', pages: [...] }` in the page, where `pages` is an array of URL strings.

This is especially useful on an offline page to display quick links to already cached content.

Example frontend script:

```js
(() => {
  // URLs to pin at the top of the list
  const PRIORITY = [
    "/",
    "/about",
    "/blog",
    "/contact"
  ];

  // Ask the active service worker for cached page URLs
  navigator.serviceWorker.controller.postMessage({ type: "GET_CACHED_PAGES" });

  // Receive messages from the service worker
  navigator.serviceWorker.addEventListener("message", async (event) => {
    if (event.data.type !== "CACHED_PAGES") return;

    // Sort URLs for a better offline navigation list
    const sortedUrls = [...event.data.pages].sort((a, b) => {

      // Priority pages first, using PRIORITY order
      const aPriority = PRIORITY.indexOf(a);
      const bPriority = PRIORITY.indexOf(b);

      if (aPriority !== -1 && bPriority !== -1) {
        return aPriority - bPriority; // Keep PRIORITY order
      }
      if (aPriority !== -1) return -1;
      if (bPriority !== -1) return 1;

      // Parent paths before child paths
      if (b.startsWith(a + "/")) return -1;
      if (a.startsWith(b + "/")) return 1;

      // Then alphabetical order
      return a.localeCompare(b, "fr", { sensitivity: "base" });
    });

    const pages = await Promise.all(
      sortedUrls.map(async (url) => {
        // Fetch cached HTML and extract a few display fields
        const res = await fetch(url);
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, "text/html");
        return {
          url,
          title: doc.querySelector("h1")?.textContent?.trim(),
          description: doc.querySelector('meta[name="description"]')?.content?.trim(),
        };
      }),
    );

    // Render links into <ul id="cached-pages"></ul>
    const list = document.querySelector("#cached-pages");
    list.innerHTML = pages
      .map(
        ({ url, title, description }) => `<li><a href="${url}"><strong>${title}</strong>${description ? `<br><small>${description}</small>` : ""}</a></li>`
      )
      .join("");
  });
})();
```

## Caching strategies and default behavior

### Strategy meaning

- `cacheFirst`: serve from cache first, then fetch from network and cache the response if missing.
- `networkFirst`: try network first, fallback to cache if offline/unreachable.
- `staleWhileRevalidate`: return cached response immediately when available, and refresh it in background.
- `networkOnly`: always fetch from network, no caching.

### Default rules

With default config, runtime behavior is:

- **Images/fonts** (`png`, `jpg`, `jpeg`, `svg`, `webp`, `avif`, `woff`, `woff2`, `eot`, `ttf`, `otf`)
  - strategy: `cacheFirst`
  - cache name: `images`
- **Scripts/styles** (`js`, `css`)
  - strategy: `cacheFirst`
  - cache name: `assets`
- **Navigation requests** (HTML pages)
  - strategy: `networkFirst` (from `defaultStrategy`)
  - cache name: `pages`

Any other unmatched `GET` request falls back to `cacheFirst`.

### Default cache names

All runtime cache names are versioned with `-<VERSION>`:

- Precache/core assets: `core-<VERSION>`
- Scripts/CSS cache: `assets-<VERSION>`
- Images/fonts cache: `images-<VERSION>`
- Navigation/pages cache: `pages-<VERSION>`
- Fallback default cache name (when no `cacheName` is provided): `site-<VERSION>`

## Kirby Cache Stamp integration

If you also use my [Kirby Cache Stamp plugin](https://github.com/SylvainAllignol/kirby-cache-stamp), you can set stamped precache entries like this:

```php
'precache' => [
  [
    'path' => '/assets/js/app.js',
    'stamp' => true,
  ],
],
```

When `stamp` is `true`, this plugin calls `cacheStamp()` (from Kirby Cache Stamp) for that file path.

This means the stamped URL changes whenever the file changes (cache-busting), which changes the generated service worker source/config hash. As a result, cache names are versioned again, old caches are cleaned up, and the browser detects a new worker file and reinstalls/updates the service worker automatically after each stamped file change.

## Special mode: `destruct`

Set `defaultStrategy` to `destruct` to unregister the service worker and force clients to reload on activation.

## Notes

- Admin (`/panel`) and `/api` requests are never cached.
- Non-GET requests are ignored by the service worker.
- Video/audio requests are not cached by default to avoid filling caches with large media files, but you can add a custom `rules` entry if you want to cache specific media URLs.

## License

[MIT](https://opensource.org/licenses/MIT)

## Credits

- [Sylvain Allignol](https://sylvainallignol.com)