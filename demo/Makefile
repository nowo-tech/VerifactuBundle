# Makefile for VerifactuBundle Demo
# Symfony 8 demo with FrankenPHP

DEMOS := symfony8

.PHONY: help up-symfony8 down-symfony8 install-symfony8 verify-symfony8 release-check release-verify clean

help:
	@echo "VerifactuBundle - Demo Commands"
	@echo ""
	@echo "  up-symfony8       Start Symfony 8 demo"
	@echo "  down-symfony8     Stop Symfony 8 demo"
	@echo "  install-symfony8  Install demo dependencies"
	@echo "  verify-symfony8   Healthcheck demo (HTTP 200)"
	@echo "  release-check     Demo test coverage + release-verify"
	@echo "  release-verify    Up, healthcheck, down"
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

release-check:
	@$(MAKE) -C symfony8 release-check
	@echo "✅ Demo passed release-check."

clean:
	rm -rf symfony8/vendor symfony8/var symfony8/.phpunit.cache symfony8/coverage symfony8/coverage.xml

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
