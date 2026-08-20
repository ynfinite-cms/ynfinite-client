import { load } from '@fingerprintjs/botd'
import SHA256 from 'crypto-js/sha256'

const debug = false
const renderedKey = Math.random().toString(36).substring(2)
const focusedElements = []
/** Required fields the visitor interacted with, including runtime-cloned ones. */
const touchedFields = new WeakSet()
const FOCUS_FIELD_SELECTOR =
	':is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])'
const defaultValueFields = []
let botD = undefined
let humanMovement = false
let inputTypingPatterns = []
let inputLastKeyTime = 0
let normalTypingConsistency = true

/**
 * Captcha state per form element. Used to be two module level variables, which
 * meant a captcha shown on form A made form B skip its whole scoring pass and
 * then crash on a missing `.captchaTextBox` - silently killing form B's submit.
 */
const captchaStates = new WeakMap()

/**
 * Bot score bookkeeping.
 *
 * Two tiers:
 *   HARD codes are behavioral evidence of automation (a honeypot was filled, an
 *   invisible checkbox was checked, machine-perfect typing or mouse paths).
 *   Only these can reach the 100 point hard block.
 *   SOFT codes describe the environment or the *absence* of signals (no storage,
 *   nothing ever focused, no pointer movement). Privacy browsers, Safari's
 *   click-doesn't-focus behavior, keyboard/touch users and headless-looking
 *   setups all trip these without being bots, so their combined contribution is
 *   capped below the block threshold - they can at most cause a captcha.
 *
 * Every signal is stored once, keyed by its error code, so a second submit
 * attempt cannot accumulate the same penalty twice. Submit-scoped codes are
 * kept per form element - a penalty earned on form A must not block form B on
 * the same page.
 */
const SCORE_VALUES = { 1: 40, 2: 40, 3: 100, 4: 20, 5: 5, 6: 5, 7: 5, 8: 30, 9: 15, 10: 10, 11: 5, 12: 5, 13: 5, 14: 5, 17: 20 }
const HARD_CODES = new Set(['1', '2', '3', '4', '8'])
const SOFT_SCORE_CAP = 40

// Signals that describe a single submit attempt on one form (as opposed to page
// level browser/environment checks). Stored per form, cleared when the visitor
// starts a new form, so a fresh attempt is judged on its own merits.
const SUBMIT_SCOPED_CODES = new Set(['1', '2', '3', '4', '8', '9', '17'])

// code -> points for page level environment signals (shared by all forms).
const envScoreByCode = new Map()
// form element -> Map(code -> points) for submit-scoped signals.
const submitScoresByForm = new WeakMap()

function addBotScore(form, code, message) {
	code = String(code)
	const points = SCORE_VALUES[code] || 0

	let store = envScoreByCode
	if (SUBMIT_SCOPED_CODES.has(code)) {
		if (!form) {
			return
		}
		if (!submitScoresByForm.has(form)) {
			submitScoresByForm.set(form, new Map())
		}
		store = submitScoresByForm.get(form)
	}

	if (store.has(code)) {
		return
	}
	store.set(code, points)

	if (debug) {
		console.log(`%cBot detected by ${message} (added ${points} Score, code ${code})`, 'color: red')
		console.log('%cNew Botscore: ' + getBotScore(form), `color: ${getBotScore(form) >= 100 ? 'red' : 'yellow'}`)
	}
}

function getBotScore(form) {
	let hard = 0
	let soft = 0

	const tally = (code, points) => {
		if (HARD_CODES.has(String(code))) {
			hard += points
		} else {
			soft += points
		}
	}

	envScoreByCode.forEach((points, code) => tally(code, points))
	if (form && submitScoresByForm.has(form)) {
		submitScoresByForm.get(form).forEach((points, code) => tally(code, points))
	}

	return hard + Math.min(soft, SOFT_SCORE_CAP)
}

function getErrorCodes(form) {
	const codes = Array.from(envScoreByCode.keys())
	if (form && submitScoresByForm.has(form)) {
		codes.push(...submitScoresByForm.get(form).keys())
	}
	return codes.map(String).sort((a, b) => Number(a) - Number(b))
}

function resetSubmitScores(form) {
	submitScoresByForm.delete(form)
}

/**
 * Reads the `ynfinite-csrf-protection` cookie value set by CsrfCookieMiddleware.
 * Returns an empty string when the cookie is not present.
 */
function getCsrfToken() {
	const match = document.cookie.match(/(?:^|;\s*)ynfinite-csrf-protection=([^;]+)/)
	return match ? decodeURIComponent(match[1]) : ''
}

function dontFocusHoneypots() {
	const honeypots = document.querySelectorAll('input[name="yn_confirm_name"], input[name="yn_confirm_email"], [name="consents[]_v2"]')

	// add event focusin, find the parent form, find .form-content, find the first form field and focus it
	honeypots.forEach((honeypot) => {
		honeypot.addEventListener('focusin', (e) => {
			const form = honeypot.closest('form')
			const formContent = form && form.querySelector('.form-content')
			const firstField = formContent && formContent.querySelector('input, textarea, select')
			if (firstField) {
				firstField.focus()
			}
		})
	})
}

