/**
 * F&O Signal Pro — NSE proxy (Cloudflare Worker)
 *
 * NSE (nseindia.com) blocks many datacenter IPs and requires a primed session
 * cookie, which breaks the plugin's live option-chain / FII-DII / breadth calls
 * from some hosts. This Worker runs on Cloudflare's edge, primes the NSE cookie,
 * adds browser-like headers, and returns the JSON with permissive CORS.
 *
 * Point the plugin's "NSE proxy" setting at:
 *   https://<your-worker>.workers.dev/?url={url}
 * or, if you set a PROXY_SECRET:
 *   https://<your-worker>.workers.dev/?url={url}&key=YOUR_SECRET
 *
 * SECURITY: only requests to nseindia.com are forwarded; everything else is
 * rejected so this can't be abused as an open proxy.
 */

const ALLOWED_HOSTS = ['www.nseindia.com', 'nseindia.com'];

// Short edge cache so we are polite to NSE and fast for the plugin.
const CACHE_TTL_SECONDS = 25;

const BROWSER_HEADERS = {
	'User-Agent':
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
	Accept: 'application/json, text/plain, */*',
	'Accept-Language': 'en-US,en;q=0.9',
	'Accept-Encoding': 'gzip, deflate, br',
};

function corsHeaders() {
	return {
		'Access-Control-Allow-Origin': '*',
		'Access-Control-Allow-Methods': 'GET, OPTIONS',
		'Access-Control-Allow-Headers': 'Content-Type, X-API-Key, Authorization',
	};
}

function json(obj, status = 200) {
	return new Response(JSON.stringify(obj), {
		status,
		headers: { 'Content-Type': 'application/json', ...corsHeaders() },
	});
}

/**
 * Collect "name=value" cookie pairs from a response's Set-Cookie headers.
 */
function collectCookies(response) {
	let pairs = [];
	if (typeof response.headers.getSetCookie === 'function') {
		const all = response.headers.getSetCookie();
		pairs = all.map((c) => c.split(';')[0]).filter(Boolean);
	} else {
		const raw = response.headers.get('set-cookie');
		if (raw) {
			pairs = raw.split(/,(?=[^;]+=[^;]+)/).map((c) => c.split(';')[0].trim());
		}
	}
	return pairs.join('; ');
}

export default {
	async fetch(request, env, ctx) {
		if (request.method === 'OPTIONS') {
			return new Response(null, { status: 204, headers: corsHeaders() });
		}
		if (request.method !== 'GET') {
			return json({ error: 'Method not allowed' }, 405);
		}

		const reqUrl = new URL(request.url);

		// Health check.
		if (reqUrl.pathname === '/health') {
			return json({ ok: true, service: 'fno-nse-proxy' });
		}

		// Optional shared secret.
		if (env && env.PROXY_SECRET) {
			if (reqUrl.searchParams.get('key') !== env.PROXY_SECRET) {
				return json({ error: 'Unauthorized' }, 401);
			}
		}

		const target = reqUrl.searchParams.get('url');
		if (!target) {
			return json({ error: 'Missing "url" query parameter' }, 400);
		}

		let t;
		try {
			t = new URL(target);
		} catch (e) {
			return json({ error: 'Invalid target URL' }, 400);
		}
		if (!ALLOWED_HOSTS.includes(t.hostname)) {
			return json({ error: 'Target host not allowed (nseindia.com only)' }, 403);
		}

		// Edge cache lookup.
		const cache = caches.default;
		const cacheKey = new Request('https://cache.local/' + encodeURIComponent(t.toString()), { method: 'GET' });
		const cached = await cache.match(cacheKey);
		if (cached) {
			const r = new Response(cached.body, cached);
			r.headers.set('X-Proxy-Cache', 'HIT');
			return r;
		}

		// Prime NSE session cookies.
		let cookie = '';
		try {
			const home = await fetch('https://www.nseindia.com/option-chain', {
				headers: { ...BROWSER_HEADERS, Accept: 'text/html,application/xhtml+xml' },
				cf: { cacheTtl: 0 },
			});
			cookie = collectCookies(home);
		} catch (e) {
			// Continue without cookie; some endpoints still respond.
		}

		const headers = {
			...BROWSER_HEADERS,
			Referer: 'https://www.nseindia.com/option-chain',
		};
		if (cookie) {
			headers['Cookie'] = cookie;
		}

		let upstream;
		try {
			upstream = await fetch(t.toString(), { headers });
		} catch (e) {
			return json({ error: 'Upstream fetch failed', detail: String(e) }, 502);
		}

		const bodyText = await upstream.text();
		const contentType = upstream.headers.get('content-type') || 'application/json';

		const response = new Response(bodyText, {
			status: upstream.status,
			headers: {
				'Content-Type': contentType,
				'Cache-Control': `public, max-age=${CACHE_TTL_SECONDS}`,
				'X-Proxy-Cache': 'MISS',
				...corsHeaders(),
			},
		});

		// Store a cacheable copy (only successful JSON).
		if (upstream.status === 200) {
			ctx.waitUntil(cache.put(cacheKey, response.clone()));
		}

		return response;
	},
};
