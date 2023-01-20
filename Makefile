.PHONY: run

COMPOSE_FILE:=docker-compose.yml

build:
	docker compose -f $(COMPOSE_FILE) build --no-cache

run:
	docker compose -f $(COMPOSE_FILE) up -d

stop:
	docker compose -f $(COMPOSE_FILE) down -v

restart: stop build run

logs:
	clear && docker compose -f $(COMPOSE_FILE) logs -f --tail 0

shell:
	docker compose -f $(COMPOSE_FILE) exec -it app ash