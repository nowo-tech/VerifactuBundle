# Makefile for Verifactu Bundle Demo - Symfony 8
# Run from this directory: make up, make install, etc.

.PHONY: help up down restart build install shell logs test test-coverage update update-bundle ensure-up verify db-setup test-e2e

help:
	@echo "Verifactu Bundle Demo - Symfony 8"
	@echo ""
	@echo "  make up             Start containers"
	@echo "  make down            Stop containers"
	@echo "  make restart         Restart containers"
	@echo "  make build           Build image (no cache)"
	@echo "  make install         Install dependencies and link bundle from path"
	@echo "  make shell           Open shell in PHP container"
	@echo "  make logs            Show container logs"
	@echo "  make test            Run tests"
	@echo "  make test-coverage   Run tests with coverage"
	@echo "  make update          Run composer update"
	@echo "  make update-bundle   Update bundle from path repo"
	@echo "  make verify          Ensure up and check HTTP 200"
	@echo "  make db-setup        Create SQLite schema for hash chain"
	@echo "  make test-e2e        Playwright e2e: happy path, demo platform rules and axe (Docker image; DEMO_PORT overrides .env PORT)"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Installing dependencies (one-off container: the worker-mode server needs vendor/ before it starts)..."
	@$(COMPOSE) run --rm -T --no-deps --entrypoint composer -e COMPOSER_MEMORY_LIMIT=-1 php install --no-interaction --no-scripts
	$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@$(MAKE) link-bundle
	@$(MAKE) db-setup
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"
down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then \
		echo "Starting container..."; \
		if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi; \
		$(COMPOSE) up -d --build; \
		echo "Waiting for PHP service..."; \
		i=0; \
		while [ $$i -lt 60 ]; do \
			if $(COMPOSE) exec -T php true 2>/dev/null; then break; fi; \
			i=$$((i+1)); \
			sleep 2; \
		done; \
		$(COMPOSE) exec -T php true; \
	fi

install: ensure-up
	@$(COMPOSE) exec -T php sh -c 'rm -rf vendor/nowo-tech/verifactu-bundle 2>/dev/null; composer update --no-interaction'
	@$(MAKE) link-bundle
	@$(MAKE) db-setup

db-setup: ensure-up
	@$(COMPOSE) exec -T php sh -c 'mkdir -p var/data && php bin/console doctrine:schema:update --force --no-interaction'

link-bundle: ensure-up
	@$(COMPOSE) exec -T php sh -c 'rm -rf vendor/nowo-tech/verifactu-bundle; ln -sf /var/verifactu-bundle vendor/nowo-tech/verifactu-bundle && composer run-script post-install-cmd'

update: ensure-up
	$(COMPOSE) exec -T php composer update --no-interaction

update-bundle: ensure-up
	$(COMPOSE) exec -T php composer update nowo-tech/verifactu-bundle --no-interaction
	@$(MAKE) link-bundle
	$(COMPOSE) exec -T php php bin/console cache:clear --env=dev --no-warmup || true
	$(COMPOSE) restart php
	@sleep 3
	@echo "Bundle updated. Reload the page in the browser."

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec php composer test

test-coverage: ensure-up
	$(COMPOSE) exec php composer test-coverage

verify: ensure-up
	@PORT=$$(grep '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8001; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://localhost:$$PORT 2>/dev/null || echo "000"); \
	if [ "$$code" = "200" ]; then echo "✅ Demo responding (HTTP $$code)"; else echo "❌ Demo not responding (HTTP $$code)"; exit 1; fi

release-check: ensure-up update-bundle test verify
	@echo "✅ Demo passed release-check."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
COMPOSE ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
SERVICE_PHP := php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk

# REQ-DEMO-013: Playwright e2e (official Playwright image; the image tag follows the exact @playwright/test version pinned in e2e/package.json).
PLAYWRIGHT_VERSION := $(shell sed -n 's/.*"@playwright\/test": "\([0-9.]*\)".*/\1/p' e2e/package.json)
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v$(PLAYWRIGHT_VERSION)-jammy

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$${DEMO_PORT:-$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2)}; PORT=$${PORT:-8010}; \
	docker run --rm --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium'
