# Frontend de cherryPOS, servido por nginx junto a la API.
#
# La aplicación y la API comparten origen a propósito: el service worker de la
# PWA exige HTTPS y un mismo origen simplifica el certificado del local.
FROM node:22-alpine AS build

RUN corepack enable

WORKDIR /repo

COPY pnpm-workspace.yaml package.json ./
COPY packages/calc ./packages/calc
COPY apps/pos ./apps/pos

RUN pnpm install --frozen-lockfile --filter @cherrypos/pos... \
    && pnpm --filter @cherrypos/pos build

FROM nginx:1.27-alpine

COPY --from=build /repo/apps/pos/dist /usr/share/nginx/html
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
