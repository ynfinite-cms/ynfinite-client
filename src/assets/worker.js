import SHA256 from 'crypto-js/sha256'

let nonce = 0

function calculateHash(csrfToken, previousHash, timestamp, nonce, form) {
	return SHA256(csrfToken + previousHash + timestamp + form + nonce).toString()
}

onmessage = (e) => {
	const difficulty = e.data.difficulty
	const chances = Math.min(Math.max(e.data.chances, 0), 9)
	const form = e.data.form
	const timestamp = e.data.timestamp
	const previousHash = e.data.previousHash
	const minRunTime = e.data.minRunTime
	const csrfToken = e.data.csrfToken

	let chancesArray = []
	let hash = ''
	let hashFound = false
	let runTimeOver = false

	for (let i = 0; i <= chances; i++) {
		chancesArray.push(String(i).repeat(difficulty))
	}

	while (hashFound === false || runTimeOver === false) {
		if (hashFound === false) {
			nonce++
			hash = calculateHash(csrfToken, previousHash, timestamp, nonce, form)

			if (chancesArray.includes(hash.substring(0, difficulty))) {
				hashFound = true
			}
		} else if (hashFound === true && Date.now() - timestamp > minRunTime) {
			runTimeOver = true
		}
	}

	postMessage({ hash, nonce })
}
