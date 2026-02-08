FROM node:20-alpine

# Install Chromium for diagram rendering
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    curl

# Configure Puppeteer for Alpine
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

WORKDIR /app

# Install mermaid-cli and Fastify web framework
RUN npm install -g @mermaid-js/mermaid-cli@latest
RUN npm install fastify @fastify/cors

COPY server.js .
COPY puppeteer-config.json .

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

CMD ["node", "server.js"]
