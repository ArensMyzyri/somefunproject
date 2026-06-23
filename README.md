# The Absence Run

Employees request time off — vacation, sick days, and more. This service
processes the **pending** requests for a period: for each one it checks the
employee's remaining entitlement, decides **approve** or **reject**, updates
their balance, and posts the decision to the HR API.

The run is split into two stages: `app:absence:run` **enqueues** each pending
request (oldest submission first) as its own asynchronous message, and a
background **worker** decides each request and reports it to HR. This keeps
memory bounded and makes the run safe to re-run or resume after a failure.

---

## Documentation

| Topic | File |
|-------|------|
| Architecture (components, data flow, dependencies) | [docs/architecture.md](docs/architecture.md) |
| Functionalities (what it does, end to end) | [docs/functionalities.md](docs/functionalities.md) |
| Leave policy (the functional source of truth) | [docs/LEAVE_POLICY.md](docs/LEAVE_POLICY.md) |

---

## Prerequisites

- **Docker** and **Docker Compose** (the whole stack runs in containers).
- That's it for running it — PHP 8.2+ and Composer live inside the image.
  You only need them on the host if you want to run tools outside Docker.

---

## Configuration

Credentials are read from a **`.env`** file that is **git-ignored**, so secrets
never land in version control. Create yours from the template and set a
password:

```bash
cp .env.example .env
# then edit .env and set POSTGRES_PASSWORD (and HR_API_TOKEN if needed)
```

`compose.yaml` interpolates `POSTGRES_USER` / `POSTGRES_PASSWORD` / `POSTGRES_DB`
from this file; Compose aborts with a clear message if `POSTGRES_PASSWORD` is
unset.

---

## Setup

```bash
# 1. Build and start the stack (database + app + worker + hr-api)
docker compose up -d

# 2. Install PHP dependencies
docker compose exec app composer install

# 3. Create the database schema (runs the versioned migrations).
#    The Messenger transport table is created automatically by the worker.
docker compose exec app bin/console doctrine:migrations:migrate -n

# 4. Load the sample period (the 2025 leave year)
docker compose exec app bin/console doctrine:fixtures:load -n
```

---

## Running the absence run

```bash
# Enqueue all pending requests for a run date (defaults to today).
docker compose exec app bin/console app:absence:run --date=2025-04-15
```

The `worker` service is already running `messenger:consume async`, so it picks
up the queued requests and processes them automatically. Watch it work:

```bash
docker compose logs -f worker
```

To consume manually instead (e.g. for a one-off), run:

```bash
docker compose exec app bin/console messenger:consume async -vv
```

---

## Testing

The suite boots against a fresh SQLite schema per test, with an in-memory fake
HR client and the queue switched to synchronous handling — no database, worker,
or HR endpoint required.

```bash
docker compose exec app ./vendor/bin/phpunit
```

---

## Useful commands

```bash
# Inspect the database (psql)
docker compose exec database psql -U app -d app

# Migration status
docker compose exec app bin/console doctrine:migrations:list

# Reset the mock HR system's recorded decisions
curl -X POST http://localhost:8081/v1/_reset -H "Authorization: Bearer <HR_API_TOKEN>"
```

### Ports

| Service | Host address |
|---------|--------------|
| Postgres | `127.0.0.1:5432` |
| Mock HR API | `http://localhost:8081` |

---

## Tear down

```bash
docker compose down        # stop and remove containers
docker compose down -v     # also wipe the database volume (fully clean slate)
```
