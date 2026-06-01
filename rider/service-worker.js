/* eslint-disable no-restricted-globals */
'use strict';

/**
 * 라이더 PWA — /assets/ 정적 리소스 캐시, 라이더 영역 네비게이션은 네트워크 우선(실패 시 오프라인 페이지)
 * 버전 올릴 때 CACHE_VERSION 과 아래 activate 정리 접두사를 함께 수정하세요.
 */
var CACHE_VERSION = 'baedal-rider-v1';
var ASSET_CACHE = CACHE_VERSION + '-assets';
var CACHE_PREFIX = 'baedal-rider-';

function riderOfflineUrl() {
	var u = new URL(self.location.href);
	return new URL('offline.html', u).href;
}

self.addEventListener('install', function (event) {
	var offlineUrl = riderOfflineUrl();
	event.waitUntil(
		caches
			.open(ASSET_CACHE)
			.then(function (cache) {
				return cache.add(offlineUrl).catch(function () {});
			})
			.then(function () {
				return self.skipWaiting();
			})
	);
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches
			.keys()
			.then(function (keys) {
				return Promise.all(
					keys.map(function (key) {
						if (key.startsWith(CACHE_PREFIX) && key !== ASSET_CACHE) {
							return caches.delete(key);
						}
						return Promise.resolve();
					})
				);
			})
			.then(function () {
				return self.clients.claim();
			})
	);
});

function isSameOrigin(url) {
	return url.origin === self.location.origin;
}

function isAssetPath(pathname) {
	return pathname.indexOf('/assets/') !== -1;
}

function isRiderScopePath(pathname) {
	var scopePath = new URL(self.registration.scope).pathname.replace(/\/$/, '') || '';
	if (!scopePath) {
		return true;
	}
	return pathname === scopePath || pathname.indexOf(scopePath + '/') === 0;
}

self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') {
		return;
	}

	var url = new URL(req.url);
	if (!isSameOrigin(url)) {
		return;
	}

	var isNavigate = req.mode === 'navigate';

	if (isNavigate && isRiderScopePath(url.pathname) && !isAssetPath(url.pathname)) {
		var offlineUrl = riderOfflineUrl();
		event.respondWith(
			fetch(req).catch(function () {
				return caches.match(offlineUrl);
			})
		);
		return;
	}

	if (isAssetPath(url.pathname)) {
		event.respondWith(
			caches.open(ASSET_CACHE).then(function (cache) {
				return fetch(req)
					.then(function (res) {
						if (res.ok) {
							try {
								cache.put(req, res.clone());
							} catch (e) {}
						}
						return res;
					})
					.catch(function () {
						return cache.match(req);
					});
			})
		);
	}
});
