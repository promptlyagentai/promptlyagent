const fastify = require('fastify')({ logger: true })
const { exec } = require('child_process')
const fs = require('fs/promises')
const path = require('path')
const { promisify } = require('util')
const execPromise = promisify(exec)

fastify.register(require('@fastify/cors'))

// Health check
fastify.get('/health', async () => {
  return { status: 'healthy', service: 'mermaid-cli' }
})

// Render Mermaid diagram
fastify.post('/render', async (request, reply) => {
  const {
    code,
    format = 'svg',
    backgroundColor = 'transparent',
    theme = null,  // Optional: 'default', 'dark', 'neutral', 'forest', 'base'
    width = null,  // Optional: viewport width (can be used to override default)
    scale = null,  // Optional: scale factor (e.g. 2, 3, 4 for larger diagrams)
    maxWidth = null  // Optional: maximum output width in pixels (prevents oversized images)
  } = request.body

  console.log('Received params:', { theme, scale, backgroundColor, format, width, maxWidth })

  if (!code) {
    return reply.code(400).send({ error: 'code is required' })
  }

  const validFormats = ['svg', 'png', 'pdf']
  if (!validFormats.includes(format)) {
    return reply.code(400).send({
      error: `format must be one of: ${validFormats.join(', ')}`
    })
  }

  const tempDir = `/tmp/mermaid-${Date.now()}-${Math.random().toString(36).slice(2)}`
  const inputFile = path.join(tempDir, 'diagram.mmd')
  const outputFile = path.join(tempDir, `diagram.${format}`)
  const configFile = path.join(tempDir, 'config.json')

  try {
    await fs.mkdir(tempDir, { recursive: true })
    await fs.writeFile(inputFile, code, 'utf8')

    // Create config file with theme
    if (theme) {
      const config = {
        theme: theme,
        themeVariables: {},
        // Add left padding to prevent title cutoff
        pie: {
          textPosition: 0.5
        },
        // Increase diagram padding
        flowchart: {
          padding: 20
        }
      }
      await fs.writeFile(configFile, JSON.stringify(config), 'utf8')
    }

    // Build command with optional config file and scale
    let cmd = `mmdc -i "${inputFile}" -o "${outputFile}" -b ${backgroundColor} --puppeteerConfigFile /app/puppeteer-config.json`

    // Set viewport size to give more space for titles (prevents cutoff)
    let viewportWidth = width || 1200  // Wide viewport for long titles
    const viewportHeight = 800

    // Calculate smart scale factor based on maxWidth constraint
    let finalScale = scale || 1
    if (maxWidth && scale) {
      const projectedWidth = viewportWidth * scale
      if (projectedWidth > maxWidth) {
        // Reduce scale to fit within maxWidth constraint
        finalScale = Math.floor(maxWidth / viewportWidth * 10) / 10  // Round to 1 decimal
        fastify.log.info({
          originalScale: scale,
          finalScale,
          projectedWidth,
          maxWidth,
          reason: 'Scale reduced to prevent oversized image'
        }, 'Scale adjustment applied')
      }
    }

    cmd += ` -w ${viewportWidth} -H ${viewportHeight}`

    if (theme) {
      cmd += ` -c "${configFile}"`
    }
    if (finalScale && finalScale > 1) {
      cmd += ` -s ${finalScale}`  // Scale factor for larger/higher-res diagrams
    }

    fastify.log.info({ cmd, theme, scale: finalScale, backgroundColor, viewportWidth, maxWidth }, 'Executing mmdc command')
    await execPromise(cmd, { timeout: 30000 })

    const output = await fs.readFile(outputFile)
    const mimeTypes = {
      svg: 'image/svg+xml',
      png: 'image/png',
      pdf: 'application/pdf'
    }

    reply.header('Content-Type', mimeTypes[format])
    return reply.send(output)

  } catch (error) {
    fastify.log.error(error)
    return reply.code(500).send({
      error: 'Rendering failed',
      details: error.message
    })
  } finally {
    try {
      await fs.rm(tempDir, { recursive: true, force: true })
    } catch (cleanupError) {
      fastify.log.warn('Cleanup failed:', cleanupError)
    }
  }
})

// Convert arbitrary SVG to PNG using Puppeteer
fastify.post('/convert-svg', async (request, reply) => {
  const {
    svg,
    width = 2000,
    backgroundColor = 'white'
  } = request.body

  if (!svg) {
    return reply.code(400).send({ error: 'svg content is required' })
  }

  const tempDir = `/tmp/svg-convert-${Date.now()}-${Math.random().toString(36).slice(2)}`
  const htmlFile = path.join(tempDir, 'page.html')
  const outputFile = path.join(tempDir, 'output.png')

  try {
    await fs.mkdir(tempDir, { recursive: true })

    // Create HTML wrapper for SVG
    const html = `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { margin: 0; padding: 0; background: ${backgroundColor}; }
    svg { display: block; max-width: 100%; height: auto; }
  </style>
</head>
<body>${svg}</body>
</html>`

    await fs.writeFile(htmlFile, html, 'utf8')

    // Use Puppeteer via Node to render HTML to PNG
    const puppeteerScript = `
const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    executablePath: '/usr/bin/chromium'
  });
  const page = await browser.newPage();
  await page.goto('file://${htmlFile}', { waitUntil: 'networkidle0' });
  await page.setViewport({ width: ${width}, height: 800 });
  const element = await page.$('svg');
  if (element) {
    await element.screenshot({ path: '${outputFile}', type: 'png', omitBackground: ${backgroundColor === 'transparent'} });
  }
  await browser.close();
})();
`

    const scriptFile = path.join(tempDir, 'render.js')
    await fs.writeFile(scriptFile, puppeteerScript, 'utf8')
    await execPromise(`node "${scriptFile}"`, { timeout: 30000 })

    const output = await fs.readFile(outputFile)
    reply.header('Content-Type', 'image/png')
    return reply.send(output)

  } catch (error) {
    fastify.log.error(error)
    return reply.code(500).send({
      error: 'SVG conversion failed',
      details: error.message
    })
  } finally {
    try {
      await fs.rm(tempDir, { recursive: true, force: true })
    } catch (cleanupError) {
      fastify.log.warn('Cleanup failed:', cleanupError)
    }
  }
})

fastify.listen({ port: 3000, host: '0.0.0.0' }, (err, address) => {
  if (err) {
    fastify.log.error(err)
    process.exit(1)
  }
  fastify.log.info(`Mermaid service listening on ${address}`)
})