/**
 * Fills and evaluates the honeypot fields.
 *
 * Must run on *every* submit path, not only on a submit button click: PHP
 * rejects any POST whose yn_confirm_email does not carry the CSRF cookie value,
 * so a path that never fills it in ends in "Security check failed" for a real
 * visitor. submitForm() therefore calls this itself; the click handler stays as
 * an early trigger. Calling it twice is harmless - the first call fills the
 * fields, and addBotScore() records every signal at most once.
 *
 * All lookups are null-safe: the honeypots only exist on POST forms rendered by
 * form.twig, while the click handler is attached to every form on the page.
 */
function checkHoneypot(form) {
	const honeypot_name = form.querySelector('input[name="yn_confirm_name"], [name="consents[]_v2"]')
	if (honeypot_name) {
		if (!honeypot_name.value) {
			honeypot_name.value = renderedKey
		}

		if (honeypot_name.value !== renderedKey) {
			addBotScore(form, '1', 'name honeypot')
		} else if (debug) {
			console.log('%cHoneypot (name) check passed', 'color: green')
		}
	}

	const honeypot_mail = form.querySelector('input[name="yn_confirm_email"]')
	const expectedHoneypot = getCsrfToken()
	if (honeypot_mail) {
		if (!honeypot_mail.value) {
			honeypot_mail.value = expectedHoneypot
		}

		if (honeypot_mail.value !== expectedHoneypot) {
			addBotScore(form, '2', 'mail honeypot')
		} else if (debug) {
			console.log('%cHoneypot (mail) check passed', 'color: green')
		}
	}

	const consent_honeypots = form.querySelectorAll('.yn_consents_v2')
	consent_honeypots.forEach((consent) => {
		const input = consent.querySelector('input[type="checkbox"]')
		if (input && input.checked) {
			addBotScore(form, '3', 'consent honeypot')
		} else {
			consent.remove()
		}
	})
}

function setHoneypotClickEvent() {
	const forms = document.querySelectorAll('form')
	forms.forEach((form) => {
		const submitButtons = form.querySelectorAll('[type=submit]:not([tabindex="-1"], [type="hidden"], .hidden')
		submitButtons.forEach((submitButton) => {
			submitButton.addEventListener('click', () => {
				checkHoneypot(form)
			})
		})
	})
}

function analyzeTypingConsistency(patterns) {
	if (patterns.length < 3) return 0

	// Calculate variance in typing patterns
	const sum = patterns.reduce((a, b) => a + b, 0)
	const mean = sum / patterns.length
	const squareDiffs = patterns.map((value) => Math.pow(value - mean, 2))
	const variance = squareDiffs.reduce((a, b) => a + b, 0) / patterns.length

	// Low variance means very consistent typing (suspicious)
	return Math.max(0, 1 - Math.min(variance, 5000) / 5000)
}

function checkTryTypingConsistency(form) {
	if (normalTypingConsistency === false) {
		addBotScore(form, '4', 'unnatural typing patterns')
	}
}

function setupTypingAnalysis() {
	const inputs = document.querySelectorAll('input, textarea')

	inputs.forEach((input) => {
		input.addEventListener('keydown', (e) => {
			// Auto-repeat (holding Backspace/an arrow key) fires at a fixed OS
			// interval, i.e. with near zero variance. Counting those keys made
			// a completely normal edit look machine perfect and latched hard
			// code 4 (+20) on every form of the page.
			if (e.repeat) {
				return
			}

			const currentTime = Date.now()

			if (inputLastKeyTime > 0) {
				// Track time between keypresses
				const timeDiff = currentTime - inputLastKeyTime
				inputTypingPatterns.push(timeDiff)
			}

			inputLastKeyTime = currentTime
		})

		input.addEventListener('blur', () => {
			if (inputTypingPatterns.length > 4) {
				const consistencyCheck = analyzeTypingConsistency(inputTypingPatterns)
				if (consistencyCheck > 0.95) {
					normalTypingConsistency = false
				}
			}
			inputTypingPatterns = []
			inputLastKeyTime = 0
		})
	})
}

function checkBrowserEnvironment() {
	if (!navigator.language || !navigator.userAgent || !navigator.platform) {
		addBotScore(null, '5', 'missing navigator properties')
		return
	}

	if (!window.devicePixelRatio || window.devicePixelRatio === 0) {
		addBotScore(null, '6', 'suspicious devicePixelRatio')
		return
	}

	if (typeof document.addEventListener !== 'function' || typeof window.setTimeout !== 'function') {
		addBotScore(null, '7', 'missing core browser functions')
		return
	}

	if (debug) {
		console.log('%cBrowser environment check passed', 'color: green')
	}
}

