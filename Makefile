.PHONY: phar static static-linux static-linux-arm64 static-macos static-macos-arm64 static-all docker-linux docker-linux-arm64 clean

BINARY_NAME = typo3-to-confluence
BUILDS_DIR  = builds

# Build the PHAR archive
phar:
	composer build

# Build static binary for the current platform
static: phar
	./bin/build-static

# Native static builds (must be run on the matching OS)
static-linux: phar
	./bin/build-static linux-x86_64

static-linux-arm64: phar
	./bin/build-static linux-aarch64

static-macos: phar
	./bin/build-static macos-x86_64

static-macos-arm64: phar
	./bin/build-static macos-aarch64

# Build for all natively-possible platforms
static-all: phar
	./bin/build-static all

# Build Linux binary via Docker (works from macOS)
docker-linux:
	docker build --platform linux/amd64 -f Dockerfile.build --output "$(BUILDS_DIR)" --target export .
	mv "$(BUILDS_DIR)/$(BINARY_NAME)" "$(BUILDS_DIR)/$(BINARY_NAME)-linux-x86_64"
	@echo "Built: $(BUILDS_DIR)/$(BINARY_NAME)-linux-x86_64"

docker-linux-arm64:
	docker build --platform linux/arm64 -f Dockerfile.build --output "$(BUILDS_DIR)" --target export .
	mv "$(BUILDS_DIR)/$(BINARY_NAME)" "$(BUILDS_DIR)/$(BINARY_NAME)-linux-aarch64"
	@echo "Built: $(BUILDS_DIR)/$(BINARY_NAME)-linux-aarch64"

# Clean build artifacts
clean:
	rm -rf $(BUILDS_DIR)/*.phar $(BUILDS_DIR)/$(BINARY_NAME)-* .spc
