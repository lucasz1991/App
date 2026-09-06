FROM node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5

WORKDIR /opt/railtime-meshcentral-connector

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && node -e "if (require('./node_modules/meshcentral/package.json').version !== '1.2.5') process.exit(1)"

COPY --chown=node:node src ./src

USER node
EXPOSE 9442

CMD ["node", "src/server.js", "--config", "/etc/railtime-meshcentral-connector/config.json"]
