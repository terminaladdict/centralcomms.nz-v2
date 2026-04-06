REMOTE_HOST := centralcomms.netent.co.nz
REMOTE_PATH := /var/www/dev
REMOTE_USER := paul

.PHONY: sync-data build push deploy

# Pull live notifications.json back before committing (skips gracefully on first deploy)
sync-data:
	rsync $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/assets/data/notifications.json \
		public/assets/data/notifications.json 2>/dev/null || echo "No remote notifications.json yet — using local copy"

# Build the Astro site
build:
	npm run build

# Push dist/ to the server.
# Excludes notifications-auth-config.php — it lives on the server but is not in the repo.
# After sync, ensure notifications.json is writable by the web server.
push:
	rsync -avz --delete \
		--exclude='assets/php/notifications-auth-config.php' \
		dist/ $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/
	ssh $(REMOTE_USER)@$(REMOTE_HOST) "chmod 777 $(REMOTE_PATH)/assets/data/notifications.json"

# Full workflow: pull live data → build → push
deploy: sync-data build push
