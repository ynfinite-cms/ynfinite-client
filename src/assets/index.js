//JS Files
import './js/general'

import YnfiniteConsents from './js/cookies'
import YnfiniteForms from './js/forms'
import YnfiniteBotProtection from './js/botprotection'
import YnfiniteAccordions from './js/accordions'
import YnfiniteLogin from './js/login'
import YnfiniteFormSettings from './js/formSettings'
import YnfiniteLanguageSwitch from './js/languageSwitch'
import YnfiniteCache from './js/cache'

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
	cache: YnfiniteCache,
	forms: {
		updateUrl: YnfiniteForms.updateUrl,
		repopulate: YnfiniteForms.repopulateForm,
		enable: YnfiniteForms.enableForm,
		disable: YnfiniteForms.disableForm,
		showResponse: YnfiniteForms.showResponse,
	},
}
