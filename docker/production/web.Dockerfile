# Production web image: the React SPA built by Vite (static files, no dev
# server, no HMR) served by nginx, which also forwards /api, /sanctum,
# /cancelar and /up to PHP-FPM (backend:9000) — one origin for everything.
# Built from the repository root (docker-compose.prod.yml).
FROM node:24-alpine AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.27-alpine
# Rendered by the image's envsubst step at start-up (only PUBLIC_SCHEME and
# REAL_IP_FROM, see NGINX_ENVSUBST_FILTER); nginx's own $variables are left
# untouched.
COPY docker/production/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html
ENV PUBLIC_SCHEME=https \
    REAL_IP_FROM=172.16.0.0/12 \
    NGINX_ENVSUBST_FILTER=^(PUBLIC_SCHEME|REAL_IP_FROM)$
EXPOSE 80
