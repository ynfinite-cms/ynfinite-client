import DOMPurify from 'dompurify'

function handleResponse(e, t) {
	t.json().then((t) => {
		const { success, error, inline: n, redirect: o, type: i } = t

		if (success) {
			;('information' === i && (e.innerHTML = DOMPurify.sanitize(n[0].template)),
				'redirect' === i && window.location.replace(o),
				'inline' === i &&
					n.forEach((e) => {
						if (e.selector) {
							const t = e.selector.substring(1)
							if ('.' === e.selector.charAt(0)) {
								document.getElementsByClassName(t).forEach((t) => {
									t.innerHTML = DOMPurify.sanitize(e.template)
								})
							}
							if ('#' === e.selector.charAt(0)) {
								const el = document.getElementById(t)
								if (el) el.innerHTML = DOMPurify.sanitize(e.template)
							}
						}
					}))
		} else {
			const errorContainer = e.querySelector('.yn-error')
			const li = document.createElement('li')
			li.dataset.status = error.status
			li.textContent = error.message
			const ul = document.createElement('ul')
			ul.appendChild(li)
			errorContainer.replaceChildren(ul)
		}
	})
}

function functSubmit(e) {
	e.preventDefault()
	const t = e.target,
		n = new FormData(t),
		o = e.target.getAttribute('data-ynfiniteid'),
		i = e.target.getAttribute('data-ynfinitelang'),
		c = e.target.getAttribute('data-ynfinite-content'),
		s = e.target.getAttribute('data-ynfinite-section'),
		sp = e.target.getAttribute('data-ynfinite-slugprefix')
	;(n.append('formId', o), n.append('lang', i), n.append('prefix', sp), n.append('contentId', c), n.append('sectionId', s), n.append('action', 'submit'))

	;(fetch(t.action, {
		method: t.method,
		body: n,
	}).then(handleResponse.bind(null, t)),
		e.preventDefault())
}

function functOnInput(e) {
	const { form: t } = e.target,
		n = new FormData(t),
		o = t.getAttribute('data-ynfiniteid'),
		i = t.getAttribute('data-ynfinitelang')
	;(n.append('formId', o),
		n.append('lang', i),
		n.append('action', 'change'),
		(('insertText' === e.inputType && e.target.value.length > 3) || 'insertText' !== e.inputType) &&
			fetch(t.action, {
				method: t.method,
				body: n,
			}).then(handleResponse.bind(null, t)),
		e.preventDefault())
}

const forms = document.querySelectorAll('[data-ynfiniteform="true"]')
for (const e of forms) e.addEventListener('submit', functSubmit)
const onChangeForms = document.querySelectorAll('[data-ynfiniteform-onchange="true"]')
onChangeForms.forEach(function (e) {
	for (var t, n = 0; (t = e.elements[n++]); ) t.addEventListener('input', functOnInput)
})
const buttons = document.querySelectorAll('.gdpr-button')
for (const e of buttons)
	e.addEventListener('click', function (e) {
		e.preventDefault()
		const t = e.target.getAttribute('data-formid'),
			n = e.target.getAttribute('data-formaction'),
			o = document.getElementById(t),
			i = new FormData(o)
		fetch(n || o.action, { method: o.method, body: i }).then((e) => {
			200 === e.status &&
				e.json().then((e) => {
					const responseDiv = document.createElement('div')
					responseDiv.className = 'response'
					responseDiv.textContent = e.message
					o.replaceChildren(responseDiv)
				})
		})
	})