function trackMovements() {
	let mousePositions = []
	let lastTime = 0
	let straightLineCounter = 0

	const checkStraightLine = (positions, index) => {
		if (index < 2) return false

		const p1 = positions[index - 2]
		const p2 = positions[index - 1]
		const p3 = positions[index]

		if (p1 && p2 && p3) {
			const slope1 = p2.x !== p1.x ? (p2.y - p1.y) / (p2.x - p1.x) : Infinity
			const slope2 = p3.x !== p2.x ? (p3.y - p2.y) / (p3.x - p2.x) : Infinity

			// Viel strengere Schwelle für die Erkennung gerader Linien
			// 0.001 statt 0.01 bedeutet, dass die Linien fast exakt gerade sein müssen
			const isStraight = Math.abs(slope1 - slope2) < 0.001

			// Zusätzlich: Prüfe die Distanz zwischen den Punkten, um gleichmäßige Bewegungen zu erkennen
			const dist1 = Math.sqrt(Math.pow(p2.x - p1.x, 2) + Math.pow(p2.y - p1.y, 2))
			const dist2 = Math.sqrt(Math.pow(p3.x - p2.x, 2) + Math.pow(p3.y - p2.y, 2))

			// Prüfe auch, ob die Abstände zwischen den Punkten ähnlich sind (ein weiterer Indikator für Bot-Bewegungen)
			const isEvenlySpaced = Math.abs(dist1 - dist2) / Math.max(dist1, dist2) < 0.05

			// Nur melden, wenn die Linie gerade UND gleichmäßig verteilt ist
			const isRobotic = isStraight && isEvenlySpaced

			if (debug && isRobotic) {
				console.log('%cStraight line detected', 'color: yellow')
			}
			return isRobotic
		}
		return false
	}

	const recordMousePosition = (e) => {
		const currentTime = Date.now()
		if (currentTime - lastTime > 50) {
			// Nur alle 50ms aufzeichnen, um die Leistung zu verbessern
			mousePositions.push({ x: e.clientX, y: e.clientY, time: currentTime })
			lastTime = currentTime

			// Prüfe auf verdächtig gerade Linien
			if (checkStraightLine(mousePositions, mousePositions.length - 1)) {
				straightLineCounter++
			}
		}
	}

	document.addEventListener('mousemove', recordMousePosition)

	// Überprüfung beim Formular-Submit
	const checkMovementPatterns = (form) => {
		// Zu wenige aufgezeichnete Punkte sind verdächtig (falls Gerät keine Touch-Eingabe hat)
		const positions = mousePositions

		// Zu viele gerade Linien deuten auf einen Bot hin
		if (positions.length > 0) {
			if (straightLineCounter > positions.length * 0.5) {
				addBotScore(form, '8', 'suspicious straight line movements')
			} else if (debug) {
				console.log('%cMovement pattern check passed', 'color: green')
			}
		} else if (debug) {
			console.log('%cMovement check skipped', 'color: yellow')
		}

		document.removeEventListener('mousemove', recordMousePosition)
	}

	// Store the function globally so it can be called when needed
	window.checkMouseMovementPatterns = checkMovementPatterns
}

function checkHumanMovement() {
	// Any of these counts as human interaction. The old version only accepted
	// mousemove, touchmove and the Tab key, which flagged Safari users (click
	// does not focus buttons there), pure-keyboard users navigating without Tab
	// and touch users who tap without dragging.
	const events = ['mousemove', 'pointermove', 'touchmove', 'touchstart', 'wheel', 'scroll', 'keydown']

	const handleMovement = () => {
		humanMovement = true
		if (debug) {
			console.log('%cMovement check passed', 'color: green')
		}
		events.forEach((name) => document.removeEventListener(name, handleMovement, { passive: true }))
	}

	events.forEach((name) => document.addEventListener(name, handleMovement, { passive: true }))
}

