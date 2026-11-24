# Caddy с плагином rate limit (D8). Версия Caddy та же, что была в compose.yml.
# Плагин: github.com/mholt/caddy-ratelimit, коммит master от 2026-06-12
# (go-pseudo-version v0.1.1-0.20260612195517-5625512f24f6; последний тег v0.1.0 старый,
# в нём нет ipv6_prefix, log_key и др.). Источник: proxy.golang.org/.../@v/master.info.
ARG CADDY_VERSION=2.11.9

FROM caddy:${CADDY_VERSION}-builder-alpine AS builder
ARG CADDY_VERSION
RUN xcaddy build v${CADDY_VERSION} \
    --with github.com/mholt/caddy-ratelimit@5625512f24f6f59d6f64fb3aafe5eecff0b286db

FROM caddy:${CADDY_VERSION}-alpine
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
