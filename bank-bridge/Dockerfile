# LineLedger bank bridge: a tiny Node service that talks to an Actual Budget
# server through @actual-app/api. Build context is this directory.
FROM node:22-slim

ENV NODE_ENV=production \
    BRIDGE_PORT=5007 \
    BRIDGE_DATA_DIR=/data

WORKDIR /app

COPY package.json ./
# The API package version is pinned in package.json and must match the Actual
# server's version (see docker-compose.yml).
RUN npm install --omit=dev --no-audit --no-fund \
    && mkdir -p /data && chown -R node:node /data /app

COPY server.mjs ./

USER node
VOLUME ["/data"]
EXPOSE 5007

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.BRIDGE_PORT||5007)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.mjs"]
