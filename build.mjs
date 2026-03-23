#!/usr/bin/env node

import { spawn } from 'child_process'
import { resolve } from 'path'

async function runCommand(command, args = [], options = {}) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, {
			stdio: 'inherit',
			shell: true,
			...options,
		})

		child.on('close', (code) => {
			if (code === 0) {
				resolve()
			} else {
				reject(new Error(`Command failed with exit code ${code}`))
			}
		})

		child.on('error', reject)
	})
}

async function buildAssets() {
	console.log('🚀 Building all assets...')

	try {
		// Build CSS files
		console.log('🎨 Building SCSS files...')
		await runCommand('node', ['scripts/build-css.mjs', '--minify'])

		// Build JavaScript files
		console.log('📦 Building JavaScript files...')
		await runCommand('npm', ['run', 'build:js'])

		console.log('✅ All assets built successfully!')
	} catch (error) {
		console.error('❌ Build failed:', error.message)
		process.exit(1)
	}
}

// Check if this is run as a script
if (import.meta.url === `file://${process.argv[1]}`) {
	buildAssets().catch(console.error)
}

export { buildAssets }
