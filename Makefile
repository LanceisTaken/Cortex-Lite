# Cortex Lite — dev commands.
# Always use these instead of raw docker/php. See CLAUDE.md.

DC := docker compose
APP := $(DC) exec app

.PHONY: up down restart build logs ps shell mysql redis \
        migrate fresh test artisan composer install client-install client-dev

up:
	$(DC) up -d
	@echo ""
	@echo "  App:     http://localhost:8080"
	@echo "  React:   cd client && npm run dev  (http://localhost:5173)"
	@echo "  MySQL:   localhost:3306  (cortex/cortex, db: cortex_lite)"
	@echo "  Redis:   localhost:6379"

down:
	$(DC) down

restart:
	$(DC) restart

build:
	$(DC) build

logs:
	$(DC) logs -f --tail=100

ps:
	$(DC) ps

shell:
	$(APP) bash

mysql:
	$(DC) exec mysql mysql -ucortex -pcortex cortex_lite

redis:
	$(DC) exec redis redis-cli

migrate:
	$(APP) php artisan migrate

fresh:
	$(APP) php artisan migrate:fresh --seed

test:
	$(APP) php artisan test

# make artisan CMD="route:list"
artisan:
	$(APP) php artisan $(CMD)

# make composer CMD="require laravel/sanctum"
composer:
	$(APP) composer $(CMD)

install:
	$(APP) composer install
	$(APP) php artisan key:generate --force
	$(APP) php artisan migrate

client-install:
	cd client && npm install

client-dev:
	cd client && npm run dev
