REMOTE_HOST := centralcomms.netent.co.nz
REMOTE_PATH := /var/www/html
REMOTE_USER := paul

.PHONY: sync-data sync-updates build push deploy

# Pull live notifications.json back before building
sync-data:
	rsync $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/assets/data/notifications.json \
		public/assets/data/notifications.json 2>/dev/null || echo "No remote notifications.json yet — using local copy"

# Pull live updates.json and any new/updated post images back before building
sync-updates:
	rsync $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/assets/data/updates.json \
		public/assets/data/updates.json 2>/dev/null || echo "No remote updates.json yet — using local copy"
	rsync -av $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/assets/images/posts/ \
		public/assets/images/posts/ 2>/dev/null || echo "No remote post images yet"

# Build the Astro site
build:
	npm run build

# Push dist/ to the server.
# Excludes server-only auth config files.
# After push, ensure data files are writable by the web server.
push:
	rsync -avz --delete \
		--exclude='assets/php/notifications-auth-config.php' \
		--exclude='assets/php/updates-auth-config.php' \
		dist/ $(REMOTE_USER)@$(REMOTE_HOST):$(REMOTE_PATH)/
	ssh $(REMOTE_USER)@$(REMOTE_HOST) \
		"chmod 666 $(REMOTE_PATH)/assets/data/notifications.json $(REMOTE_PATH)/assets/data/updates.json; chmod 777 $(REMOTE_PATH)/assets/images/posts/"

# Full workflow: pull live data → build → push
deploy: sync-data sync-updates build push
