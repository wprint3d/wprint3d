# syntax=docker/dockerfile:1.7

ARG NODE_IMAGE=docker.io/library/node:22-alpine
ARG ALPINE_IMAGE=docker.io/library/alpine:3.22

FROM ${NODE_IMAGE} AS pnpm-base

ARG PNPM_VERSION=10.34.5

RUN corepack enable && corepack prepare pnpm@${PNPM_VERSION} --activate

FROM pnpm-base AS development

RUN apk add --no-cache bash ca-certificates curl file git jq unzip

WORKDIR /app

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./

COPY entrypoint_dev.sh /app/entrypoint_dev.sh

ENTRYPOINT ["/app/entrypoint_dev.sh"]

FROM pnpm-base AS build

WORKDIR /app

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./

RUN --mount=type=cache,target=/pnpm/store,sharing=locked \
    pnpm config set store-dir /pnpm/store \
    && pnpm fetch --frozen-lockfile

COPY . .

RUN --mount=type=cache,target=/pnpm/store,sharing=locked \
    pnpm config set store-dir /pnpm/store \
    && pnpm install --offline --frozen-lockfile \
    && pnpm exec expo export -p web

FROM ${ALPINE_IMAGE} AS production

RUN apk add --no-cache bash lighttpd

WORKDIR /app

COPY --from=build /app/dist /app/dist
COPY entrypoint_prd.sh /app/entrypoint_prd.sh
COPY internal/lighttpd.conf /etc/lighttpd/lighttpd.conf

ENTRYPOINT ["/app/entrypoint_prd.sh"]
