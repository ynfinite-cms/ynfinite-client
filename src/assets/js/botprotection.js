import SHA256 from 'crypto-js/sha256'

const debug = false

const WORKER_PATH = '/assets/vendor/ynfinite/js/worker.min.js'

/**
 * The worker URL, versioned with the same ?v= the page uses for app.min.js.
 *
 * The worker used to be loaded from a bare, unversioned URL while .htaccess
 * serves JS with a long browser cache lifetime. After a deploy that changed the
 * worker's message format, browsers kept pairing the new app bundle with a
 * stale cached worker, producing unverifiable proofs and hard PHP rejections.
 * Reusing the app bundle's version guarantees both files always match.
 */
function workerUrl() {
	const appScript = document.querySelector('script[src*="app.min.js"]')
	if (appScript) {
		const match = (appScript.getAttribute('src') || '').match(/[?&]v=([^&]+)/)
		if (match) {
			return WORKER_PATH + '?v=' + match[1]
		}
	}
	return WORKER_PATH
}

/**
 * Puts the submit button back into its normal, clickable state.
 * Used both when the proof arrives and when the worker never answers, so a
 * failed worker degrades to "submit and get scored" instead of leaving a
 * permanently disabled button.
 */
function restoreSubmitButton(form) {
	const formSubmitButton = form.querySelector('button[type=submit]')
	if (!formSubmitButton) {
		return
	}

	// forms.js paints the button as a dead error state ("Bitte neu laden.") and
	// removes its pointer events. Re-arming the proof afterwards must not put
	// the original label back and re-enable it: that produced a normal looking
	// button that silently swallowed every click, with the error message gone.
	if (formSubmitButton.dataset.ynError === 'true') {
		return
	}

	formSubmitButton.classList.remove('yn-loader')
	formSubmitButton.classList.remove('yn-botprotection')
	formSubmitButton.style.removeProperty('padding-left')
	if (formSubmitButton.dataset.label) {
		formSubmitButton.textContent = formSubmitButton.dataset.label
	}
	formSubmitButton.disabled = false
}

/**
 * Main-thread proof-of-work fallback for environments where the worker cannot
 * run at all (worker asset blocked/404, CSP worker-src, no window.Worker).
 *
 * Mirrors worker.js exactly: brute-force SHA256(csrf + prevHash + timestamp +
 * formId + nonce) until the prefix matches, respecting minRunTime. Work is
 * chunked via setTimeout so the page stays responsive. Without this fallback a
 * worker infrastructure failure meant no visitor could ever produce a proof,
 * and PHP (correctly) rejected every submission.
 */
function mainThreadProof(block, difficulty, chances, minRunTime, csrfToken, onDone) {
	const form = block.data.form
	const formId = form.dataset.ynformid || ''
	const prevHash = block.previousHash || '0'
	const timestamp = block.timestamp

	const chancesArray = []
	const boundedChances = Math.min(Math.max(chances, 0), 9)
	for (let i = 0; i <= boundedChances; i++) {
		chancesArray.push(String(i).repeat(difficulty))
	}

	let nonce = 0

	const step = () => {
		// ~2000 hashes per slice keeps each slice well under a frame budget on
		// slow devices while still finishing a difficulty-5 proof quickly.
		for (let i = 0; i < 2000; i++) {
			nonce++
			const hash = SHA256(csrfToken + prevHash + timestamp + formId + nonce).toString()
			if (chancesArray.includes(hash.substring(0, difficulty))) {
				const remaining = Math.max(0, minRunTime - (Date.now() - timestamp))
				setTimeout(() => onDone(hash, nonce), remaining)
				return
			}
		}
		setTimeout(step, 0)
	}

	setTimeout(step, 0)
}

class Block {
	constructor(data, previousHash = '') {
		this.previousHash = previousHash
		this.timestamp = Date.now()
		this.data = data
		this.hash = this.calculateHash()
		this.nonce = 0
	}

	calculateHash() {
		if (this.data != null && this.data['form'] != undefined) {
			return SHA256((this.previousHash || '0') + this.timestamp + (this.data['form'].dataset.ynformid || '')).toString()
		}
		// Genesis block — return a real hash so previousHash is never undefined
		return SHA256((this.previousHash || '0') + this.timestamp + String(this.data || '')).toString()
	}

