# Makefile for Verifactu Bundle
# Simplifies Docker commands for development

.PHONY: help up down build shell install test test-coverage coverage-php-percent coverage-check test-coverage-100 cs-check cs-fix qa clean assets ensure-up rector rector-dry phpstan release-check release-check-demos composer-sync update validate validate-translations setup-hooks check-no-cursor-coauthor strip-cursor-coauthor-from-history check-open-prs

# Default target
help:
	@echo "Verifactu Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up              Start Docker container"
	@echo "  down            Stop Docker container"
	@echo "  build           Rebuild Docker image (no cache)"
	@echo "  shell           Open shell in container"
	@echo "  install         Install Composer dependencies"
	@echo "  assets          No frontend assets in this bundle (no-op)"
	@echo "  test            Run PHPUnit tests"
	@echo "  test-coverage   Run tests with code coverage"
	@echo "  cs-check        Check code style"
	@echo "  cs-fix          Fix code style"
	@echo "  rector          Apply Rector refactoring"
	@echo "  rector-dry      Run Rector in dry-run mode"
	@echo "  phpstan         Run PHPStan static analysis"
	@echo "  qa              Run all QA checks (cs-check + test)"
	@echo "  release-check   Pre-release: git hygiene, composer-sync, QA, demos"
	@echo "  composer-sync   Validate composer.json and align composer.lock"
	@echo "  clean           Remove vendor and cache"
	@echo "  update          Update composer.lock (composer update)"
	@echo "  validate-translations  Validate translation YAML syntax and key parity"
	@echo "  update-deps            Update Composer deps in bundle and demos (REQ-MAKE-008)"
	@echo "  setup-hooks            Install .githooks (REQ-GIT-001 / REQ-MAKE-006)"
	@echo "  check-no-cursor-coauthor  Fail if Cursor co-author trailers in history"
	@echo "Demos:"
	@echo "  (use make -C demo or make -C demo/symfonyX)"
	@echo ""

# Rebuild Docker image (no cache)
build:
	docker-compose build --no-cache

# Build and start container
up:
	docker-compose build
	docker-compose up -d
	@echo "Installing dependencies..."
	docker-compose exec php composer install --no-interaction
	@echo "✅ Container ready!"

# Stop container
down:
	docker-compose down

# Ensure root container is running (start if not). Used by cs-fix, cs-check, qa, install, test, test-coverage.
ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then \
		echo "Starting container (root docker-compose)..."; \
		docker-compose up -d; \
		sleep 3; \
		docker-compose exec -T php composer install --no-interaction; \
	fi

# Open shell in container
shell:
	docker-compose exec php sh

# Install dependencies
install: ensure-up
	docker-compose exec -T php composer install

# Run tests (no -T so TTY is allocated and PHPUnit can show colors in console)
test: ensure-up
	docker-compose exec php composer test

# Run tests with coverage (no -T so coverage is shown in console with colors)
test-coverage: ensure-up
	docker-compose exec php composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

coverage-check: ensure-up
	docker-compose exec -T php php .scripts/coverage-check-100.php

test-coverage-100: test-coverage coverage-check

# Check code style
cs-check: ensure-up
	docker-compose exec -T php composer cs-check

# Fix code style
cs-fix: ensure-up
	docker-compose exec -T php composer cs-fix

# Run Rector (apply refactoring)
rector: ensure-up
	docker-compose exec -T php composer rector

# Run Rector in dry-run mode
rector-dry: ensure-up
	docker-compose exec -T php composer rector-dry

# Run PHPStan static analysis
phpstan: ensure-up
	docker-compose exec -T php composer phpstan

# Validate composer.json and verify composer.lock matches (does not rewrite the lock file)
composer-sync: ensure-up
	docker-compose exec -T php composer validate --strict
	docker-compose exec -T php composer install --dry-run --no-interaction

# Update composer.lock
update: ensure-up
	docker-compose exec -T php composer update --no-interaction

# Validate composer.json
validate: ensure-up
	docker-compose exec -T php composer validate --strict

# Run all QA
qa: ensure-up
	docker-compose exec -T php composer qa

# Pre-release: git hygiene, composer-sync, cs-fix, cs-check, rector-dry, phpstan, test-coverage, demos
release-check: check-no-cursor-coauthor check-open-prs ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage-100 release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

setup-hooks:
	@chmod +x .githooks/commit-msg .githooks/pre-commit 2>/dev/null || true
	@chmod +x .scripts/check-no-cursor-coauthor.sh .scripts/strip-cursor-coauthor-from-history.sh 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# No frontend assets in this bundle
assets:
	@echo "No frontend assets in this bundle."

# Clean vendor and cache
clean:
	rm -rf vendor
	rm -rf .phpunit.cache
	rm -rf coverage
	rm -f coverage.xml
	rm -f .php-cs-fixer.cache


# Validate bundle translation YAML files and key parity
validate-translations: ensure-up
	docker-compose exec -T php php .scripts/validate-translations.php

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
COMPOSE := docker-compose
SERVICE_PHP := php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
