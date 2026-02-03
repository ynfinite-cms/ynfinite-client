import { load } from '@fingerprintjs/botd'

const debug = false
const renderedKey = Math.random().toString(36).substring(2)
const focusedElements = []
const defaultValueFields = []
let botD = undefined
let humanMovement = false
let botScore = 0
let inputTypingPatterns = []
let inputLastKeyTime = 0
let captchaExists = false
let captchaCode
let normalTypingConsistency = true
let errorCodes = []

function dontFocusHoneypots() {
	const honeypots = document.querySelectorAll('input[name="yn_confirm_name"], input[name="yn_confirm_email"], [name="consents[]_v2"]')

	// add event focusin, find the parent form, find .form-content, find the first form field and focus it
	honeypots.forEach((honeypot) => {
		honeypot.addEventListener('focusin', (e) => {
			const form = honeypot.closest('form')
			const formContent = form.querySelector('.form-content')
			const firstField = formContent.querySelector('input, textarea, select')
			firstField.focus()
		})
	})
}

function checkHoneypot(form) {
	const honeypot_name = form.querySelector('input[name="yn_confirm_name"], [name="consents[]_v2"]')
	if (!honeypot_name.value) {
		honeypot_name.value = renderedKey
	}

	if (honeypot_name && honeypot_name.value !== renderedKey) {
		botScore += 15
		if (!errorCodes.includes('1')) {
			errorCodes.push('1')
		}
		if (debug) {
			console.log('%cBot detected by name honeypot (added 15 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	} else if (debug) {
		console.log('%cHoneypot (name) check passed', 'color: green')
	}

	const honeypot_mail = form.querySelector('input[name="yn_confirm_email"]')
	if (!honeypot_mail.value) {
		honeypot_mail.value = 'my@email.com'
	}

	if (honeypot_mail && honeypot_mail.value !== 'my@email.com') {
		botScore += 15
		if (!errorCodes.includes('2')) {
			errorCodes.push('2')
		}
		if (debug) {
			console.log('%cBot detected by mail honeypot (added 15 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	} else if (debug) {
		console.log('%cHoneypot (mail) check passed', 'color: green')
	}

	const consent_honeypots = form.querySelectorAll('.yn_consents_v2')
	consent_honeypots.forEach((consent) => {
		const input = consent.querySelector('input[type="checkbox"]')
		if (input.checked) {
			botScore += 100
			if (!errorCodes.includes('3')) {
				errorCodes.push('3')
			}
			if (debug) {
				console.log('%cBot detected by consent honeypot (added 100 Score)', 'color: red')
				console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
			}
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

function checkTryTypingConsistency() {
	if (normalTypingConsistency === false) {
		botScore += 20
		if (!errorCodes.includes('4')) {
			errorCodes.push('4')
		}
		if (debug) {
			console.log(`%cBot detected by unnatural typing patterns (added 20 Score)`, 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	}
}

function setupTypingAnalysis() {
	const inputs = document.querySelectorAll('input, textarea')

	inputs.forEach((input) => {
		input.addEventListener('keydown', (e) => {
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
		botScore += 5
		if (!errorCodes.includes('5')) {
			errorCodes.push('5')
		}
		if (debug) {
			console.log('%cBot detected by missing navigator properties (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
		return
	}

	if (!window.devicePixelRatio || window.devicePixelRatio === 0) {
		botScore += 5
		if (!errorCodes.includes('6')) {
			errorCodes.push('6')
		}
		if (debug) {
			console.log('%cBot detected by suspicious devicePixelRatio (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
		return
	}

	if (typeof document.addEventListener !== 'function' || typeof window.setTimeout !== 'function') {
		botScore += 5
		if (!errorCodes.includes('7')) {
			errorCodes.push('7')
		}
		if (debug) {
			console.log('%cBot detected by missing core browser functions (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
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
	const checkMovementPatterns = () => {
		// Zu wenige aufgezeichnete Punkte sind verdächtig (falls Gerät keine Touch-Eingabe hat)
		const positions = mousePositions

		// Zu viele gerade Linien deuten auf einen Bot hin
		if (positions.length > 0) {
			if (straightLineCounter > positions.length * 0.5) {
				if (!errorCodes.includes('8')) {
					botScore += 20
					errorCodes.push('8')
					if (debug) {
						console.log('%cBot detected by suspicious straight line movements (added 20 Score)', 'color: red')
						console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
					}
				}
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
	const setTabIndexFocus = (e) => {
		// Check for Tab key specifically
		if (e.key === 'Tab') {
			humanMovement = true
			if (debug) {
				console.log('%cMovement check (tabindex) passed', 'color: green')
			}
			document.removeEventListener('keydown', setTabIndexFocus)
		}
	}

	document.addEventListener('keydown', setTabIndexFocus)

	const handleMovement = () => {
		humanMovement = true
		if (debug) {
			console.log('%cMovement check (mouse or touch) passed', 'color: green')
		}
		document.removeEventListener('mousemove', handleMovement)
		document.removeEventListener('touchmove', handleMovement)
	}

	document.addEventListener('mousemove', handleMovement)
	document.addEventListener('touchmove', handleMovement)
}

function checkDefaultValues() {
	const fields = document.querySelectorAll('.yn-form :is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
	fields.forEach((field) => {
		if (field.value) {
			defaultValueFields.push(field.getAttribute('id'))
		}
	})
}

function setFocusEvent() {
	const fields = document.querySelectorAll(':is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')

	fields.forEach((field) => {
		const handleFocusOrInput = () => {
			if (!focusedElements.includes(field.getAttribute('id'))) {
				focusedElements.push(field.getAttribute('id'))
				// Remove event listeners once the field has been focused
				field.removeEventListener('focusin', handleFocusOrInput)
				field.removeEventListener('input', handleFocusOrInput)
			}
		}

		// Handle manual focus events
		field.addEventListener('focusin', handleFocusOrInput)
		field.addEventListener('input', handleFocusOrInput)
	})
}

function checkFocus(form) {
	const fields = form.querySelectorAll(':is(input, select, textarea)[data-ynfield][required]:not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
	let notAllFieldsFocused = false

	const checkAutofill = (field) => {
		// Safer approach to detect autofill across browsers
		let hasAutofill = false
		console.log('field', field)
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

	fields.forEach((field) => {
		if (!focusedElements.includes(field.getAttribute('id')) && !checkAutofill(field) && !defaultValueFields.includes(field.getAttribute('id'))) {
			notAllFieldsFocused = true
		}
	})

	if (notAllFieldsFocused) {
		botScore += 20
		if (!errorCodes.includes('9')) {
			errorCodes.push('9')
		}
		if (debug) {
			console.log('%cBot detected by focus (added 20 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
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
				botScore += 5
				if (!errorCodes.includes('10')) {
					errorCodes.push('10')
				}
				if (debug) {
					console.log('%cBot detected by BotD (added 5 Score)', 'color: red')
					console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
				}
			} else if (debug) {
				console.log('%cBotD check passed', 'color: green')
			}
		})
		.catch((error) => console.error(error))
}

function localStorageCheck() {
	localStorage.setItem('ynfinite-bot-protection', renderedKey)
	sessionStorage.setItem('ynfinite-bot-protection', renderedKey)
	document.cookie = 'ynfinite-bot-protection=' + renderedKey + '; path=/'

	if (localStorage.getItem('ynfinite-bot-protection') !== renderedKey) {
		botScore += 5
		if (!errorCodes.includes('11')) {
			errorCodes.push('11')
		}
		if (debug) {
			console.log('%cBot detected by localStorage (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	} else if (debug) {
		console.log('%clocalStorage check passed', 'color: green')
	}

	if (sessionStorage.getItem('ynfinite-bot-protection') !== renderedKey) {
		botScore += 5
		if (!errorCodes.includes('12')) {
			errorCodes.push('12')
		}
		if (debug) {
			console.log('%cBot detected by sessionStorage (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	} else if (debug) {
		console.log('%csessionStorage check passed', 'color: green')
	}

	if (document.cookie.indexOf('ynfinite-bot-protection=' + renderedKey) === -1) {
		botScore += 5
		if (!errorCodes.includes('13')) {
			errorCodes.push('13')
		}
		if (debug) {
			console.log('%cBot detected by cookie (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
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
		botScore += 5
		if (!errorCodes.includes('14')) {
			errorCodes.push('14')
		}
		if (debug) {
			console.log('%cBot detected by screen size (added 5 Score)', 'color: red')
			console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
		}
	}
}

function createCaptcha(form) {
	const pages = form.querySelectorAll('.yn-form-page')
	const formPage = pages[pages.length - 1]

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
		var index = Math.floor(Math.random() * charsArray.length + 1) //get the next character from the array
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

	//storing captcha so that can validate you can save it somewhere else according to your specific requirements
	captchaCode = captcha.join('')
	captchaExists = true

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
	captchaTextBox.focus()
	captchaTextBox.reportValidity()
}

function validateCaptcha(form) {
	event.preventDefault()
	debugger
	if (form.querySelector('.captchaTextBox').value === captchaCode) {
		return true
	} else {
		return false
	}
}

const YnfiniteForms = {
	resetForm(element) {
		element.reset()
	},

	async submitForm(element) {
		const redirect = element.getAttribute('redirect')
		const method = element.getAttribute('method')
		const hasProof = method == 'get' ? true : element.getAttribute('data-has-proof')
		const proofenHash = method == 'get' ? true : element.getAttribute('data-proofen-hash')
		const formSubmitButton = element.querySelector('button[type=submit]')

		if (!captchaExists) {
			checkFocus(element)
			checkTryTypingConsistency()

			if (window.checkMouseMovementPatterns) {
				window.checkMouseMovementPatterns()
			}

			if (!hasProof) {
				botScore += 100
				if (!errorCodes.includes('15')) {
					errorCodes.push('15')
				}

				if (debug) {
					console.log('%cBot detected by missing hasProof (added 100 Score)', 'color: red')
					console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
				}
			} else {
				if (debug) {
					console.log('%cHasProof check passed', 'color: green')
				}
			}

			if (!proofenHash) {
				botScore += 100
				if (!errorCodes.includes('16')) {
					errorCodes.push('16')
				}

				if (debug) {
					console.log('%cBot detected by missing proofenHash (added 100 Score)', 'color: red')
					console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
				}
			} else {
				if (debug) {
					console.log('%cProofenHash check passed', 'color: green')
				}
			}

			if (!humanMovement) {
				botScore += 50
				if (!errorCodes.includes('17')) {
					errorCodes.push('17')
				}

				if (debug) {
					console.log('%cBot detected by movement (added 100 Score)', 'color: red')
					console.log('%cNew Botscore: ' + botScore, `color: ${botScore >= 100 ? 'red' : 'yellow'}`)
				}
			}

			if (botScore >= 100) {
				console.log(`%cBot score: ${botScore}`, 'color: red')
			} else if (botScore < 100) {
				console.log(`%cBot score: ${botScore}`, 'color: green')
			}

			if (botScore > 0 && botScore < 100) {
				if (debug) {
					console.log('%cPossible Bot detected - adding captcha', 'color: yellow')
				}
				createCaptcha(element)
				return false
			}
		}

		if (captchaExists) {
			const captchaValidation = validateCaptcha(element)
			if (!captchaValidation) {
				if (debug) {
					console.log('%cCaptcha check failed', 'color: red')
				}
				const captchaTextBox = element.querySelector('.captchaTextBox')
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
				return false
			} else if (debug) {
				console.log('%cCaptcha check passed', 'color: green')
			}
		}

		if (botScore >= 100) {
			console.log('&cSorry, there is no proof here that you are a human. The form can not be sent.', 'color: red')
			formSubmitButton.classList.remove('yn-loader')
			formSubmitButton.style.removeProperty('padding-left')
			formSubmitButton.style.borderColor = 'var(--error, red)'
			formSubmitButton.style.backgroundColor = 'var(--error, red)'
			formSubmitButton.style.color = 'var(--light, white)'
			formSubmitButton.style.pointerEvents = 'none'
			formSubmitButton.textContent = `Bot-Schutz fehlgeschlagen [${errorCodes.join(', ')}]. Bitte neu laden.`
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
		formData.set('events', element.getAttribute('data-events'))
		formData.set('method', method)
		formData.set('formId', element.getAttribute('data-ynformid'))
		formData.set('formLanguage', element.getAttribute('data-language'))
		formData.set('hasProof', hasProof)
		formData.set('proofenHash', proofenHash)
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

		const response = await fetch(action, {
			method: 'POST',
			body: formData,
		})

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
					break
				case 'redirect':
					window.location.replace(jsonResponse['url'])
					break
				case '404':
				case 'error':
					console.log('404/Error: ', jsonResponse['message'])
					element.querySelector('.yn-error').textContent = jsonResponse['message']
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
					const formInputElements = form.querySelectorAll('select, input')

					for (var i = 0; i < formInputElements.length; i++) {
						formInputElements[i].value = ''
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