	// difficulty = size of number (4 = 0000)
	// chances = number of chances (2 = 0000, 1111, 2222)
	startProofOfWork(difficulty = 5, chances = 1, minRunTime = 5000, csrfToken = '') {
		const form = this.data.form

		// A worker can also fail *silently*: a stale cached worker.min.js that
		// never answers, a worker the browser killed, a hashing loop that never
		// terminates. Neither onmessage nor onerror fires then, so without these
		// timers the submit button stayed disabled forever and the visitor could
		// not send the form at all. WORKER_TIMEOUT recomputes the proof on the
		// main thread, PROOF_TIMEOUT is the last resort that gives the button
		// back (forms.js still waits for the proof, PHP stays the authority).
		const WORKER_TIMEOUT = 15000
		const PROOF_TIMEOUT = 25000
		let workerWatchdog = null
		let proofWatchdog = null

		const finish = (hash, nonce) => {
			clearTimeout(workerWatchdog)
			clearTimeout(proofWatchdog)
			this.hash = hash
			form.dataset.hasProof = 'true'
			form.dataset.proofenHash = hash
			form.dataset.proofenNonce = String(nonce)
			form.dataset.proofenPrevHash = this.previousHash || '0'
			form.dataset.proofenTimestamp = String(this.timestamp)

			// Re-enables the submit button and, with it, implicit
			// (Enter key) submission - see the focusin handler below.
			restoreSubmitButton(form)

			// Anything waiting on the proof (forms.js waitForProof) resumes here.
			form.dispatchEvent(new Event('yn-proof-ready'))

			if (debug) {
				console.timeEnd('yn-botprotection')
			}
		}

		if (debug) {
			console.time('yn-botprotection')
		}

		// Clearing data-working matters as much as re-enabling the button: forms.js
		// only re-arms a proof when no run is in flight, so a hung run that kept
		// the flag set made every later submit wait out waitForProof's timeout and
		// then be rejected - 20 of those lock the visitor out.
		proofWatchdog = setTimeout(() => {
			delete form.dataset.working
			restoreSubmitButton(form)
		}, PROOF_TIMEOUT)

		// new Worker() throws synchronously under a worker-src CSP; without the
		// try/catch that used to leave the form permanently disabled.
		let blockWorker = null
		if (window.Worker) {
			try {
				blockWorker = new Worker(workerUrl())
			} catch (e) {
				blockWorker = null
			}
		}

		if (!blockWorker) {
			mainThreadProof(this, difficulty, chances, minRunTime, csrfToken, finish)
			return
		}

		let fellBack = false

		const fallBackToMainThread = (reason) => {
			if (fellBack) {
				return
			}
			fellBack = true
			clearTimeout(workerWatchdog)
			try {
				blockWorker.terminate()
			} catch (e) {
				// worker already gone - nothing to clean up
			}
			console.error('Bot protection worker ' + reason + ' - falling back to main thread.')
			mainThreadProof(this, difficulty, chances, minRunTime, csrfToken, finish)
		}

		// Silent failure: worker was created but never posted a result.
		workerWatchdog = setTimeout(() => fallBackToMainThread('did not answer in time'), WORKER_TIMEOUT)

		blockWorker.onmessage = (e) => {
			blockWorker.terminate()
			if (fellBack) {
				return
			}
			// A worker answering with an unexpected payload (stale cached asset from
			// before a deploy) would otherwise store the string "undefined" as the
			// proof: forms.js accepts it as "done" and PHP rejects every attempt
			// with pow_bad_format. Recompute on the main thread instead.
			const hash = e.data && e.data.hash
			if (typeof hash !== 'string' || !/^[0-9a-f]{64}$/.test(hash)) {
				fallBackToMainThread('returned an unusable proof')
				return
			}
			clearTimeout(workerWatchdog)
			finish(hash, e.data.nonce)
		}

		// Worker asset could not be loaded or threw (404, MIME, CSP at fetch
		// time): compute the proof on the main thread instead. The visitor
		// still gets a valid proof; only the thread doing the work changes.
		blockWorker.onerror = () => {
			fallBackToMainThread('failed to run')
		}

		blockWorker.postMessage({
			form: form.dataset.ynformid || '',
			previousHash: this.previousHash || '0',
			timestamp: this.timestamp,
			difficulty,
			chances,
			minRunTime,
			csrfToken,
		})
	}
}

class BlockChain {
	constructor() {
		this.chain = [this.createGenesisBlock()]
	}

