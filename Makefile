# OllamaDev build/test/install workflow.
# Edit src/*.php, then `make` to build, test, and install.

INSTALL_PATH ?= $(HOME)/.local/bin/ollamadev

.PHONY: all build test install lint clean rollback

all: build test

build:
	@./build.sh

lint:
	@php -l ollamadev >/dev/null && echo "✓ lint ok"

# Run the smoke suite against the freshly built binary.
test: build
	@php tests/smoke.php

# Install only if the build + tests pass (test depends on build). The currently
# installed binary is backed up to <path>.bak first, so `make rollback` can
# instantly restore your live CLI even without git.
install: test
	@if [ -f "$(INSTALL_PATH)" ]; then cp "$(INSTALL_PATH)" "$(INSTALL_PATH).bak"; fi
	@cp ollamadev "$(INSTALL_PATH)" && chmod +x "$(INSTALL_PATH)" && echo "✓ installed to $(INSTALL_PATH) (previous saved to $(INSTALL_PATH).bak)"

# Restore the previously installed binary from its backup.
rollback:
	@if [ -f "$(INSTALL_PATH).bak" ]; then \
		cp "$(INSTALL_PATH).bak" "$(INSTALL_PATH)" && chmod +x "$(INSTALL_PATH)" && echo "✓ rolled back to previous installed binary"; \
	else echo "✗ no backup found at $(INSTALL_PATH).bak"; exit 1; fi

clean:
	@rm -f /tmp/ollamadev.orig && echo "✓ cleaned"
