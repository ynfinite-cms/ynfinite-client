/**
 * Generic API cache utility.
 * Wraps any async function with server-side file caching via /yn-api/cache.
 *
 * Usage:
 *   const data = await $_yn.cache.fetch('my-key', fetchFunction, 3600)
 *
 * @param {string}   key      - Unique cache identifier
 * @param {Function} fetchFn  - Async function that returns fresh data
 * @param {number}   [ttl]    - TTL in seconds (default: 24h via server fallback)
 * @returns {Promise<any>}    - Cached or freshly fetched data
 */
const fetch_cached = async (key, fetchFn, ttl) => {
	if (!key || !key.trim()) return await fetchFn()

	try {
		const res = await fetch(`/yn-api/cache?key=${encodeURIComponent(key)}`)
		const result = await res.json()

		if (result.cached !== false) {
			return result
		}
	} catch (e) {
		console.warn(`[yn-cache] read failed for "${key}":`, e)
	}

	const data = await fetchFn()

	if (data != null) {
		try {
			const body = { key, data }
			if (ttl > 0) body.ttl = ttl
			await fetch('/yn-api/cache', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body),
			})
		} catch (e) {
			console.warn(`[yn-cache] write failed for "${key}":`, e)
		}
	}

	return data
}

export default { fetch: fetch_cached }
