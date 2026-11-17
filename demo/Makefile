# Makefile for VerifactuBundle Demo
# Symfony 8 demo with FrankenPHP

DEMOS := symfony8

.PHONY: help up-symfony8 down-symfony8 install-symfony8 verify-symfony8 release-check release-verify demo-smoke clean

help:
	@echo "VerifactuBundle - Demo Commands"
	@echo ""
	@echo "  up-symfony8       Start Symfony 8 demo"
	@echo "  down-symfony8     Stop Symfony 8 demo"
	@echo "  install-symfony8  Install demo dependencies"
	@echo "  verify-symfony8   Healthcheck demo (HTTP 200)"
	@echo "  release-check     Demo test coverage + release-verify"
	@echo "  release-verify    Up, healthcheck, down"
	@echo "  demo-smoke        REQ-TEST-011: up -> HTTP 200 on :8010 -> down"
	@echo "  clean             Remove vendor/cache from demo"
	@echo ""

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

verify-symfony8:
	@$(MAKE) -C symfony8 verify

release-verify:
	@$(MAKE) up-symfony8
	@sleep 10
	@$(MAKE) verify-symfony8
	@$(MAKE) down-symfony8

# REQ-TEST-011: boot the demo stack and expect HTTP 200 (final response, redirects followed), then tear it down.
demo-smoke:
	@for demo in $(DEMOS); do \
		port=8010; \
		echo "Demo smoke: $$demo (http://127.0.0.1:$$port)"; \
		$(MAKE) up-$$demo || { $(MAKE) down-$$demo 2>/dev/null || true; exit 1; }; \
		code=000; \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
			code=$$(curl -sL -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
			if [ "$$code" = "200" ]; then break; fi; \
			sleep 2; \
		done; \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "$$demo failed with HTTP $$code"; exit 1; fi; \
		echo "$$demo OK (HTTP $$code)"; \
	done

release-check:
	@$(MAKE) -C symfony8 release-check
	@echo "✅ Demo passed release-check."

up: up-symfony8

down: down-symfony8

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

clean:
	rm -rf symfony8/vendor symfony8/var symfony8/.phpunit.cache symfony8/coverage symfony8/coverage.xml

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
