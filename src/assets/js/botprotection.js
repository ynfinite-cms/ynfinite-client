import SHA256 from 'crypto-js/sha256'

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
		if (window.Worker) {
			const blockWorker = new Worker('/assets/vendor/ynfinite/js/worker.min.js')

			blockWorker.onmessage = (e) => {
				this.hash = e.data.hash
				this.data.form.dataset.hasProof = 'true'
				this.data.form.dataset.proofenHash = this.hash
				this.data.form.dataset.proofenNonce = String(e.data.nonce)
				this.data.form.dataset.proofenPrevHash = this.previousHash || '0'
				this.data.form.dataset.proofenTimestamp = String(this.timestamp)

				const formSubmitButton = this.data.form.querySelector('button[type=submit]')

				formSubmitButton.classList.remove('yn-loader')
				formSubmitButton.classList.remove('yn-botprotection')
				formSubmitButton.style.removeProperty('padding-left')
				formSubmitButton.textContent = formSubmitButton.dataset.label

				blockWorker.terminate()
				console.timeEnd()
			}

			console.time()

			blockWorker.postMessage({
				form: this.data['form'].dataset.ynformid || '',
				previousHash: this.previousHash || '0',
				timestamp: this.timestamp,
				difficulty,
				chances,
				minRunTime,
				csrfToken,
			})
		}
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
		const csrfToken = document.cookie.match(/(?:^|;\s*)_yncsrf=([^;]+)/)?.[1] || ''
		const blockchain = new BlockChain()

		// .yn-no-bot-protection is set by Twig on noBotProtection forms.
		// It is purely a UX signal — it controls whether to show the PoW spinner.
		// It does NOT skip any bot scoring in forms.js (all scoring runs on all forms).
		// A bot adding/removing this class cannot skip any checks; PHP validates the proof.
		const forms = document.querySelectorAll('form[data-ynform=true][method=post]:not(.yn-no-bot-protection)')

		forms.forEach((form) => {
			form.dataset.hasProof = 'false'
			form.dataset.proofenHash = ''

			const formSubmitButton = form.querySelector('button[type=submit]')

			form.addEventListener('focusin', function () {
				if (form.dataset.hasProof === 'false' && !form.dataset.working) {
					form.dataset.working = true

					const pos = 'var(--loader-size,16px) + ' + getComputedStyle(formSubmitButton).paddingLeft
					formSubmitButton.dataset.label = formSubmitButton.textContent
					formSubmitButton.style.paddingLeft = formSubmitButton.style.paddingLeft = 'calc(' + pos + ')'
					formSubmitButton.style.setProperty('--yn-loader-pos', 'calc((' + pos + ' - var(--loader-size,16px)) / 2);')
					formSubmitButton.classList.add('yn-botprotection')
					formSubmitButton.classList.add('yn-loader')
					formSubmitButton.textContent = 'Bot-Prüfung'

					const block = blockchain.addBlock(new Block({ form: form }))
					block.startProofOfWork(5, 1, 5000, csrfToken)
				}
			})
		})

		// noBotProtection forms: set hasProof and proofenHash immediately (no spinner/focusin needed).
		// forms.js scoring still runs — hasProof/proofenHash will be set here so those checks pass.
		// A bot adding this class still faces all JS scoring and PHP HMAC validation.
		const noBotForms = document.querySelectorAll('form[data-ynform=true][method=post].yn-no-bot-protection')
		noBotForms.forEach((form) => {
			const formId = form.getAttribute('data-ynformid') || ''
			form.dataset.hasProof = 'true'
			form.dataset.proofenHash = SHA256(csrfToken + formId + 'nobot').toString()
		})
	},
}

export default YnfiniteBotProtection
