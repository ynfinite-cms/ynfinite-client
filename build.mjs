#!/usr/bin/env node

import { spawn, execSync } from 'child_process'
import { writeFileSync } from 'fs'
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
		await runCommand('node', ['scripts/build-css.mjs'])

		// Build JavaScript files
		console.log('📦 Building JavaScript files...')
		await runCommand('npm', ['run', 'build:js'])

		// Deploy marker: StaticCache::buildStamp() compares every cache file's
		// mtime against this file's mtime. The FTP deploy uploads it with a fresh
		// mtime, which turns every page cached before the deploy into a cache
		// miss (lazy re-render) - no manual cache reset needed after deploys.
		let gitSha = ''
		try {
			gitSha = execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim()
		} catch {
			// not a git checkout (e.g. exported build) - timestamp alone is fine
		}
		writeFileSync('public/assets/vendor/ynfinite/js/build-version.txt', `${new Date().toISOString()}${gitSha ? ' ' + gitSha : ''}\n`)
		console.log('🏷️  Wrote build marker (build-version.txt)')

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
