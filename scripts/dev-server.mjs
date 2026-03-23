#!/usr/bin/env node

import browserSync from 'browser-sync'
import { WebSocketServer } from 'ws'
import { spawn, exec } from 'child_process'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import { promisify } from 'util'

const execAsync = promisify(exec)
const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

// Configuration
const config = {
	proxy: 'localhost',
	port: 3100,
	ui: {
		port: 3101,
	},
	websocketPort: 3102,
	open: false,
	notify: true,
	reloadDelay: 100,
}

// Function to kill processes using specific ports
async function killProcessesOnPorts(ports) {
	for (const port of ports) {
		try {
			const { stdout } = await execAsync(`lsof -ti:${port}`)
			const pids = stdout
				.trim()
				.split('\n')
				.filter((pid) => pid)

			if (pids.length > 0) {
				console.log(`🔄 Closing existing process on port ${port}...`)
				for (const pid of pids) {
					try {
						await execAsync(`kill -9 ${pid}`)
					} catch (error) {
						// Process might already be dead, ignore
					}
				}
				// Give processes time to close
				await new Promise((resolve) => setTimeout(resolve, 1000))
			}
		} catch (error) {
			// No processes found on this port, which is fine
		}
	}
}

// Fast, smart hot reload client script - moved outside initializeServer
function getHotReloadScript() {
	return `
(function() {
  'use strict';
  
  let ws = null;
  let isReloading = false;
  
  function initWebSocket() {
    ws = new WebSocket('ws://localhost:${config.websocketPort}');
    
    ws.onopen = () => {
      // Silent connection - no console log
    };
    
    ws.onclose = () => {
      setTimeout(initWebSocket, 2000);
    };
    
    ws.onerror = () => {
      // Silent error handling
    };
    
    ws.onmessage = (event) => handleMessage(JSON.parse(event.data));
  }
  
  function handleMessage(data) {
    if (isReloading) return;
    
    switch(data.type) {
      case 'css-update':
        handleSmartCSSUpdate(data);
        break;
      case 'full-reload':
        handleFullReload(data);
        break;
      case 'build-error':
        showNotification(\`❌ \${data.language.toUpperCase()} error in \${data.file}: \${data.message.split('\\n')[0]}\`, true);
        break;
    }
  }
  
  // Smart CSS update - only reload changed files
  function handleSmartCSSUpdate(data) {
    const changedFiles = new Set(data.changes.map(c => getFileNameFromPath(c.file)));
    const linksToUpdate = [];
    
    // Find which link elements need updating
    document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
      const fileName = getFileNameFromHref(link.href);
      if (changedFiles.has(fileName)) {
        linksToUpdate.push(link);
      }
    });
    
    if (linksToUpdate.length === 0) return;
    
    // Update only the changed CSS files
    updateCSSLinks(linksToUpdate, data.batchTimestamp);
    
    // Show clean notification
    const count = linksToUpdate.length;
    const message = count === 1 ? '🎨 CSS updated' : \`🎨 \${count} CSS files updated\`;
    showNotification(message);
  }
  
  function handleFullReload(data) {
    isReloading = true;
    const type = data.fileType === 'js' ? 'JavaScript' : 'Template';
    showNotification(\`📦 \${type} updated\`);
    setTimeout(() => window.location.reload(), 300);
  }
  
  // Update specific CSS links with aggressive cache busting
  function updateCSSLinks(links, timestamp) {
    links.forEach((link, index) => {
      requestAnimationFrame(() => {
        const baseHref = link.href.split('?')[0];
        const newHref = \`\${baseHref}?v=\${timestamp}&i=\${index}&r=\${Math.random().toString(36).substr(2, 5)}\`;
        
        const newLink = document.createElement('link');
        newLink.rel = 'stylesheet';
        newLink.type = 'text/css';
        newLink.href = newHref;
        
        newLink.onload = () => {
          link.remove();
        };
        
        newLink.onerror = () => {
          newLink.remove();
        };
        
        link.parentNode.insertBefore(newLink, link.nextSibling);
      });
    });
  }
  
  // Extract filename from file path
  function getFileNameFromPath(filePath) {
    return filePath.split('/').pop().split('.')[0];
  }
  
  // Extract filename from CSS href
  function getFileNameFromHref(href) {
    const url = new URL(href, window.location.origin);
    return url.pathname.split('/').pop().split('.')[0];
  }
  
  // Clean notification system
  function showNotification(message, isError = false) {
    const id = 'hot-reload-notification';
    let notification = document.getElementById(id);
    
    if (notification) notification.remove();
    
    notification = document.createElement('div');
    notification.id = id;
    notification.textContent = message;
    notification.style.cssText = 
      'position:fixed;top:20px;right:20px;background:' + (isError ? '#f44336' : '#4CAF50') + ';color:white;' +
      'padding:8px 12px;border-radius:6px;z-index:1000000;font-size:13px;' +
      'font-family:-apple-system,BlinkMacSystemFont,sans-serif;' +
      'box-shadow:0 2px 10px rgba(0,0,0,0.2);' +
      'transform:translateX(100%);transition:transform 0.2s ease;opacity:0.9;';
    
    document.body.appendChild(notification);
    
    requestAnimationFrame(() => {
      notification.style.transform = 'translateX(0)';
    });
    
    setTimeout(() => {
      if (notification.parentNode) {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => notification.remove(), 200);
      }
    }, isError ? 4000 : 1800);
  }
  
  // Initialize
  initWebSocket();
  
})();
`
}

