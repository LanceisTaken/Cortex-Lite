# Multi-stage production image: builds React, then serves it via nginx.
# Not used in dev — dev runs the Vite dev server on the host at :5173,
# and dev nginx is the plain nginx:alpine image with default.conf mounted.

FROM node:20-alpine AS client-builder
WORKDIR /build
COPY client/package.json client/package-lock.json ./
RUN npm ci
COPY client/ ./
RUN npm run build

FROM nginx:alpine
COPY docker/nginx/default.prod.conf /etc/nginx/conf.d/default.conf
COPY --from=client-builder /build/dist /usr/share/nginx/html
EXPOSE 80
