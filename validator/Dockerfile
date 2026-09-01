# Konform dogrulama servisi.
#
# Kural seti yapi sirasinda indirilip derlenir; imaj kendi kendine yeter ve
# calisma aninda ag erisimi gerektirmez.

FROM node:22-slim AS build
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY package.json package-lock.json ./
RUN npm ci
COPY scripts ./scripts
ARG EN16931_VERSION=1.3.16
RUN EN16931_VERSION=$EN16931_VERSION node scripts/build-rules.mjs

FROM node:22-slim AS runtime
WORKDIR /app
ENV NODE_ENV=production PORT=8790
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=build /app/rules ./rules
COPY src ./src
# Kok olmayan kullanici; servis dosya yazmaz.
USER node
EXPOSE 8790
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD node -e "fetch('http://127.0.0.1:'+process.env.PORT+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "src/server.js"]