// Initialize server after clearing ports
async function initializeServer() {
	// Kill any existing processes on our ports
	await killProcessesOnPorts([config.port, config.ui.port, config.websocketPort])

	// Create WebSocket server
	const wss = new WebSocketServer({ port: config.websocketPort })

	// Hot reload state
	const clients = new Set()
	let cssChangeTimeout = null
	let changedCSSFiles = new Map() // Map to track actual file changes with timestamps

	// Helper function for clean file path display
	function getCleanFileName(filePath) {
		return filePath.replace('public/assets/css/', '').replace('public/assets/js/', '')
	}

	// WebSocket connection handling
	wss.on('connection', (ws) => {
		clients.add(ws)
		console.log('🔗 Browser connected')

		ws.on('message', (message) => {
			try {
				const data = JSON.parse(message)
				if (data.type === 'css') {
					// Direct notification from build-css.mjs
					handleCSSChange(data.file)
				} else if (data.type === 'error') {
					// Broadcast error to browser
					broadcast({
						type: 'build-error',
						language: 'css',
						file: data.file.file,
						message: data.file.message,
					})
					console.log(`❌ CSS Build Error in ${data.file.file}: ${data.file.message.split('\n')[0]}`)
				}
			} catch (e) {
				// Ignore non-json messages
			}
		})

		ws.on('close', () => {
			clients.delete(ws)
			console.log('📱 Browser disconnected')
		})
	})

	// Broadcast to all clients
	function broadcast(message) {
		const payload = JSON.stringify(message)
		clients.forEach((ws) => {
			if (ws.readyState === ws.OPEN) {
				ws.send(payload)
			}
		})
	}

	// Smart CSS change handling - only track files that actually changed
	function handleCSSChange(filePath) {
		const fileName = getCleanFileName(filePath)
		changedCSSFiles.set(filePath, Date.now())

		clearTimeout(cssChangeTimeout)
		cssChangeTimeout = setTimeout(() => {
			const changes = Array.from(changedCSSFiles.keys())

			broadcast({
				type: 'css-update',
				changes: changes.map((file) => ({ file })),
				batchTimestamp: Date.now(),
			})

			console.log(`🎨 ${changes.length > 1 ? `${changes.length} styles` : getCleanFileName(changes[0])} updated`)
			changedCSSFiles.clear()
		}, 100)
	}

	// Handle full page reloads
	function handleFullReload(type, file) {
		broadcast({
			type: 'full-reload',
			fileType: type,
			file: file,
		})

		const fileName = getCleanFileName(file)
		const icon = type === 'js' ? '📦' : '🔄'
		console.log(`${icon} ${fileName} → reload`)
	}

	// Initialize BrowserSync
	const bs = browserSync.create()

	bs.init({
		proxy: config.proxy,
		port: config.port,
		ui: { port: config.ui.port },
		open: config.open,
		notify: config.notify,
		reloadDelay: config.reloadDelay,
		logLevel: 'silent', // Silence BrowserSync logs

		middleware: [
			{
				route: '/hot-reload.js',
				handle: (req, res) => {
					res.setHeader('Content-Type', 'application/javascript')
					res.setHeader('Cache-Control', 'no-cache')
					res.end(getHotReloadScript())
				},
			},
		],

		files: [
			{
				match: ['public/assets/css/**/*.css'],
				fn: (event, file) => {
					handleCSSChange(file)
				},
			},
			{
				match: ['public/assets/js/**/*.js'],
				fn: (event, file) => {
					bs.reload()
					handleFullReload('js', file)
				},
			},
			{
				match: ['**/*.php', '**/*.twig', 'templates/**/*'],
				fn: (event, file) => {
					bs.reload()
					handleFullReload('template', file)
				},
			},
		],

		snippetOptions: {
			rule: {
				match: /<\/body>/i,
				fn: (snippet, match) => `<script src="/hot-reload.js"></script>\n${snippet}${match}`,
			},
		},
	})

	// Start watchers
	console.log('🚀 Starting asset watchers...')

	const cssWatcher = spawn('npm', ['run', 'build:css', '--', '--watch', '--hot'], {
		stdio: 'pipe', // Capture output to control logging
		shell: true,
	})

	const jsWatcher = spawn('vite', ['build', '--watch'], {
		stdio: 'pipe', // Capture output to control logging
		shell: true,
		cwd: resolve(__dirname, '..'),
	})

	// Handle watcher output - pipe through directly with warning filters
	const filterWarnings = (data, stream) => {
		const str = data.toString()
		// Filter out common deprecation noise from child processes
		if (str.includes('Deprecation Warning') || str.includes('Sass @import rules are deprecated') || str.includes('Global built-in functions are deprecated') || str.includes('repetitive deprecation warnings omitted')) {
			return
		}
		stream.write(data)
	}

	cssWatcher.stdout.on('data', (d) => filterWarnings(d, process.stdout))
	cssWatcher.stderr.on('data', (d) => filterWarnings(d, process.stderr))

	jsWatcher.stdout.on('data', (d) => filterWarnings(d, process.stdout))
	jsWatcher.stderr.on('data', (d) => filterWarnings(d, process.stderr))

	process.on('SIGINT', () => {
		console.log('\n👋 Shutting down hot reload server...')
		cssWatcher.kill('SIGINT')
		jsWatcher.kill('SIGINT')
		bs.exit()
		process.exit(0)
	})

	// Clean startup message
	console.log('🔥 Hot Reload Server Started')
	console.log(`📍 Local: http://localhost:${config.port}`)
	console.log('🎯 Ready for changes...\n')
}

// Start the server
initializeServer().catch(console.error)
