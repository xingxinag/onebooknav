/**
 * OneBookNav Service Worker
 * Provides offline functionality and caching
 */

const CACHE_NAME = 'onebooknav-v1.0.0';
const STATIC_CACHE = 'onebooknav-static-v1';
const DYNAMIC_CACHE = 'onebooknav-dynamic-v1';

// Files to cache for offline functionality
const STATIC_FILES = [
  '/',
  '/manifest.json',
  '/assets/css/app.css',
  '/assets/js/app.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// API endpoints that should be cached
const API_CACHE_PATTERNS = [
  /\/api\/categories/,
  /\/api\/bookmarks/,
  /\/api\/auth\/user/
];

// Install event - cache static files
self.addEventListener('install', event => {
  console.log('[SW] Installing...');

  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('[SW] Caching static files');
        return cache.addAll(STATIC_FILES);
      })
      .then(() => {
        console.log('[SW] Installation complete');
        return self.skipWaiting();
      })
      .catch(error => {
        console.error('[SW] Installation failed:', error);
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  console.log('[SW] Activating...');

  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => {
            if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE) {
              console.log('[SW] Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log('[SW] Activation complete');
        return self.clients.claim();
      })
  );
});

// Fetch event - handle requests with caching strategy
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Handle different types of requests
  if (url.pathname.startsWith('/api/')) {
    // API requests - Network First with cache fallback
    event.respondWith(handleAPIRequest(request));
  } else if (STATIC_FILES.some(pattern => url.pathname.match(pattern))) {
    // Static files - Cache First
    event.respondWith(handleStaticRequest(request));
  } else {
    // Other requests - Network First
    event.respondWith(handleDynamicRequest(request));
  }
});

/**
 * Handle API requests with Network First strategy
 */
async function handleAPIRequest(request) {
  const cache = await caches.open(DYNAMIC_CACHE);

  try {
    // Try network first
    const networkResponse = await fetch(request);

    // Cache successful responses
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }

    return networkResponse;
  } catch (error) {
    console.log('[SW] Network failed, trying cache:', request.url);

    // Fallback to cache
    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Return offline page for failed API requests
    return new Response(JSON.stringify({
      success: false,
      error: 'Offline - please try again when connected',
      offline: true
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

/**
 * Handle static files with Cache First strategy
 */
async function handleStaticRequest(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cachedResponse = await cache.match(request);

  if (cachedResponse) {
    return cachedResponse;
  }

  try {
    const networkResponse = await fetch(request);
    cache.put(request, networkResponse.clone());
    return networkResponse;
  } catch (error) {
    console.error('[SW] Failed to fetch static resource:', request.url);
    throw error;
  }
}

/**
 * Handle dynamic requests with Network First strategy
 */
async function handleDynamicRequest(request) {
  const cache = await caches.open(DYNAMIC_CACHE);

  try {
    const networkResponse = await fetch(request);

    // Cache successful responses
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }

    return networkResponse;
  } catch (error) {
    console.log('[SW] Network failed, trying cache:', request.url);

    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Return offline page
    if (request.mode === 'navigate') {
      return caches.match('/offline.html') || new Response(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>Offline - OneBookNav</title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 2rem; text-align: center; background: #f8f9fa; }
            .container { max-width: 400px; margin: 0 auto; }
            .icon { font-size: 4rem; margin-bottom: 1rem; color: #6c757d; }
            h1 { color: #343a40; margin-bottom: 1rem; }
            p { color: #6c757d; margin-bottom: 2rem; }
            .btn { background: #007bff; color: white; padding: 0.75rem 2rem; border: none; border-radius: 0.375rem; text-decoration: none; display: inline-block; }
          </style>
        </head>
        <body>
          <div class="container">
            <div class="icon">📚</div>
            <h1>You're Offline</h1>
            <p>OneBookNav is not available right now. Please check your internet connection and try again.</p>
            <a href="/" class="btn" onclick="location.reload()">Try Again</a>
          </div>
        </body>
        </html>
      `, {
        headers: { 'Content-Type': 'text/html' }
      });
    }

    throw error;
  }
}

// Background sync for offline actions
self.addEventListener('sync', event => {
  console.log('[SW] Background sync:', event.tag);

  if (event.tag === 'sync-bookmarks') {
    event.waitUntil(syncBookmarks());
  }
});

/**
 * Sync bookmarks when back online
 */
async function syncBookmarks() {
  try {
    // Get pending actions from IndexedDB
    const pendingActions = await getPendingActions();

    for (const action of pendingActions) {
      try {
        const response = await fetch(action.url, action.options);
        if (response.ok) {
          // Remove successful action from pending
          await removePendingAction(action.id);
        }
      } catch (error) {
        console.error('[SW] Sync failed for action:', action.id, error);
      }
    }
  } catch (error) {
    console.error('[SW] Background sync failed:', error);
  }
}

// Push notification handling
self.addEventListener('push', event => {
  console.log('[SW] Push received');

  if (!event.data) {
    return;
  }

  const data = event.data.json();
  const options = {
    body: data.body,
    icon: '/assets/img/icon-192.png',
    badge: '/assets/img/badge.png',
    data: data.data,
    actions: [
      {
        action: 'open',
        title: 'Open OneBookNav'
      },
      {
        action: 'close',
        title: 'Close'
      }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Notification click handling
self.addEventListener('notificationclick', event => {
  console.log('[SW] Notification clicked');

  event.notification.close();

  if (event.action === 'open' || !event.action) {
    event.waitUntil(
      clients.openWindow('/')
    );
  }
});

// Message handling from main thread
self.addEventListener('message', event => {
  console.log('[SW] Message received:', event.data);

  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data && event.data.type === 'CACHE_URLS') {
    event.waitUntil(
      caches.open(DYNAMIC_CACHE)
        .then(cache => cache.addAll(event.data.urls))
    );
  }
});

// Utility functions for IndexedDB operations
async function getPendingActions() {
  // Implementation would use IndexedDB to store offline actions
  return [];
}

async function removePendingAction(id) {
  // Implementation would remove action from IndexedDB
  return true;
}

// Cache cleanup
async function cleanupOldCaches() {
  const cacheWhitelist = [STATIC_CACHE, DYNAMIC_CACHE];
  const cacheNames = await caches.keys();

  return Promise.all(
    cacheNames.map(cacheName => {
      if (!cacheWhitelist.includes(cacheName)) {
        return caches.delete(cacheName);
      }
    })
  );
}

// Periodic cache cleanup
setInterval(cleanupOldCaches, 24 * 60 * 60 * 1000); // Daily cleanup