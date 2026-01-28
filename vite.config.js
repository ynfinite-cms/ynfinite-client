import { defineConfig } from 'vite'
import { resolve } from 'path'
import { readdirSync } from 'fs'

export default defineConfig({
	root: './development/assets',
	publicDir: false,

	build: {
		outDir: '../../public/assets',
		emptyOutDir: false,
		sourcemap: true,
		lib: false,

		rollupOptions: {
			input: (() => {
				const jsDir = resolve(process.cwd(), 'development/assets/js')
				const input = {}
				
				try {
					const files = readdirSync(jsDir)
					for (const file of files) {
						if (file.endsWith('.js') && !file.startsWith('_')) {
							const name = file.replace('.js', '')
							input[name] = resolve(process.cwd(), `development/assets/js/${file}`)
						}
					}
					console.log(`📋 Found JS entry points: ${Object.keys(input).join(', ')}`)
				} catch (error) {
					console.error('❌ Error reading JS directory:', error.message)
				}
				
				return input
			})(),

			output: {
				entryFileNames: 'js/[name].js',
				chunkFileNames: 'js/[name]-[hash].js',
				assetFileNames: (assetInfo) => {
					if (assetInfo.name && assetInfo.name.endsWith('.css')) {
						return 'css/[name][extname]'
					}
					return 'assets/[name][extname]'
				},
			},
		},

		minify: 'esbuild',
		target: 'es2015',
	},

	css: {
		preprocessorOptions: {
			scss: {
				quietDeps: true,
			},
		},
	},

	resolve: {
		alias: {
			'@': resolve(process.cwd(), 'development/assets'),
			'@scss': resolve(process.cwd(), 'development/assets/scss'),
			'@js': resolve(process.cwd(), 'development/assets/js'),
		},
	},

	server: {
		host: 'localhost',
		port: 3000,
		open: false,
	},
})
