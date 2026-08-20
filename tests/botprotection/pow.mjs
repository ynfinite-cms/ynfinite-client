#!/usr/bin/env node
/**
 * Computes a valid proof-of-work exactly like src/assets/worker.js:
 * brute-force sha256(csrf + prevHash + timestampMs + formId + nonce) until the
 * first 5 hex chars are '00000' or '11111' (difficulty=5, chances=1).
 *
 * Usage: node pow.mjs <csrfToken> <formId> [prevHash] [timestampMs]
 * Prints JSON: { hash, nonce, prevHash, timestamp }
 */
import { createHash } from 'crypto'

const [csrf, formId, prevHashArg, tsArg] = process.argv.slice(2)
if (!csrf || !formId) {
	console.error('usage: node pow.mjs <csrfToken> <formId> [prevHash] [timestampMs]')
	process.exit(1)
}

const prevHash = prevHashArg && prevHashArg !== '0' ? prevHashArg : '0'
const timestamp = tsArg || String(Date.now())

let nonce = 0
let hash = ''
for (;;) {
	nonce++
	hash = createHash('sha256')
		.update(csrf + prevHash + timestamp + formId + nonce)
		.digest('hex')
	const prefix = hash.slice(0, 5)
	if (prefix === '00000' || prefix === '11111') break
}

console.log(JSON.stringify({ hash, nonce: String(nonce), prevHash, timestamp }))
