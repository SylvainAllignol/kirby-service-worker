<?php
$panel_url = option('panel.slug', '/panel');

$options = option('sylvainallignol.service-worker');

$precache = array_map(function ($item) {
	if (is_array($item)) {
		if (kirby()->plugin('sylvainallignol/cache-stamp') && ($item['stamp'] ?? false) === true) {
			return cacheStamp($item['path']);
		}
		return $item['path'];
	}
	return $item;
}, $options['precache'] ?? []);

$precache = json_encode(
	$precache,
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
$version = $options['version'] ?? hash('xxh3', json_encode($options) . json_encode($precache));
$rules = json_encode(
	$options['rules'],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$offlineFallback = json_encode(
	$options['offlineFallback'],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$defaultStrategy = json_encode(
	$options['defaultStrategy'],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$offlineImage = json_encode(
	$options['offlineSVG'],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$debugEnabled = (bool)option('debug', false);
$debugFunction = $debugEnabled
	? <<<JS
/**
 * Debug utility function
 * 
 * @param  {...any} args - the arguments to log
 * @returns {void}
 */
function dbg(...args) {
	console.log('%c[SW]', 'color:#0aa;font-weight:bold', ...args);
}
JS
	: '';

/**
 * If the default strategy is set to "destruct", the service worker will unregister itself and reload all clients when activated.
 */
if ($defaultStrategy === '"destruct"') {
	echo
<<<JS
self.addEventListener('install', function(e) {
	self.skipWaiting();
});

self.addEventListener('activate', function(e) {
	e.waitUntil(
		self.registration.unregister()
			.then(function() {
				return self.clients.matchAll();
			})
			.then(function(clients) {
				clients.forEach(client => client.navigate(client.url))
			});
	);
});
JS;
	return;
}

$script =
<<<JS
const VERSION = '{$version}';
const PRECACHE = {$precache};
const RULES = {$rules};
const OFFLINE_FALLBACK = {$offlineFallback};
const OFFLINE_IMAGE = {$offlineImage};
const DEFAULT_STRATEGY = {$defaultStrategy};
const PANEL_URL = '{$panel_url}';

{$debugFunction}

/**
 * Utility function to check if a URL is cacheable (not an admin or API route)
 * 
 * @param {string} url - the URL to check
 * @returns {boolean} - true if the URL is cacheable, false otherwise
 */
function isCacheable(url) {
	const urlObj = new URL(url);
	const path = urlObj.pathname;
	return !path.startsWith('/' + PANEL_URL) && !path.startsWith('/api');
}

/**
 * Check if a request URL is part of the precache list
 *
 * @param {Request} request
 * @returns {boolean}
 */
function isInPrecache(request) {
	const path = new URL(request.url).pathname;
	return PRECACHE.includes(path);
}

/**
 * Utility function to populate the core cache with the precache assets
 * 
 * @returns {Promise} - a promise that resolves when all assets are cached
 */
function populateCoreCache() {
	return caches.open('core-' + VERSION).then(cache => cache.addAll(PRECACHE));
}

/**
 * Utility function to clear outdated caches that don't match the current version
 * 
 * @returns {Promise} - a promise that resolves when outdated caches are cleared
 */
function clearOutdatedCaches() {
	return caches.keys().then(cacheNames => {
		return Promise.all(
			cacheNames.filter(key => {
				return !key.endsWith(VERSION);
			}).map(key => {
				dbg('clearing outdated cache', key);
				return caches.delete(key);
			})
		);
	});
}

/**
 * Look up a request in the core (precache) first, then in the target cache.
 * This avoids duplicating precached assets in other caches.
 *
 * @param {Request} request - the request to look up
 * @param {Cache} targetCache - the runtime cache to fall back to
 * @returns {Promise<Response|undefined>}
 */
async function matchFromCaches(request, targetCache) {
	const cached = await targetCache.match(request, { ignoreVary: true });
	if (cached) return cached;

	const core = await caches.open('core-' + VERSION);
	return core.match(request, { ignoreVary: true });
}

/* ---------------------------
 * INSTALL
 --------------------------- */

self.addEventListener('install', (event) => {
	event.waitUntil(
		populateCoreCache().then(() => {
			dbg('install:precache-complete');
			return self.skipWaiting();
		})
	);
});

/* ---------------------------
 * ACTIVATE
 --------------------------- */

self.addEventListener('activate', event => {
	event.waitUntil(
		clearOutdatedCaches().then(() => {
			dbg('activate:done', { version: VERSION });
			self.clients.claim();
		})
	);
});

/* ---------------------------
 * MESSAGE
 --------------------------- */

const messageHandlers = {
	async GET_CACHED_PAGES(event) {
		const pages = await getCachedHtmlPages();
		postMessageResponse(event, { type: 'CACHED_PAGES', pages });
	}
};

function postMessageResponse(event, payload) {
	if (event.ports && event.ports[0]) {
		event.ports[0].postMessage(payload);
		return;
	}

	if (event.source && typeof event.source.postMessage === 'function') {
		event.source.postMessage(payload);
	}
}

self.addEventListener('message', event => {
	const messageType = event.data && event.data.type;
	const handler = messageType && messageHandlers[messageType];
	if (!handler) return;

	event.waitUntil(handler(event));
});

/* ---------------------------
 * FETCH
 --------------------------- */

self.addEventListener('fetch', event => {

	const {request} = event;

	dbg('REQUEST', request);

	if (request.method !== 'GET') return;

	const url = request.url;

	/**
	 * Ignore admin and API routes
	 */
	if(!isCacheable(url)) {
		dbg('fetch:ignored', url);
		return;
	}

	/**
	 * Check runtime caching rules
	 */
	for (const rule of RULES) {
		if (new RegExp(rule.urlPattern).test(url)) {
			dbg('fetch:rule-match', { url, strategy: rule.strategy, pattern: rule.urlPattern });
			const strategy = rule.strategy || DEFAULT_STRATEGY;
			return event.respondWith(strategies[strategy](request, rule.cacheName));
		}
	}

	/**
	 * Default strategy for navigation requests
	 */
	if (request.mode === 'navigate') {
		dbg('fetch:navigate', { url, strategy: DEFAULT_STRATEGY });
		event.respondWith(strategies[DEFAULT_STRATEGY](request, 'pages'));
		return;
	}

	// Ignore video and audio (range requests not supported)
	if (request.destination === 'video' || request.destination === 'audio'){
		event.respondWith(
			fetch(request).catch(() => new Response('', { status: 503, statusText: 'Offline' }))
		);
		return;
	}

	// Catch-all: any unmatched GET request
	event.respondWith(strategies.cacheFirst(request));

});

/* ---------------------------
 * STRATEGIES
 --------------------------- */

const strategies = {

	/**
	 * Cache First strategy:
	 * looks in cache, if not found goes to network and caches the result
	 */
	async cacheFirst(request, cacheName = 'site') {
		const cache = await caches.open(cacheName + '-' + VERSION);
		const cached = await matchFromCaches(request, cache);

		if (cached) {
			dbg('cacheFirst', { status: 'hit', url: request.url });
			return cached;
		}

		try {
			const res = await fetch(request);
			if (res.ok && !isInPrecache(request)) {
				cache.put(request, res.clone())
					.catch(err => {
						dbg('cacheFirst', { status: 'put-error', url: request.url, err });
					});
			}
			dbg('cacheFirst', { status: 'miss', url: request.url, status: res.status });
			return res;
		} catch (err) {
			dbg('cacheFirst', { status: 'offline', url: request.url, err });
			return offlineFallback(request);
		}
	},

	/**
	 * Network First strategy:
	 * goes to network, if fails looks in cache
	 */
	async networkFirst(request, cacheName = 'site') {
		const cache = await caches.open(cacheName + '-' + VERSION);

		try {
			const res = await fetch(request);
			if (res.ok && !isInPrecache(request)) {
				cache.put(request, res.clone())
					.catch(err => {
						dbg('networkFirst', { status: 'put-error', url: request.url, err });
					});
			}
			dbg('networkFirst', { status: 'network', url: request.url, status: res.status });
			return res;
		} catch (err) {
			dbg('networkFirst', { status: 'offline', url: request.url, err });
			const cached = await matchFromCaches(request, cache);
			if(cached) {
				dbg('networkFirst', { status: 'fallback-hit', url: request.url });
				return cached;
			}
			return offlineFallback(request);
		}
	},

	/**
	 * Stale While Revalidate strategy:
	 * returns cached response first if available, fetches network in background and updates cache
	 */
	async staleWhileRevalidate(request, cacheName = 'site') {
		const cache = await caches.open(cacheName + '-' + VERSION);
		const cached = await matchFromCaches(request, cache);

		// Launch network fetch in background (no await — intentional)
		const networkFetch = fetch(request)
			.then(res => {
				if (res.ok) cache.put(request, res.clone())
					.catch(err => {
						dbg('staleWhileRevalidate', { status: 'put-error', url: request.url, err });
					});
				dbg('staleWhileRevalidate', { status: 'revalidated', url: request.url, httpStatus: res.status });
				return res;
			})
			.catch(err => {
				dbg('staleWhileRevalidate', { status: 'offline', url: request.url, err });
				return null;
			});

		if (cached) {
			dbg('staleWhileRevalidate', { status: 'hit', url: request.url });
			return cached; // Return immediately, networkFetch runs in background
		}

		dbg('staleWhileRevalidate', { status: 'miss', url: request.url });
		return (await networkFetch) ?? offlineFallback(request);
	},

	/**
	 * Network Only strategy:
	 * goes to network, does not use cache
	 */
	async networkOnly(request) {
		try{
			return await fetch(request);
		} catch(err) {
			dbg('networkOnly', { status: 'offline', url: request.url, err });
			return offlineFallback(request);
		}
	}
};

/* ---------------------------
 * OFFLINE FALLBACK
 --------------------------- */

async function offlineFallback(request) {
	dbg('offline', { status: 'offline', mode: request.mode, destination: request.destination, url: request.url });
	if (request.mode === 'navigate' && OFFLINE_FALLBACK) {
		return caches.match(OFFLINE_FALLBACK);
	}
	if (request.destination === 'image') {
		return imageFallbackResponse();
	}
	return new Response('', { status: 503, statusText: 'Offline' });
}

/* ---------------------------
 * OFFLINE IMAGE FALLBACK
 --------------------------- */

function imageFallbackResponse() {
	return new Response(OFFLINE_IMAGE, {
		headers: { 'Content-Type': 'image/svg+xml' }
	});
}

/* ---------------------------
 * UTILITIES
 --------------------------- */

async function getCachedHtmlPages() {
	const cacheNames = await caches.keys();
	const versionSuffix = '-' + VERSION;
	const versionedCacheNames = cacheNames.filter(name => name.endsWith(versionSuffix));
	const orderedCaches = [
		...versionedCacheNames.filter(name => !name.startsWith('core-')),
		...versionedCacheNames.filter(name => name.startsWith('core-'))];

	const pagesSet = new Set();

	for (const cacheName of orderedCaches) {
		const cache = await caches.open(cacheName);
		const requests = await cache.keys();

		for (const request of requests) {
			const response = await cache.match(request);
			if (!response) continue;

			const contentType = response.headers.get('content-type') || '';
			if (!contentType.includes('text/html')) continue;

			const url = new URL(request.url);
			const pagePath = url.pathname;
			if (!pagePath) continue;
			if (OFFLINE_FALLBACK && pagePath === OFFLINE_FALLBACK) continue;

			pagesSet.add(pagePath);
		}
	}

	return Array.from(pagesSet);
}
JS;

// Strip debug calls from generated SW source when Kirby debug mode is disabled.
if (!$debugEnabled) {
	$lines = explode("\n", $script);
	$lines = array_filter($lines, static function (string $line): bool {
		return !str_contains($line, 'dbg(');
	});
	$script = implode("\n", $lines);
}

echo $script;
