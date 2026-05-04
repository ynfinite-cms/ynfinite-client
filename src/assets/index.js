//JS Files
import './js/general'

import YnfiniteAccordions from './js/accordions'
import YnfiniteBotProtection from './js/botprotection'
import YnfiniteConsents from './js/cookies'
import YnfiniteForms from './js/forms'
import YnfiniteFormSettings from './js/formSettings'
import YnfiniteLanguageSwitch from './js/languageSwitch'
import YnfiniteLogin from './js/login'

window.addEventListener('DOMContentLoaded', () => {
	YnfiniteForms.setup()
	YnfiniteAccordions.setup()
	YnfiniteConsents.setup()
	YnfiniteBotProtection.setup()
	YnfiniteLogin.setup()
	YnfiniteFormSettings.setup()
	YnfiniteLanguageSwitch.setup()
})

window.$_yn = {
	forms: {
		updateUrl: YnfiniteForms.updateUrl,
		repopulate: YnfiniteForms.repopulateForm,
		enable: YnfiniteForms.enableForm,
		disable: YnfiniteForms.disableForm,
		showResponse: YnfiniteForms.showResponse,
	},
}