	getLatestBlock() {
		return this.chain[this.chain.length - 1]
	}

	addBlock(newBlock) {
		newBlock.previousHash = this.getLatestBlock().hash
		this.chain.push(newBlock)

		return newBlock
	}

	createGenesisBlock() {
		return new Block('Genesis block', '0')
	}
}

const YnfiniteBotProtection = {
	setup() {
		const csrfToken = document.cookie.match(/(?:^|;\s*)ynfinite-csrf-protection=([^;]+)/)?.[1] || ''
		const blockchain = new BlockChain()

		const lockSubmitButton = (form) => {
			const formSubmitButton = form.querySelector('button[type=submit]')
			if (!formSubmitButton) {
				return
			}
			// A button forms.js declared dead ("Bitte neu laden.") keeps its
			// message: overwriting it with the spinner label would hide why the
			// submit failed, and dataset.label would then cache the error text.
			if (formSubmitButton.dataset.ynError === 'true') {
				return
			}
			const pos = 'var(--loader-size,16px) + ' + getComputedStyle(formSubmitButton).paddingLeft
			formSubmitButton.dataset.label = formSubmitButton.textContent
			formSubmitButton.style.paddingLeft = 'calc(' + pos + ')'
			formSubmitButton.style.setProperty('--yn-loader-pos', 'calc((' + pos + ' - var(--loader-size,16px)) / 2);')
			formSubmitButton.classList.add('yn-botprotection')
			formSubmitButton.classList.add('yn-loader')
			formSubmitButton.textContent = 'Bot-Prüfung'
			// Disabling the default button also blocks implicit submission,
			// i.e. pressing Enter inside a field, so nothing can submit
			// while the proof is still being computed. Re-enabled in finish().
			formSubmitButton.disabled = true
		}

		// A "real" proof is a 64-hex hash. Stale cached HTML from before this
		// feature carries data-proofen-hash="true", which must never count.
		const hasRealProof = (form) => form.dataset.hasProof === 'true' && /^[0-9a-f]{64}$/.test(form.dataset.proofenHash || '')

		const startProof = (form) => {
			if (hasRealProof(form) || form.dataset.working) {
				return
			}

			form.dataset.working = true
			lockSubmitButton(form)

			const block = blockchain.addBlock(new Block({ form: form }))
			block.startProofOfWork(5, 1, 5000, csrfToken)
		}

		// .yn-no-bot-protection is set by Twig on noBotProtection forms.
		// It is purely a UX signal — it controls whether to show the PoW spinner.
		// It does NOT skip any bot scoring in forms.js (all scoring runs on all forms).
		// A bot adding/removing this class cannot skip any checks; PHP validates the proof.
		//
		// The nobot shortcut additionally requires the server-signed yn_nobot_token
		// input. HTML cached before that token existed carries the class without
		// the token — PHP would reject its sentinel, so such forms are treated as
		// normal PoW forms (the proof is computed lazily on focus/submit instead).
		const forms = document.querySelectorAll('form[data-ynform=true][method=post]')

		forms.forEach((form) => {
			const isNoBotForm = form.classList.contains('yn-no-bot-protection') && !!form.querySelector('input[name="yn_nobot_token"]')

			if (isNoBotForm) {
				// noBotProtection forms: set hasProof and proofenHash immediately (no spinner/focusin needed).
				// forms.js scoring still runs — hasProof/proofenHash will be set here so those checks pass.
				// A bot adding this class still faces all JS scoring and PHP HMAC validation.
				const formId = form.getAttribute('data-ynformid') || ''
				form.dataset.hasProof = 'true'
				form.dataset.proofenHash = SHA256(csrfToken + formId + 'nobot').toString()
				return
			}

			form.dataset.hasProof = 'false'
			form.dataset.proofenHash = ''

			form.addEventListener('focusin', function () {
				startProof(form)
			})
		})

		// Lazy start requested by forms.js: fired on submit when no proof exists
		// yet (form was never focused — Safari does not focus buttons on click —
		// or the proof was consumed by a previous submit and must be re-armed).
		document.addEventListener('yn-start-proof', (e) => {
			const form = e.target
			if (!(form instanceof HTMLFormElement)) {
				return
			}
			if ((form.getAttribute('method') || '').toLowerCase() !== 'post') {
				return
			}
			startProof(form)
		})
	},
}

export default YnfiniteBotProtection
