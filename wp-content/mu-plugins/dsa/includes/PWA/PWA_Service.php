<?php

namespace DSA\PWA;

use DSA\Notifications\Push_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PWA_Service {
	private $settings;
	private $push;

	public function __construct( Settings $settings, ?Push_Service $push = null ) {
		$this->settings = $settings;
		$this->push = $push;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'serve_endpoint' ], 0 );
		add_action( 'wp_head', [ $this, 'print_head_tags' ], 2 );
	}

	public function public_config(): array {
		$push = $this->push ? $this->push->public_config() : [ 'enabled' => false, 'publicKey' => '' ];
		$permissions = $this->settings->get( 'permissions', [] );
		return [
			'enabled'          => $this->enabled(),
			'manifestUrl'      => $this->manifest_url(),
			'serviceWorkerEnabled' => $this->service_worker_enabled(),
			'serviceWorkerUrl' => $this->service_worker_enabled() ? $this->service_worker_url() : '',
			'scope'            => $this->scope_url(),
			'siteIconReady'    => (bool) get_site_icon_url( 512 ),
			'iosHelp'          => __( 'On iPhone or iPad, tap Share, then Add to Home Screen, then Add.', 'dsa' ),
			'androidHelp'      => __( 'Open the browser menu and choose Install app or Add to Home screen.', 'dsa' ),
			'pushEnabled'      => ! empty( $push['enabled'] ),
			'vapidPublicKey'   => (string) ( $push['publicKey'] ?? '' ),
			'vapidKeyId'       => (string) ( $push['keyId'] ?? '' ),
			'offlineEditorialEnabled' => is_array( $permissions ) && ! empty( $permissions['offline_editorial_enabled'] ),
		];
	}

	public function print_head_tags(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$icon = $this->app_icon_url( 180 );
		?>
		<link rel="manifest" href="<?php echo esc_url( $this->manifest_url() ); ?>">
		<meta name="theme-color" content="<?php echo esc_attr( $this->theme_color() ); ?>">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="default">
		<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $this->short_name() ); ?>">
		<?php if ( $icon ) : ?>
			<link rel="apple-touch-icon" href="<?php echo esc_url( $icon ); ?>">
		<?php endif; ?>
		<?php
	}

	public function serve_endpoint(): void {
		if ( isset( $_GET['dsa_pwa_manifest'] ) ) {
			$this->serve_manifest();
		}

		if ( isset( $_GET['dsa_service_worker'] ) ) {
			$this->serve_service_worker();
		}
	}

	private function serve_manifest(): void {
		if ( ! $this->enabled() ) {
			status_header( 404 );
			exit;
		}

		$icons = [];
		foreach ( [ 192, 512 ] as $size ) {
			$url = $this->app_icon_url( $size );
			if ( $url ) {
				$icons[] = [
					'src'   => esc_url_raw( $url ),
					'sizes' => $size . 'x' . $size,
					'type'  => $this->icon_mime_type( $url ),
				];
			}
		}

		$manifest = [
			'id'               => $this->scope_url(),
			'name'             => wp_strip_all_tags( get_bloginfo( 'name' ) ?: __( 'Kiwe Appsite', 'dsa' ) ),
			'short_name'       => $this->short_name(),
			'description'      => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'lang'             => str_replace( '_', '-', get_locale() ),
			'start_url'        => add_query_arg( 'dsa_pwa', '1', home_url( '/' ) ),
			'scope'            => $this->scope_url(),
			'display'          => 'standalone',
			'display_override' => [ 'window-controls-overlay', 'standalone', 'minimal-ui' ],
			'orientation'      => 'any',
			'background_color' => '#f2f4f6',
			'theme_color'      => $this->theme_color(),
			'icons'            => $icons,
		];

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function serve_service_worker(): void {
		if ( ! $this->service_worker_enabled() ) {
			status_header( 404 );
			exit;
		}

		$permissions = $this->settings->get( 'permissions', [] );
		$config = wp_json_encode(
			[
				'version'        => DSA_VERSION,
				'cache'          => 'kiwe-appsite-' . preg_replace( '/[^a-zA-Z0-9._-]/', '-', DSA_VERSION ),
				'editorialCache' => 'kiwe-editorial-v1-' . preg_replace( '/[^a-zA-Z0-9._-]/', '-', DSA_VERSION ),
				'mediaCache'     => 'kiwe-editorial-media-v1-' . preg_replace( '/[^a-zA-Z0-9._-]/', '-', DSA_VERSION ),
				'pushMetaCache'  => 'kiwe-push-meta-v1',
				'editorialFreshSeconds' => 15 * MINUTE_IN_SECONDS,
				'home'           => home_url( '/' ),
				'icon'           => $this->app_icon_url( 192 ),
				'badge'          => $this->app_icon_url( 96 ),
				'theme'          => $this->theme_color(),
				'name'           => wp_strip_all_tags( get_bloginfo( 'name' ) ?: __( 'Kiwe Appsite', 'dsa' ) ),
				'offlineMessage' => __( 'You are offline. Reconnect to continue with the latest site content.', 'dsa' ),
				'pushRest'       => rest_url( 'dsa/v1/push/subscription' ),
				'offlineRest'    => rest_url( 'dsa/v1/offline-editorial' ),
				'offlineEditorialEnabled' => is_array( $permissions ) && ! empty( $permissions['offline_editorial_enabled'] ),
				'assets'         => [
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/css/surface.css' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/surface.js' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/ai-island.js' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/app-island.js' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/data-island.js' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/games-engine.js' ),
					add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/profile-panel.js' ),
				],
			]
		);

		status_header( 200 );
		nocache_headers();
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'X-Content-Type-Options: nosniff' );
		?>
