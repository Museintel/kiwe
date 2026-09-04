FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY src ./src
COPY runtime-config.example.json ./runtime-config.example.json
ENV PORT=3000 KIWE_KEY_STATE_DIR=/data/baileys
EXPOSE 3000
VOLUME ["/data"]
CMD ["node", "src/server.mjs"]
