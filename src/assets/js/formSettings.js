const YnfiniteFormSettings = {
	setup() {
		// Accessibility & MultiGroup Setup
		const forms = document.querySelectorAll('form')

		if (!forms) {
			return
		}

		const toggleHide = (button, condition) => {
			if (condition) {
				button.style.display = 'none'
			} else {
				button.style.display = ''
			}
		}

		const rerenderGroups = (groups, activeIndex, maxIndex, submitButtons, prevButton, nextButton, firstClick = false) => {
			const fieldErrors = groups[activeIndex - 1].querySelectorAll('.yn-field-error')
			if (fieldErrors) {
				fieldErrors.forEach((f) => f.classList.remove('yn-field-error'))
			}

			groups.forEach((g, index) => {
				index++
				toggleHide(g, index !== activeIndex)

				if (index === activeIndex) {
					const firstElement = g.querySelector(':is(select, input, textarea):not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
					if (firstElement && !firstClick) firstElement.focus()
					rerenderButtons(groups, activeIndex, maxIndex, submitButtons, prevButton, nextButton)
				}
			})
		}

		const rerenderButtons = (groups, activeIndex, maxIndex, submitButtons, prevButton, nextButton) => {
			toggleHide(prevButton, activeIndex <= 1)
			toggleHide(nextButton, activeIndex >= maxIndex)
			submitButtons.forEach((submitButton) => {
				toggleHide(submitButton, activeIndex !== maxIndex)
			})
		}

		const validateFieldGroup = (groups, activeIndex) => {
			const fields = groups[activeIndex - 1].querySelectorAll(':is(input, select, textarea):not([tabindex="-1"], [type="hidden"], .hidden, [name="yn_confirm_name"], [name="consents[]_v2"])')
			const invalidFields = Array.from(fields).filter((f) => !f.checkValidity())

			if (invalidFields) {
				invalidFields.forEach((f) => {
					f.reportValidity()
					f.classList.add('yn-field-error')
				})
			}

			return invalidFields.length === 0 ? true : false
		}

		forms.forEach((form) => {
			const groups = Array.from(form.querySelectorAll('.form-content .yn-form-page:not(.hiddenFieldWrapper)'))

			if (groups.length > 1) {
				let activeIndex = 1
				const submitButtons = form.querySelectorAll('[type=submit]:not([tabindex="-1"], [type="hidden"], .hidden)')
				const consents = form.querySelector('.yn-consents')
				const formContent = form.querySelector('.form-content')

				// move to first group after send
				form.addEventListener('onAsyncChange', () => {
					activeIndex = 1
					rerenderGroups(groups, activeIndex, groups.length, submitButtons, prevButton, nextButton)
				})

				// move consents in last group
				if (consents) {
					groups[groups.length - 1].appendChild(consents)
				}
				// temp remove submitButtons
				form.querySelectorAll('[type=submit]:not([tabindex="-1"], [type="hidden"], .hidden)').forEach((btn) => {
					btn.remove()
				})

				// add Next and Prev button
				let prevButton = document.createElement('a')
				prevButton.type = 'button'
				prevButton.title = window.ynTranslations?.yn_previous || 'Zurück'
				prevButton.role = 'button'
				prevButton.ariaLabel = window.ynTranslations?.yn_previous || 'Zurück'
				prevButton.tabIndex = '0'
				prevButton.innerHTML = window.ynTranslations?.yn_previous || 'Zurück'
				prevButton.classList.add('button', 'button-prev')
				prevButton.style.marginRight = 'auto'
				const prevButtonFunc = () => {
					activeIndex--
					rerenderGroups(groups, activeIndex, groups.length, submitButtons, prevButton, nextButton)
				}
				prevButton.addEventListener('click', prevButtonFunc)
				prevButton.addEventListener('keydown', (event) => {
					if (event.key === 'Enter') {
						event.preventDefault()
						prevButtonFunc()
					}
				})

				const buttonClasses = form.getAttribute('data-button-classes') || undefined
				let nextButton = document.createElement('a')
				nextButton.type = 'button'
				nextButton.title = window.ynTranslations?.yn_next || 'Weiter'
				nextButton.role = 'button'
				nextButton.ariaLabel = window.ynTranslations?.yn_next || 'Weiter'
				nextButton.tabIndex = '0'
				nextButton.innerHTML = window.ynTranslations?.yn_next || 'Weiter'
				nextButton.classList.add('button', 'button-next')
				if (buttonClasses) {
					buttonClasses.split(' ').forEach((c) => {
						nextButton.classList.add(c)
					})
				}
				const nextButtonFunc = () => {
					if (validateFieldGroup(groups, activeIndex)) {
						activeIndex++
						rerenderGroups(groups, activeIndex, groups.length, submitButtons, prevButton, nextButton)
					}
				}
				nextButton.addEventListener('click', nextButtonFunc)
				nextButton.addEventListener('keydown', (event) => {
					if (event.key === 'Enter') {
						event.preventDefault()
						nextButtonFunc()
					}
				})

				// render buttons
				let buttonGroup = document.createElement('div')
				buttonGroup.classList.add('yn-buttons', 'flex', 'justify-end', 'gap', 'gap-half', 'align-center')

				buttonGroup.appendChild(prevButton)
				buttonGroup.appendChild(nextButton)
				if (submitButtons) {
					submitButtons.forEach((b) => {
						buttonGroup.appendChild(b)
					})
				}
				formContent.appendChild(buttonGroup)

				// first group render
				rerenderGroups(groups, activeIndex, groups.length, submitButtons, prevButton, nextButton, true)
				if (consents) {
					consents.style.display = ''
				}
			}
		})
	},
}

module.exports = YnfiniteFormSettings