const KIWE_PWA = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
self.addEventListener('install', function (event) {
	event.waitUntil(caches.open(KIWE_PWA.cache).then(function (cache) {
		return Promise.all((KIWE_PWA.assets || []).map(function (asset) {
			return cache.add(asset).catch(function () { return null; });
		}));
	}).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (event) {
	event.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (key) {
			return (key.indexOf('kiwe-appsite-') === 0 && key !== KIWE_PWA.cache)
				|| (key.indexOf('kiwe-editorial-v1-') === 0 && (!KIWE_PWA.offlineEditorialEnabled || key !== KIWE_PWA.editorialCache))
				|| (key.indexOf('kiwe-editorial-media-v1-') === 0 && (!KIWE_PWA.offlineEditorialEnabled || key !== KIWE_PWA.mediaCache));
		}).map(function (key) { return caches.delete(key); }));
	}).then(function () { return self.clients.claim(); }));
});
self.addEventListener('message', function (event) {
	if (event.data && event.data.type === 'KIWE_SKIP_WAITING') self.skipWaiting();
	if (event.data && event.data.type === 'KIWE_PUSH_RENEWAL') {
		event.waitUntil(storePushRenewal(event.data));
	}
	if (event.data && event.data.type === 'KIWE_PUSH_RENEWAL_CLEAR') {
		event.waitUntil(caches.open(KIWE_PWA.pushMetaCache).then(function (cache) { return cache.delete(self.location.origin + '/__kiwe_push_renewal__'); }));
	}
});
self.addEventListener('fetch', function (event) {
	const request = event.request;
	if (!request || request.method !== 'GET') return;
	if (isRuntimeFreshUrl(request.url)) {
		event.respondWith(fetch(request, {cache:'no-store', credentials:'same-origin'}).catch(function () {
			return caches.match(request);
		}));
		return;
	}
	if (request.mode === 'navigate') {
		const navigation = fetch(request);
		event.respondWith(navigation.catch(function () {
			return offlineEditorialResponse(request.url).then(function (response) { return response || offlineShellResponse(); });
		}));
		event.waitUntil(navigation.then(function (response) {
			return response && response.ok && !isNetworkOnlyUrl(request.url) ? cacheEditorial(request.url, request) : false;
		}).catch(function () { return false; }));
		return;
	}
	if (isAppAsset(request.url)) {
		event.respondWith(caches.match(request).then(function (cached) {
			return cached || fetch(request).then(function (response) {
				if (response && response.ok) caches.open(KIWE_PWA.cache).then(function (cache) { cache.put(request, response.clone()); });
				return response;
			});
		}));
	}
});
function isRuntimeFreshUrl(value) {
	let url;
	try { url = new URL(value); } catch (error) { return true; }
	if (url.origin !== self.location.origin) return false;
	if (/\/wp-json\/dsa(?:\/|$)/i.test(url.pathname) || url.searchParams.get('rest_route') && /^\/dsa\//i.test(url.searchParams.get('rest_route') || '')) return true;
	if (/\/wp-content\/mu-plugins\/dsa\//i.test(url.pathname)) return true;
	return /[?&]dsa_(?:runtime|debug|health|rt|manifest|service_worker|pwa_manifest)=/i.test(url.search);
}
function isNetworkOnlyUrl(value) {
	let url;
	try { url = new URL(value); } catch (error) { return true; }
	if (url.origin !== self.location.origin || url.search) return true;
	return /\/(?:wp-admin|wp-json|wp-login\.php|cart|checkout|my-account|order-pay|order-received|wc-api)(?:\/|$)/i.test(url.pathname);
}
function isAppAsset(value) {
	let candidate;
	try { candidate = new URL(value); } catch (error) { return false; }
	if (candidate.origin !== self.location.origin) return false;
	return (KIWE_PWA.assets || []).some(function (asset) {
		let expected;
		try { expected = new URL(asset); } catch (error) { return false; }
		if (candidate.pathname !== expected.pathname) return false;
		const version = expected.searchParams.get('ver');
		return !version || candidate.searchParams.get('ver') === version;
	});
}
function saveDataRequested(request) {
	return (request && request.headers && request.headers.get('Save-Data') === 'on')
		|| (self.navigator && self.navigator.connection && self.navigator.connection.saveData === true);
}
function offlineContractUrl(value) {
	const endpoint = new URL(KIWE_PWA.offlineRest);
	endpoint.searchParams.set('url', value);
	return endpoint.href;
}
function trimCache(cacheName, maximum) {
	return caches.open(cacheName).then(function (cache) {
		return cache.keys().then(function (keys) {
			return Promise.all(keys.slice(0, Math.max(0, keys.length - maximum)).map(function (key) { return cache.delete(key); }));
		});
	});
}
function cacheEditorial(value, request) {
	if (!KIWE_PWA.offlineEditorialEnabled || !KIWE_PWA.offlineRest || isNetworkOnlyUrl(value) || saveDataRequested(request)) return Promise.resolve(false);
	const contractUrl = offlineContractUrl(value);
	return caches.open(KIWE_PWA.editorialCache).then(function (cache) {
		return cache.match(contractUrl).then(function (cached) {
			const cachedAt = cached ? Number(cached.headers.get('X-Kiwe-Cached-At') || 0) || Date.parse(cached.headers.get('Date') || '') : 0;
			if (cachedAt && Date.now() - cachedAt < (KIWE_PWA.editorialFreshSeconds || 900) * 1000) return false;
			return fetch(contractUrl, {credentials:'same-origin', cache:'no-store', headers:{'X-Kiwe-Offline':'public-editorial-v1'}});
		});
	}).then(function (response) {
		if (!response) return false;
		const policy = response.headers.get('X-Kiwe-Offline-Policy') || '';
		const control = response.headers.get('Cache-Control') || '';
		if (!response.ok || policy !== 'public-editorial-v1' || /(?:private|no-store)/i.test(control)) return false;
		return response.clone().json().then(function (payload) {
			if (!payload || !payload.offlineReady || !payload.content || !payload.content.html) return false;
			return response.blob().then(function (blob) {
				const headers = new Headers(response.headers);
				headers.set('X-Kiwe-Cached-At', String(Date.now()));
				const stored = new Response(blob, {status:response.status,statusText:response.statusText,headers:headers});
				return caches.open(KIWE_PWA.editorialCache).then(function (cache) {
				return cache.put(contractUrl, stored).then(function () {
					const media = Array.isArray(payload.media) ? payload.media.slice(0, 8) : [];
					return Promise.all(media.map(cacheEditorialMedia)).then(function () { return trimCache(KIWE_PWA.editorialCache, 30); });
				});
				});
			});
		});
	}).catch(function () { return false; });
}
function cacheEditorialMedia(hint) {
	if (!hint || !hint.url) return Promise.resolve(false);
	let url;
	try { url = new URL(hint.url); } catch (error) { return Promise.resolve(false); }
	if (url.origin !== self.location.origin) return Promise.resolve(false);
	if (self.navigator && self.navigator.connection && self.navigator.connection.saveData === true) return Promise.resolve(false);
	return fetch(url.href, {credentials:'omit', cache:'no-store'}).then(function (response) {
		if (!response.ok || !(response.headers.get('Content-Type') || '').toLowerCase().startsWith('image/')) return false;
		return response.blob().then(function (blob) {
			if (!blob || blob.size > 3145728) return false;
			const stored = new Response(blob, {status:200, headers:{'Content-Type':blob.type || 'application/octet-stream','Cache-Control':'public, max-age=86400'}});
			return caches.open(KIWE_PWA.mediaCache).then(function (cache) {
				return cache.put(url.href, stored).then(function () { return trimCache(KIWE_PWA.mediaCache, 40); });
			});
		});
	}).catch(function () { return false; });
}
function offlineEditorialResponse(value) {
	if (!KIWE_PWA.offlineEditorialEnabled || !KIWE_PWA.offlineRest || isNetworkOnlyUrl(value)) return Promise.resolve(null);
	return caches.open(KIWE_PWA.editorialCache).then(function (cache) {
		return cache.match(offlineContractUrl(value)).then(function (cached) {
			if (!cached) return null;
			return cached.json().then(function (payload) {
				if (!payload || !payload.offlineReady || !payload.content) return null;
				const title = escapeHtml((payload.document && payload.document.title) || KIWE_PWA.name || 'Kiwe Appsite');
				const description = escapeHtml((payload.document && payload.document.description) || 'Saved for offline reading.');
				const canonical = escapeHtml((payload.route && payload.route.canonicalUrl) || value);
				const color = /^#[0-9a-f]{6}$/i.test(KIWE_PWA.theme || '') ? KIWE_PWA.theme : '#6b7280';
				const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' + color + '"><link rel="canonical" href="' + canonical + '"><title>' + title + '</title><style>html{font-family:system-ui,sans-serif;background:#f2f4f6;color:#20242d}body{margin:0;padding:clamp(24px,6vw,72px)}main{max-width:760px;margin:auto}.offline-kicker{font-size:12px;font-weight:800;text-transform:uppercase;color:' + color + '}.offline-title{font-size:clamp(38px,8vw,84px);line-height:1;margin:18px 0}.offline-description{font-size:18px;line-height:1.5;color:#59606d}.offline-content{margin-top:40px;font-size:18px;line-height:1.7}.offline-content img{display:block;max-width:100%;height:auto;margin:24px 0}</style></head><body><main><div class="offline-kicker">Available offline</div><h1 class="offline-title">' + title + '</h1><p class="offline-description">' + description + '</p><article class="offline-content">' + payload.content.html + '</article></main></body></html>';
				return new Response(html, {headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store','Content-Security-Policy':"default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",'X-Kiwe-Offline':'public-editorial-v1'}});
			}).catch(function () { return null; });
		});
	});
}
function offlineShellResponse() {
	const title = escapeHtml(KIWE_PWA.name || 'Kiwe Appsite');
	const message = escapeHtml(KIWE_PWA.offlineMessage || 'You are offline.');
	const color = /^#[0-9a-f]{6}$/i.test(KIWE_PWA.theme || '') ? KIWE_PWA.theme : '#6b7280';
	return new Response('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' + color + '"><title>' + title + '</title><style>html{font-family:system-ui,sans-serif;background:#f2f4f6;color:#20242d}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:28px}.offline{max-width:720px}.offline small{font-weight:800;text-transform:uppercase;color:' + color + '}.offline h1{font-size:clamp(48px,10vw,112px);line-height:.92;margin:18px 0;color:' + color + '}.offline p{font-size:20px;font-weight:650;line-height:1.4}</style></head><body><main class="offline"><small>Offline Appsite</small><h1>' + title + '</h1><p>' + message + '</p></main></body></html>', {headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}});
}
function escapeHtml(value) {
	return String(value || '').replace(/[&<>"']/g, function (character) {
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
	});
}
self.addEventListener('push', function (event) {
	let payload = {};
	if (event.data) {
		try { payload = event.data.json(); } catch (error) { payload = { body: event.data.text() }; }
	}
	const title = payload.title || 'Kiwe';
		const options = {
		body: payload.body || '',
		icon: payload.icon || KIWE_PWA.icon || undefined,
		badge: payload.badge || KIWE_PWA.badge || undefined,
		tag: payload.tag || 'kiwe-update',
		data: { url: payload.url || KIWE_PWA.home },
		vibrate: Array.isArray(payload.vibrate) ? payload.vibrate : [120, 60, 120],
		silent: false,
		renotify: true,
	};
	event.waitUntil(Promise.all([
		self.registration.showNotification(title, options),
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
			clients.forEach(function (client) { client.postMessage({ type: 'KIWE_PUSH_RECEIVED', payload: payload }); });
		})
	]));
});
self.addEventListener('pushsubscriptionchange', function (event) {
	const oldSubscription = event.oldSubscription || null;
	const options = oldSubscription && oldSubscription.options ? oldSubscription.options : null;
	if (!options || !options.applicationServerKey || !KIWE_PWA.pushRest) return;
	event.waitUntil(readPushRenewal().then(function (renewal) {
		if (!renewal || !renewal.token || !renewal.endpoint || renewal.endpoint !== oldSubscription.endpoint) return null;
		return self.registration.pushManager.subscribe({
		userVisibleOnly: true,
		applicationServerKey: options.applicationServerKey
		}).then(function (subscription) {
			return fetch(KIWE_PWA.pushRest, {
			method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json','X-Kiwe-Mutation':'1'},
			body: JSON.stringify({subscription: subscription.toJSON(), standalone: true, oldEndpoint: renewal.endpoint, renewalToken: renewal.token})
			}).then(function (response) { return response.ok ? response.json() : null; }).then(function (payload) {
				if (!payload || !payload.ok || !payload.renewalToken) return null;
				return storePushRenewal({endpoint:subscription.endpoint, renewalToken:payload.renewalToken});
			});
		});
	}).catch(function () { return null; }));
});
function storePushRenewal(data) {
	const token = String((data && (data.renewalToken || data.token)) || '');
	const endpoint = String((data && data.endpoint) || '');
	if (!token || !endpoint) return Promise.resolve(false);
	return caches.open(KIWE_PWA.pushMetaCache).then(function (cache) {
		return cache.put(new Request(self.location.origin + '/__kiwe_push_renewal__'), new Response(JSON.stringify({token:token,endpoint:endpoint}), {headers:{'Content-Type':'application/json','Cache-Control':'no-store'}}));
	});
}
function readPushRenewal() {
	return caches.open(KIWE_PWA.pushMetaCache).then(function (cache) {
		return cache.match(self.location.origin + '/__kiwe_push_renewal__');
	}).then(function (response) { return response ? response.json() : null; }).catch(function () { return null; });
}
self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	let target = event.notification.data && event.notification.data.url ? event.notification.data.url : KIWE_PWA.home;
	try { if (new URL(target).origin !== self.location.origin) target = KIWE_PWA.home; } catch (error) { target = KIWE_PWA.home; }
	event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
		for (const client of clients) {
			if ('focus' in client) {
				if ('navigate' in client) client.navigate(target);
				return client.focus();
			}
		}
		return self.clients.openWindow ? self.clients.openWindow(target) : null;
	}));
});
		<?php
		exit;
	}

	private function enabled(): bool {
		$permissions = $this->settings->get( 'permissions', [] );
		return (bool) $this->settings->get( 'enabled', true ) && is_array( $permissions ) && ! empty( $permissions['pwa_enabled'] );
	}

	private function service_worker_enabled(): bool {
		return $this->enabled() && (bool) $this->settings->get( 'service_worker', false );
	}

	private function manifest_url(): string {
		return add_query_arg( 'dsa_pwa_manifest', '1', home_url( '/' ) );
	}

	private function service_worker_url(): string {
		return add_query_arg( 'dsa_service_worker', '1', home_url( '/' ) );
	}

	private function scope_url(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? trailingslashit( $path ) : '/';
	}

	private function short_name(): string {
		$name = wp_strip_all_tags( get_bloginfo( 'name' ) ?: __( 'Kiwe', 'dsa' ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 24 ) : substr( $name, 0, 24 );
	}

	private function theme_color(): string {
		$theme = $this->settings->get( 'dsa_theme', [] );
		$color = is_array( $theme ) ? sanitize_hex_color( $theme['active_color'] ?? '' ) : '';
		return $color ?: '#6b7280';
	}

	private function icon_mime_type( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return 'webp' === $extension ? 'image/webp' : ( 'jpg' === $extension || 'jpeg' === $extension ? 'image/jpeg' : 'image/png' );
	}

	private function app_icon_url( int $size ): string {
		$icon = get_site_icon_url( $size );
		if ( $icon ) {
			return $icon;
		}

		return '';
	}
}
