#!/usr/bin/env node

import { readFile, writeFile, mkdir } from 'fs/promises'
import { dirname, resolve, join } from 'path'
import { createRequire } from 'module'
import { fileURLToPath } from 'url'
import chokidar from 'chokidar'
import { WebSocket } from 'ws'
import { readdirSync } from 'fs'

const require = createRequire(import.meta.url)
const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

const sass = require('sass')
const autoprefixer = require('autoprefixer')
const postcss = require('postcss')
const inputDir = resolve(__dirname, '../development/assets/scss')
const outputDir = resolve(__dirname, '../public/assets/css')

// Dynamically detect SCSS files to compile
function getAvailableScssFiles() {
	const scssFiles = []
	
	try {
		const files = readdirSync(inputDir)
		for (const file of files) {
			if (file.endsWith('.scss') && !file.startsWith('_')) {
				const name = file.replace('.scss', '')
				scssFiles.push(name)
			}
		}
		console.log(`📋 Found SCSS files: ${scssFiles.join(', ')}`)
	} catch (error) {
		console.error('❌ Error reading SCSS directory:', error.message)
	}
	
	return scssFiles
}

const scssFiles = getAvailableScssFiles()

// Hot reload WebSocket connection
let hotReloadWs = null

function connectHotReload() {
	if (isHotReload && !hotReloadWs) {
		try {
			hotReloadWs = new WebSocket('ws://localhost:3102')
			hotReloadWs.on('open', () => {
				console.log('🔗 Connected to hot reload server')
			})
			hotReloadWs.on('error', (err) => {
				// Silently fail if hot reload server is not running
				hotReloadWs = null
			})
		} catch (error) {
			// Silently fail if WebSocket connection fails
		}
	}
}

function notifyHotReload(type, file) {
	if (hotReloadWs && hotReloadWs.readyState === WebSocket.OPEN) {
		hotReloadWs.send(
			JSON.stringify({
				type,
				file: file.replace(process.cwd(), ''),
				timestamp: Date.now(),
			})
		)
	}
}

async function ensureDir(dir) {
	try {
		await mkdir(dir, { recursive: true })
	} catch (error) {
		if (error.code !== 'EEXIST') throw error
	}
}

async function compileSCSS(filename) {
	const inputFile = join(inputDir, `${filename}.scss`)
	const outputFile = join(outputDir, `${filename}.css`)

	try {
		console.log(`🎨 Compiling ${filename}.scss...`)

		// Compile SCSS with suppressed warnings
		const result = sass.compile(inputFile, {
			sourceMap: true,
			style: 'expanded',
			loadPaths: [resolve(__dirname, '../node_modules'), resolve(__dirname, '../development/assets/scss')],
			quietDeps: true,
			// Control warning verbosity based on command line args
			verbose: showWarnings,
			logger: showWarnings
				? undefined
				: {
						warn(message, options) {
							try {
								// Check if the warning is from FontAwesome (third-party) files
								const url = options?.span?.url?.toString() || ''
								const isFromFontAwesome = url.includes('_fontawesome') || url.includes('fontawesome.scss')

								// Suppress common deprecation warnings, especially from third-party libraries
								const shouldSuppress = message.includes('Sass @import rules are deprecated') || message.includes('Global built-in functions are deprecated') || message.includes('repetitive deprecation warnings omitted') || isFromFontAwesome

								if (!shouldSuppress) {
									const fileName = url ? ` (${url.split('/' ).pop()})` : ''
									console.warn(`⚠️  SCSS${fileName}:`, message.split('\n' )[0])
								}
							} catch (error) {
								// If there's any error in the logger, just suppress the warning
							}
						},
						debug: () => {},
				  },
		})

		// Process with PostCSS/Autoprefixer
		const processed = await postcss([
			autoprefixer({
				overrideBrowserslist: ['last 2 versions', '>1%', 'not dead'],
			}),
		]).process(result.css, {
			from: inputFile,
			to: outputFile,
			map: { prev: result.sourceMap },
		})

		// Ensure output directory exists
		await ensureDir(outputDir)

		// Write CSS file
		await writeFile(outputFile, processed.css)

		// Write source map
		if (processed.map) {
			await writeFile(`${outputFile}.map`, processed.map.toString())
		}

		console.log(`✅ Compiled ${filename}.css`)

		// Notify hot reload if enabled
		if (isHotReload) {
			notifyHotReload('css', outputFile)
		}
	} catch (error) {
		console.error(`❌ Error compiling ${filename}.scss:`, error.message)
	}
}

async function compileAll() {
	console.log('🚀 Building CSS files...')

	for (const file of scssFiles) {
		await compileSCSS(file)
	}

	console.log('✅ CSS build completed!')
}

// Watch mode
function startWatcher() {
	console.log('👀 Watching SCSS files for changes...')

	const watcher = chokidar.watch([join(inputDir, '**/*.scss')], { ignoreInitial: false })

	watcher.on('change', async (filePath) => {
		// Determine which main SCSS file to recompile
		const changedFile = filePath.replace(inputDir, '').replace(/\\/g, '/' ).substring(1)

		// If it's a main file, compile just that one
		for (const file of scssFiles) {
			if (changedFile === `${file}.scss`) {
				await compileSCSS(file)
				return
			}
		}

		// If it's a partial, recompile all files (safer approach)
		console.log(`📝 SCSS file changed: ${changedFile}`)
		console.log('🔄 Recompiling all SCSS files...')

		for (const file of scssFiles) {
			await compileSCSS(file)
		}
	})

	// Initial build
	compileAll()

	return watcher
}

// Check command line arguments
const args = process.argv.slice(2)
const isWatch = args.includes('--watch') || args.includes('-w')
const showWarnings = args.includes('--verbose') || args.includes('-v')
const isHotReload = args.includes('--hot')

// Connect to hot reload if enabled
if (isHotReload) {
	connectHotReload()
}

if (isWatch) {
	startWatcher()
} else {
	compileAll().catch(console.error)
}

export { compileAll, compileSCSS, startWatcher }
