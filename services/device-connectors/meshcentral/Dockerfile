FROM node:22-bookworm-slim

WORKDIR /opt/railtime-meshcentral-connector

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && test "$(node -p \"require('./node_modules/meshcentral/package.json').version\")" = "1.2.5"

COPY --chown=node:node src ./src

USER node
EXPOSE 9442

CMD ["node", "src/server.js", "--config", "/etc/railtime-meshcentral-connector/config.json"]
