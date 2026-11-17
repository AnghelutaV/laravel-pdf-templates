# Node renderer of anghelutav/laravel-pdf-templates: WYSIWYG (pdfme) PDFs, PNG pages and the Chrome engine (Chromium).
# Build from the package root (server.mjs reads resources/assets/*.js):
#   docker build -f renderer/Dockerfile -t pdf-renderer .
#   docker build -f vendor/anghelutav/laravel-pdf-templates/renderer/Dockerfile vendor/anghelutav/laravel-pdf-templates
# Run: docker run -d -p 127.0.0.1:3000:3000 -e PDFME_RENDERER_TOKEN=secret --init pdf-renderer
FROM node:22-bookworm-slim

# Chromium for the Chrome engine; fonts so text in HTML templates looks the same as on a desktop
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium ca-certificates dumb-init \
       fonts-dejavu-core fonts-liberation fonts-noto-core fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

ENV NODE_ENV=production \
    PUPPETEER_SKIP_DOWNLOAD=1 \
    CHROME_PATH=/usr/bin/chromium \
    HOST=0.0.0.0 \
    PORT=3000 \
    RENDERER_CONCURRENCY=2 \
    RENDERER_QUEUE_MAX=50 \
    RENDERER_BROWSER_RECYCLE=200

WORKDIR /app/renderer
COPY renderer/package.json ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force
COPY renderer/server.mjs ./
COPY resources/assets/placeholders.js resources/assets/barcode.js resources/assets/charts.js /app/resources/assets/

USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:'+process.env.PORT+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
ENTRYPOINT ["dumb-init", "--"]
CMD ["node", "server.mjs"]