function checkDefaultValues() {
	const fields = document.querySelectorAll('.yn-form :is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
	fields.forEach((field) => {
		if (field.value) {
			defaultValueFields.push(field.getAttribute('id'))
		}
	})
}

/**
 * Records which required fields the visitor actually interacted with.
 *
 * Delegated on the document instead of bound per field: list forms clone their
 * rows from a <template> at runtime, so fields that exist at submit time did not
 * exist at setup time. Binding per field meant those rows could never be marked
 * as touched - the focus check re-queries the form on submit - and every submit
 * of a list form was scored as a bot (code 9) and sent to the captcha.
 */
function setFocusEvent() {
	const handleFocusOrInput = (e) => {
		const field = e.target
		if (!field || typeof field.matches !== 'function') {
			return
		}
		if (!field.matches(FOCUS_FIELD_SELECTOR)) {
			return
		}

		touchedFields.add(field)

		const id = field.getAttribute('id')
		if (id && !focusedElements.includes(id)) {
			focusedElements.push(id)
		}
	}

	// 'change' additionally covers selects, radios and checkboxes toggled
	// without a focus event reaching them.
	document.addEventListener('focusin', handleFocusOrInput, true)
	document.addEventListener('input', handleFocusOrInput, true)
	document.addEventListener('change', handleFocusOrInput, true)
}

function checkFocus(form) {
	const fields = form.querySelectorAll(':is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
	let notAllFieldsFocused = false

	const checkAutofill = (field) => {
		// Safer approach to detect autofill across browsers
		let hasAutofill = false
		try {
			// Try standard selector first
			if (field.matches(':autofill')) {
				hasAutofill = true
			}
		} catch (e) {
			// If standard selector fails, try vendor prefixes
			try {
				if (field.matches(':-webkit-autofill')) {
					hasAutofill = true
				}
			} catch (e2) {}

			try {
				if (field.matches(':-moz-autofill')) {
					hasAutofill = true
				}
			} catch (e3) {}
		}
		return hasAutofill
	}

	// Group by name: a required radio group renders `required` on EVERY option,
	// but a visitor only ever interacts with one of them. Judging each option
	// individually meant a required radio group could never pass this check and
	// every legitimate submit was penalized. A group counts as touched when any
	// member was focused/input/changed, is autofilled/prefilled, or (for
	// radios/checkboxes) any member is checked.
	const groups = new Map()
	fields.forEach((field) => {
		const groupKey = field.name || field.getAttribute('id') || Math.random().toString(36)
		if (!groups.has(groupKey)) {
			groups.set(groupKey, [])
		}
		groups.get(groupKey).push(field)
	})

	const fieldTouched = (field) =>
		touchedFields.has(field) ||
		focusedElements.includes(field.getAttribute('id')) ||
		checkAutofill(field) ||
		defaultValueFields.includes(field.getAttribute('id')) ||
		((field.type === 'radio' || field.type === 'checkbox') && field.checked)

	groups.forEach((members) => {
		if (!members.some(fieldTouched)) {
			notAllFieldsFocused = true
		}
	})

	if (notAllFieldsFocused) {
		addBotScore(form, '9', 'focus')
	} else {
		if (debug) {
			console.log('%cFocus check passed', 'color: green')
		}
	}
}

function botDCheck() {
	load({ monitoring: false })
		.then((botd) => botd.detect())
		.then((result) => {
			botD = result
			if (botD.bot) {
				addBotScore(null, '10', 'BotD')
			} else if (debug) {
				console.log('%cBotD check passed', 'color: green')
			}
		})
		.catch((error) => console.error(error))
}

/**
 * Storage writes throw - not return null - when storage is denied (sandboxed
 * iframe without allow-same-origin, cookies blocked entirely). setup() runs
 * these checks *before* attaching the submit listeners, so an uncaught throw
 * here used to leave the form doing a native POST straight to /yn-form/send,
 * which answers with raw JSON. Every access is guarded; a denied store is
 * scored as a signal, exactly like a store that silently drops the value.
 */
function localStorageCheck() {
	const probe = (write, read) => {
		try {
			write()
			return read() === renderedKey
		} catch (e) {
			return false
		}
	}

	const localOk = probe(
		() => localStorage.setItem('ynfinite-bot-protection', renderedKey),
		() => localStorage.getItem('ynfinite-bot-protection')
	)
	const sessionOk = probe(
		() => sessionStorage.setItem('ynfinite-bot-protection', renderedKey),
		() => sessionStorage.getItem('ynfinite-bot-protection')
	)
	const cookieOk = probe(
		() => (document.cookie = 'ynfinite-bot-protection=' + renderedKey + '; path=/'),
		() => (document.cookie.indexOf('ynfinite-bot-protection=' + renderedKey) === -1 ? '' : renderedKey)
	)

	if (!localOk) {
		addBotScore(null, '11', 'localStorage')
	} else if (debug) {
		console.log('%clocalStorage check passed', 'color: green')
	}

	if (!sessionOk) {
		addBotScore(null, '12', 'sessionStorage')
	} else if (debug) {
		console.log('%csessionStorage check passed', 'color: green')
	}

	if (!cookieOk) {
		addBotScore(null, '13', 'cookie')
	} else if (debug) {
		console.log('%ccookie check passed', 'color: green')
	}
}

function checkScreen() {
	if (window.screen.width > 0 && window.screen.height > 0) {
		if (debug) {
			console.log('%cscreen size check passed', 'color: green')
		}
	} else {
		addBotScore(null, '14', 'screen size')
	}
}

function createCaptcha(form) {
	// hidden.twig also renders a .yn-form-page (.hiddenFieldWrapper) - appending
	// the captcha there gives the visitor a required input they cannot see.
	const pages = form.querySelectorAll('.yn-form-page:not(.hiddenFieldWrapper)')
	const formPage = pages[pages.length - 1] || form.querySelector('.form-content') || form

	const row = document.createElement('div')
	row.classList.add('yn-form-grid-row')

	const col = document.createElement('div')
	col.classList.add('yn-form-grid-field', 'yn-form-grid-field-12')

	const captchaWrapper = document.createElement('div')
	captchaWrapper.classList.add('yn-captcha-wrapper', 'widget', 'widget--captcha')

	const label = document.createElement('label')
	label.classList.add('widget__label')
	label.textContent = 'Sicherheitsabfrage'
	captchaWrapper.appendChild(label)

	const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#000'
	const accentFont = getComputedStyle(document.documentElement).getPropertyValue('--accent-font') || '#fff'

	var charsArray = '123456789abcdefghjkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ@!?'
	var lengthOtp = 6
	var captcha = []
	for (var i = 0; i < lengthOtp; i++) {
		//below captchaCode will not allow Repetition of Characters
		var index = Math.floor(Math.random() * charsArray.length) //get the next character from the array
		if (captcha.indexOf(charsArray[index]) == -1) captcha.push(charsArray[index])
		else i--
	}
	var canv = document.createElement('canvas')
	canv.classList.add('yn-captcha')
	canv.width = 140
	canv.height = 60
	canv.style.backgroundColor = accentFont
	canv.style.borderRadius = 'var(--border-radius, 0px)'
	// Set fixed display size in CSS pixels to prevent stretching
	canv.style.width = '100%' // Match the canvas width
	canv.style.height = '60px'
	// Make canvas responsive to container while maintaining aspect ratio
	canv.style.objectFit = 'contain'
	canv.style.objectPosition = 'left'
	let ctx = canv.getContext('2d')
	ctx.font = '30px Georgia'
	ctx.strokeStyle = accent

	// Set normal opacity for context initially
	ctx.globalAlpha = 1.0
	// Draw some noise/pattern with reduced opacity
	ctx.save()
	ctx.globalAlpha = 0.5

	// Draw the noise lines
	for (let i = 0; i < 12; i++) {
		ctx.beginPath()
		ctx.lineWidth = 1 + Math.random() * 3
		ctx.moveTo(Math.random() * canv.width, Math.random() * canv.height)
		ctx.lineTo(Math.random() * canv.width, Math.random() * canv.height)
		ctx.stroke()
	}

	// Restore full opacity for the text
	ctx.restore()

	ctx.fillStyle = accent
	ctx.textAlign = 'center'
	ctx.textBaseline = 'middle'
	ctx.fillText(captcha.join(''), canv.width / 2, canv.height / 2)

	// Add input field for captcha
	var input = document.createElement('input')
	input.classList.add('captchaTextBox')
	input.type = 'text'
	input.name = 'captcha'
	input.placeholder = 'Wiederholen Sie den Code'
	input.required = true

	// Captcha answer is kept per form element, never in a shared module variable.
	captchaStates.set(form, { code: captcha.join('') })

	// create div widget__input-container and append input to it
	const inputContainer = document.createElement('div')
	inputContainer.classList.add('widget__input-container')
	inputContainer.appendChild(canv)
	inputContainer.appendChild(input)
	captchaWrapper.appendChild(inputContainer)
	col.appendChild(captchaWrapper)
	row.appendChild(col)

	formPage.appendChild(row)
	const captchaTextBox = form.querySelector('.captchaTextBox')
	if (captchaTextBox) {
		captchaTextBox.focus()
		captchaTextBox.reportValidity()
	}
}

function validateCaptcha(form) {
	const state = captchaStates.get(form)
	const captchaTextBox = form.querySelector('.captchaTextBox')

	if (!state || !captchaTextBox) {
		return false
	}

	return captchaTextBox.value === state.code
}

/**
 * True when the form carries a usable proof: a real 64-hex hash, not the
 * legacy "true" sentinel that stale cached HTML may still contain.
 */
function hasValidProof(form) {
	return form.getAttribute('data-has-proof') === 'true' && /^[0-9a-f]{64}$/.test(form.getAttribute('data-proofen-hash') || '')
}

/**
 * Resolves once the proof-of-work for this form is done.
 *
 * The proof normally starts on the first focusin and runs for at least 5
 * seconds. A submit that beats it (Enter key, keyboard activation, Safari's
 * click-without-focus, requestSubmit()) waits here instead of being scored as a
 * bot. The timeout is a safety net: if the proof never arrives we submit
 * anyway - PHP is the authority on proofs and answers with its normal error,
 * instead of the client permanently blocking the form.
 */
function waitForProof(form, timeout = 20000) {
	const isDone = () => hasValidProof(form)

	// Already proven, or the worker was never started (form never focused).
	if (isDone() || !form.dataset.working) {
		return Promise.resolve()
	}

	return new Promise((resolve) => {
		let settled = false

		const finish = () => {
			if (settled) return
			settled = true
			clearInterval(poll)
			clearTimeout(bail)
			form.removeEventListener('yn-proof-ready', finish)
			resolve()
		}

		const poll = setInterval(() => {
			if (isDone()) finish()
		}, 100)
		const bail = setTimeout(finish, timeout)

		form.addEventListener('yn-proof-ready', finish)
	})
}

const YnfiniteForms = {
	resetForm(element) {
		element.reset()
	},

	/**
	 * Public entry point for every submit path (submit event, onChange event,
	 * reset button, templates).
	 *
	 * Guards against overlapping submits of the same form: a proof of work can
	 * be used exactly once, so a double click - or a change event firing while
	 * a submit is still in flight - sent the same proof twice and PHP answered
	 * the second request with a replay rejection. The visitor saw "security
	 * check failed, please reload" right after a successful send, and every
	 * such rejection counted against the per IP rejection limit.
	 */
	async submitForm(element, event) {
		if (element.dataset.ynSubmitting === 'true') {
			if (debug) {
				console.log('%cSubmit ignored - previous submit of this form is still running', 'color: yellow')
			}
			return false
		}

		element.dataset.ynSubmitting = 'true'
		try {
			return await this.runSubmit(element, event)
		} finally {
			delete element.dataset.ynSubmitting
		}
	},

	async runSubmit(element) {
		const redirect = element.getAttribute('redirect')
		const method = element.getAttribute('method')
		const formSubmitButton = element.querySelector('button[type=submit]')

		// yn_nobot_token is server-rendered by PHP only when form.settings.noBotProtection is true.
		// Its HMAC value is signed with the API key, so a bot cannot compute a valid token without it.
		// Presence is used as the JS skip signal — faking it (adding a garbage input) doesn't help
		// the bot because PHP will reject any submission with an invalid HMAC.
		const isNoBotForm = !!element.querySelector('input[name="yn_nobot_token"]')

		// Never race the proof-of-work - see waitForProof(). If no proof exists
		// and none is being computed (form never focused, proof consumed by a
		// previous submit, stale cached nobot markup), ask botprotection.js to
		// start one lazily and wait for it.
		if (method !== 'get' && !isNoBotForm) {
			if (!hasValidProof(element) && !element.dataset.working) {
				element.dispatchEvent(new Event('yn-start-proof', { bubbles: true }))
			}
			await waitForProof(element, 30000)
		}

		// data-has-proof is an attribute, so its value is the *string* 'true' or
		// 'false' - and 'false' is truthy. Compare explicitly.
		const hasProof = method == 'get' ? true : element.getAttribute('data-has-proof') === 'true'
		const proofenHash = method == 'get' ? true : element.getAttribute('data-proofen-hash')
		const proofenNonce = method == 'get' ? '' : element.getAttribute('data-proofen-nonce') || ''
		const proofenPrevHash = method == 'get' ? '' : element.getAttribute('data-proofen-prev-hash') || ''
		const proofenTimestamp = method == 'get' ? '' : element.getAttribute('data-proofen-timestamp') || ''

		// Honeypot values are required by PHP on every POST, so fill them here
		// instead of relying on the submit button click handler alone.
		if (method !== 'get') {
			checkHoneypot(element)
		}

		if (!captchaStates.has(element) && !isNoBotForm && method !== 'get') {
			checkFocus(element)
			checkTryTypingConsistency(element)

			if (window.checkMouseMovementPatterns) {
				window.checkMouseMovementPatterns(element)
			}

			// A missing proof is NOT scored anymore (old codes 15/16, +100 each).
			// PHP hard-rejects any POST without a valid proof regardless, so the
			// client-side +100 added no security - it only hard-blocked humans
			// whose worker failed or whose device was too slow. If the proof is
			// still missing after waitForProof(), submit and let PHP answer.

			if (!humanMovement) {
				addBotScore(element, '17', 'movement')
			}

			if (debug) {
				console.log(`%cBot score: ${getBotScore(element)}`, getBotScore(element) >= 100 ? 'color: red' : 'color: green')
			}

			if (getBotScore(element) > 0 && getBotScore(element) < 100) {
				if (debug) {
					console.log('%cPossible Bot detected - adding captcha', 'color: yellow')
				}
				createCaptcha(element)
				return false
			}
		}

		if (captchaStates.has(element)) {
			const captchaValidation = validateCaptcha(element)
			if (!captchaValidation) {
				if (debug) {
					console.log('%cCaptcha check failed', 'color: red')
				}
				const captchaTextBox = element.querySelector('.captchaTextBox')
				if (captchaTextBox) {
					captchaTextBox.setCustomValidity('Der eingegebene Captcha-Code stimmt nicht überein. Bitte versuche es erneut.')
					captchaTextBox.reportValidity()
					captchaTextBox.focus()
					// Reset validation message after user starts typing
					captchaTextBox.addEventListener(
						'input',
						() => {
							captchaTextBox.setCustomValidity('')
						},
						{ once: true }
					)
				}
				return false
			} else if (debug) {
				console.log('%cCaptcha check passed', 'color: green')
			}
		}

		if (!isNoBotForm && method !== 'get' && getBotScore(element) >= 100) {
			if (formSubmitButton) {
				formSubmitButton.classList.remove('yn-loader')
				formSubmitButton.style.removeProperty('padding-left')
				formSubmitButton.style.borderColor = 'var(--error, red)'
				formSubmitButton.style.backgroundColor = 'var(--error, red)'
				formSubmitButton.style.color = 'var(--light, white)'
				formSubmitButton.style.pointerEvents = 'none'
				formSubmitButton.dataset.ynError = 'true'
				formSubmitButton.textContent = `Bot-Schutz fehlgeschlagen [${getErrorCodes(element).join(', ')}]. Bitte neu laden.`
			}
			return
		}

		if (redirect === 'true') {
			element.submit()
			return
		}

		if (method == 'post' && formSubmitButton) {
			const pos = 'var(--loader-size,16px) + ' + getComputedStyle(formSubmitButton).paddingLeft
			formSubmitButton.classList.add('yn-loader')
			formSubmitButton.style.paddingLeft = formSubmitButton.style.paddingLeft = 'calc(' + pos + ')'
			formSubmitButton.style.setProperty('--yn-loader-pos', 'calc((' + pos + ' - var(--loader-size,16px)) / 2);')
		}

		const ynBeforeAsyncChangeData = new Event('onPreAsyncChangeData')
		element.dispatchEvent(ynBeforeAsyncChangeData)

		const formData = new FormData(element)
		formData.set('_csrf_token', getCsrfToken())
		formData.set('events', element.getAttribute('data-events'))
		formData.set('method', method)
		formData.set('formId', element.getAttribute('data-ynformid'))
		formData.set('formLanguage', element.getAttribute('data-language'))
		formData.set('hasProof', hasProof ? 'true' : 'false')
		formData.set('proofenHash', isNoBotForm ? SHA256(getCsrfToken() + element.getAttribute('data-ynformid') + 'nobot').toString() : proofenHash)
		formData.set('proofenNonce', proofenNonce)
		formData.set('proofenPrevHash', proofenPrevHash)
		formData.set('proofenTimestamp', proofenTimestamp)
		// Telemetry only: PHP logs score and codes for threshold tuning, it never
		// enforces them (a bot could send anything here - the PoW/honeypot/CSRF
		// layers are the enforcement).
		if (method === 'post') {
			formData.set('botScore', String(getBotScore(element)))
			formData.set('botCodes', getErrorCodes(element).join(','))
		}
		if (element.hasAttribute('data-ynsectionid')) {
			formData.set('sectionId', element.getAttribute('data-ynsectionid'))
		}

		const action = element.getAttribute('action')

		const params = new URLSearchParams(window.location.search)
		const perPage = params.get('__yPerPage')

		if (perPage) {
			formData.append('__yPerPage', perPage)
		}

		const ynBeforeAsyncChange = new Event('onPreAsyncChange')
		element.dispatchEvent(ynBeforeAsyncChange)

		// A network failure must not skip the re-arm below: the proof is spent the
		// moment the request leaves the browser, so bailing out here left the
		// button spinning on a used proof, and the next submit was rejected as a
		// replay (and counted towards the rejection lockout).
		let response
		try {
			response = await fetch(action, {
				method: 'POST',
				body: formData,
			})
		} catch (e) {
			console.error(e)
			if (method === 'post' && !isNoBotForm) {
				element.dataset.hasProof = 'false'
				element.dataset.proofenHash = ''
				delete element.dataset.working
				element.dispatchEvent(new Event('yn-start-proof', { bubbles: true }))
			}
			if (method == 'post' && formSubmitButton) {
				formSubmitButton.classList.remove('yn-loader')
				formSubmitButton.style.removeProperty('padding-left')
				formSubmitButton.disabled = false
			}
			return
		}

		// PHP accepts each proof only once (replay protection), so this proof is
		// spent regardless of the outcome. Re-arm immediately: the next attempt
		// (fixing a validation error, submitting again) has a fresh proof ready
		// long before a human gets back to the submit button.
		if (method === 'post' && !isNoBotForm) {
			element.dataset.hasProof = 'false'
			element.dataset.proofenHash = ''
			delete element.dataset.working
			element.dispatchEvent(new Event('yn-start-proof', { bubbles: true }))
		}

		if (response.ok) {
			const jsonResponse = await response.json()
			switch (jsonResponse['type']) {
				case 'page':
					element.dispatchEvent(
						new CustomEvent('onAsyncChange', {
							detail: {
								response: jsonResponse,
							},
						})
					)
					// Clear any previous error message on success
					const errorElSuccess = element.querySelector('.yn-error')
					if (errorElSuccess) errorElSuccess.textContent = ''
					break
				case 'redirect':
					window.location.replace(jsonResponse['url'])
					break
				case '404':
				case 'error':
					console.log('404/Error: ', jsonResponse['message'])
					const errorEl = element.querySelector('.yn-error')
					if (errorEl) {
						errorEl.textContent = jsonResponse['message']
					} else if (method == 'post' && formSubmitButton) {
						formSubmitButton.classList.remove('yn-loader')
						formSubmitButton.style.removeProperty('padding-left')
						formSubmitButton.style.borderColor = 'var(--error, red)'
						formSubmitButton.style.backgroundColor = 'var(--error, red)'
						formSubmitButton.style.color = 'var(--light, white)'
						formSubmitButton.style.pointerEvents = 'none'
						formSubmitButton.dataset.ynError = 'true'
						formSubmitButton.textContent = 'Fehler. Bitte neu laden.'
					}
					break
			}

			if (method == 'post' && formSubmitButton) {
				formSubmitButton.classList.remove('yn-loader')
				formSubmitButton.style.removeProperty('padding-left')
			}
		} else {
			if (method == 'post' && formSubmitButton) {
				formSubmitButton.classList.remove('yn-loader')
				formSubmitButton.style.removeProperty('padding-left')
				formSubmitButton.style.backgroundColor = 'var(--error, red)'
				formSubmitButton.style.color = 'var(--light, white)'
				formSubmitButton.textContent = 'Error'
			}
			console.error(response)
		}
	},

	addChangeEvent(element) {
		const formInputElements = element.querySelectorAll('select, input')

		for (var i = 0; i < formInputElements.length; i++) {
			formInputElements[i].addEventListener('change', async (e) => {
				e.preventDefault()
				await this.submitForm(element, 'onChange')
			})
		}
	},

	addSubmitEvent(element) {
		element.addEventListener('submit', async (e) => {
			e.preventDefault()
			await this.submitForm(element, 'onSubmit')
		})
	},

	setupCheckboxValidation() {
		const forms = document.querySelectorAll('[data-ynform=true]')

		forms.forEach((form) => {
			const checkboxValidators = form.querySelectorAll('input[id$="_validator"][type="checkbox"]')

			checkboxValidators.forEach((validator) => {
				const container = validator.parentElement
				const checkboxes = container.querySelectorAll('input[type="checkbox"]:not([id$="_validator"])')

				const updateValidatorState = () => {
					const hasSelection = Array.from(checkboxes).some((cb) => cb.checked)

					if (hasSelection) {
						validator.checked = true
						validator.setCustomValidity('')
					} else {
						validator.checked = false
						validator.setCustomValidity('Please select at least one option.')
					}
				}

				checkboxes.forEach((checkbox) => {
					checkbox.addEventListener('change', updateValidatorState)
				})

				updateValidatorState()
			})
		})
	},

	setup() {
		const forms = document.querySelectorAll('[data-ynform=true]')

		if (forms) {
			this.setupCheckboxValidation()
			checkDefaultValues()
			botDCheck()
			localStorageCheck()
			setFocusEvent()
			checkHumanMovement()
			setHoneypotClickEvent()
			checkScreen()
			setupTypingAnalysis()
			checkBrowserEnvironment()
			trackMovements()
			dontFocusHoneypots()
		}

		forms.forEach((form) => {
			if (form.method === 'post') {
				// Add onAsyncChange event listener for async UI update
				form.addEventListener('onAsyncChange', function (e) {
					if (window.$_yn && window.$_yn.forms && typeof window.$_yn.forms.showResponse === 'function') {
						window.$_yn.forms.showResponse(this, e.detail.response)
					}
				})
			}

			if (form.hasAttribute('data-onchange')) {
				this.addChangeEvent(form)
				form.addEventListener('submit', async (e) => e.preventDefault()) // if we dont remove the submit event here, the second time you send the same formdata a submit will be triggert
			}

			if (form.hasAttribute('data-onsubmit') || !form.hasAttribute('data-onchange')) {
				this.addSubmitEvent(form)
			}

			// Handle reset action
			const resetButton = form.querySelector("button[type='reset']")
			if (resetButton) {
				resetButton.addEventListener('click', async () => {
					// Only clear the visitor's own fields. Blanking every input also
					// wiped the hidden security inputs (_csrf_token,
					// yn_required_fields_token, yn_form_method_token, yn_nobot_token,
					// yn_confirm_*), which made the following submit fail every check.
					const formInputElements = form.querySelectorAll('select:not([type=hidden]), input:not([type=hidden])')

					for (var i = 0; i < formInputElements.length; i++) {
						const field = formInputElements[i]
						if (field.name === 'yn_confirm_name' || field.name === 'yn_confirm_email' || field.name === 'consents[]_v2') {
							continue
						}
						field.value = ''
					}

					await this.submitForm(form)
				})
			}

			// Handle new form

			const newFormLink = form.querySelector('.yn-form-response__new-form')
			if (newFormLink) {
				newFormLink.addEventListener('click', (e) => {
					e.preventDefault()
					this.resetForm(form)

					// submitForm() re-arms the proof right after every POST, so a
					// fresh, unused proof usually already exists (or is being
					// computed) here. Only kick off a new one if neither is true.
					if (!hasValidProof(form) && !form.dataset.working) {
						form.dispatchEvent(new Event('yn-start-proof', { bubbles: true }))
					}

					const submitBtn = form.querySelector('button[type=submit]')
					if (submitBtn) {
						submitBtn.classList.remove('yn-loader', 'yn-botprotection')
						submitBtn.style.removeProperty('padding-left')
						if (submitBtn.dataset.label) {
							submitBtn.textContent = submitBtn.dataset.label
						}
					}

					// Remove captcha if it was injected into the form
					const captchaRow = form.querySelector('.yn-captcha-wrapper')?.closest('.yn-form-grid-row')
					if (captchaRow) captchaRow.remove()
					captchaStates.delete(form)

					// Judge the next attempt on its own signals. Page level checks
					// (BotD, storage, screen, environment) intentionally survive.
					resetSubmitScores(form)

					newFormLink.closest('form').querySelector('.form-content').classList.remove('inactive')
					newFormLink.closest('.yn-form-response').classList.remove('active')
				})
			}

			// Handle list fields
			const listFields = form.querySelectorAll('.yn-listForm-wrapper')

			listFields.forEach((listField) => {
				const newAction = listField.querySelector('.yn-listForm-actions-new')

				const rowTemplate = listField.querySelector('#listField_' + listField.dataset.ynformalias)

				const dataContainer = listField.querySelector('.yn-listForm-data')

				newAction.addEventListener('click', (e) => {
					e.preventDefault()
					const newRow = rowTemplate.content.cloneNode(true)
					newRow.className = 'yn-listForm-row'

					const deleteButton = newRow.querySelector('.yn-listForm-actions-delete')

					const fields = newRow.querySelectorAll('[data-ynfield=true]')
					fields.forEach((f) => {
						f.setAttribute('name', f.name.replace('::count::', dataContainer.childElementCount))
					})

					dataContainer.appendChild(newRow)

					deleteButton.addEventListener('click', (e) => {
						e.preventDefault()
						const row = e.target.closest('.yn-listForm-row')
						dataContainer.removeChild(row)
					})
				})
			})
		})
	},

	enableForm(form) {
		const fieldset = form.querySelector('fieldset')
		fieldset.disabled = false
	},

	disableForm(form) {
		const fieldset = form.querySelector('fieldset')
		fieldset.disabled = true
	},

	updateUrl(form) {
		const formData = new FormData(form)

		const data = new URLSearchParams()

		const params = new URLSearchParams(window.location.search)
		const perPage = params.get('__yPerPage')

		if (perPage) {
			data.append('__yPerPage', perPage)
		}

		for (const pair of formData) {
			if (pair[1]) {
				data.append(pair[0], pair[1])
			}
		}

		let newHref = `${window.location.protocol}//${window.location.hostname}${window.location.pathname}`
		if (data.toString()) {
			newHref += `?${data.toString()}`
		}

		history.pushState({}, '', newHref)
	},

	repopulateForm(form, data) {
		const keys = Object.keys(data.fields)
		for (var i = 0; i < keys.length; i++) {
			const element = data.fields[keys[i]]
			const formElement = form.querySelector(`[name="fields[${element.alias}]"]`)

			if (formElement && element && element.options) {
				let markup = `${element.options.map((option) => `<option value="${option.value}" ${option.value === element.value ? 'selected' : ''}>${option.label}</option>`).join('')}`
				if (!formElement.options[0].value) {
					markup = `<option value>${formElement.options[0].text}</option>${markup}`
				}

				formElement.innerHTML = markup
			}
		}
	},

	showResponse(form, data) {
		const responseContainer = form.querySelector('.yn-form-response')
		const formContent = form.querySelector('.form-content')

		const innerContainer = responseContainer.querySelector('.yn-form-response__inner')

		formContent.classList.add('inactive')
		innerContainer.innerHTML = data.rendered
		responseContainer.classList.add('active')
		responseContainer.scrollIntoView({
			behavior: 'auto',
			block: 'center',
			inline: 'center',
		})
	},
}

export default YnfiniteForms
