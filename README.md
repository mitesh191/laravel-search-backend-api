# Laravel Search API

A Laravel autocomplete demo backed by MySQL, Redis, and Docker Compose. Search uses an indexed word-prefix table; selecting a suggestion records usage once through a short-lived signed token.

## Run locally with Docker

The Docker setup builds the PHP application and frontend assets for you. You do not need PHP, Composer, Node.js, or MySQL installed on your host to start the app.

### Requirements

- Docker Desktop with the WSL 2 backend on Windows, or Docker Engine and the Docker Compose plugin on macOS/Linux.
- Docker running while you use the app.
- Internet access for the first image build and dependency downloads.
- For the full 10-million-record performance dataset, use a machine with at least 12 GB of RAM, allocate at least 8 GB of memory and 4 CPUs to Docker, and keep at least 10 GB of free Docker disk space. These are recommendations for the large dataset; a smaller local demo needs less.

### 1. Get the code

Clone the repository or extract its archive, then open a terminal in the project root (the directory containing `compose.yaml`).

```powershell
git clone <repository-url>
Set-Location <repository-folder>
```

If you received a ZIP, extract it and `Set-Location` to the extracted project directory instead.

### 2. Create your local Docker environment

In PowerShell:

```powershell
Copy-Item .env.docker.example .env.docker
```

Open `.env.docker` and:

1. Generate an application key in the next step and paste it into `APP_KEY`.
2. Replace `MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD` with private passwords of your own. Set `DB_PASSWORD` to the same value as `MYSQL_PASSWORD`.
3. Keep `.env.docker` private. It contains credentials and is intentionally excluded from source control.

Build the application image, then generate an application key:

```powershell
docker compose --env-file .env.docker build
docker compose --env-file .env.docker run --rm --no-deps app php artisan key:generate --show
```

Copy the printed `base64:...` value into `APP_KEY` in `.env.docker`. Do not share that key or commit the local environment file.

### 3. Start the app and create its database tables

```powershell
docker compose --env-file .env.docker up -d
docker compose --env-file .env.docker exec app php artisan migrate --force
docker compose --env-file .env.docker ps
```

Wait for the `app`, `db`, and `redis` services to report healthy/running. Open the app at **[http://127.0.0.1:8000](http://127.0.0.1:8000)**. Use `127.0.0.1` rather than `localhost`: Compose publishes the web app on IPv4 loopback only, and Windows may resolve `localhost` to IPv6 first.

The database starts empty. To add a small dataset for trying autocomplete, continue to [Add search data](#add-search-data).

## Add search data

### Small local demo

This creates 10,000 generated records and builds their MySQL term-prefix index:

```powershell
docker compose --env-file .env.docker exec app php artisan search:seed --count=10000 --chunk=5000
docker compose --env-file .env.docker exec app php artisan search:index-terms --fast-generated --chunk=10000
```

Try searching for `London Ro` in the UI.

### Full 10-million-record dataset

Only run this if you have enough memory and free disk space. Seeding and indexing can take a long time and use substantial disk I/O:

```powershell
docker compose --env-file .env.docker exec app php artisan search:seed --count=10000000 --chunk=20000
docker compose --env-file .env.docker exec app php artisan search:index-terms --chunk=100000 --fast-generated
```

`--count` is the target total, so rerunning the seed command does not add another 10 million rows. To add a specific number to an existing corpus, use `--append=<number>` instead. Existing rows are preserved. `--fast-generated` is for data produced by `search:seed`; omit it when indexing records in another format.

The database, Redis, and app storage live in named Docker volumes. They persist across container restarts and are not included when sharing the source code.

## Optional: browse MySQL with phpMyAdmin

Start the optional phpMyAdmin service:

```powershell
docker compose --env-file .env.docker --profile tools up -d phpmyadmin
```

Open **[http://127.0.0.1:8081](http://127.0.0.1:8081)** and sign in with the `MYSQL_USER` and `MYSQL_PASSWORD` values in your private `.env.docker`. The database is `MYSQL_DATABASE`. phpMyAdmin and the app are bound to your local machine; MySQL itself is not published as a host port.

Stop phpMyAdmin when you are done:

```powershell
docker compose --env-file .env.docker --profile tools stop phpmyadmin
```

## Everyday Docker commands

Run these from the project root:

```powershell
# See service status
docker compose --env-file .env.docker ps

# Follow application logs (Ctrl+C stops following logs, not the containers)
docker compose --env-file .env.docker logs -f app

# Stop services; named database and storage volumes are preserved
docker compose --env-file .env.docker down

# Start services again
docker compose --env-file .env.docker up -d

# Rebuild and restart after changing application source or Docker dependencies
docker compose --env-file .env.docker up -d --build
```

To change the local app port, update `APP_PORT` in `.env.docker`. phpMyAdmin's port can be changed with `PMA_PORT`. For example, set `APP_PORT=8001` to use `http://127.0.0.1:8001`.

> **Warning:** `docker compose --env-file .env.docker down -v` deletes the named database, Redis, and app-storage volumes, including any seeded data. Do not use `-v` unless you intend to permanently remove that local data.

## Run tests

Tests use an isolated in-memory SQLite database, not the MySQL performance dataset:

```powershell
docker compose --env-file .env.docker run --rm --no-deps `
    -e APP_ENV=testing `
    -e DB_CONNECTION=sqlite `
    -e DB_DATABASE=:memory: `
    -e DB_URL= `
    -e CACHE_STORE=array `
    -e SESSION_DRIVER=array `
    -e QUEUE_CONNECTION=sync `
    app php artisan test
```

Do not point tests that use `RefreshDatabase` at the performance database; those tests migrate and reset their test schema.

## Measure API latency

For an end-to-end measurement through the same Docker port used by the browser, run the benchmark from the Windows host. This optional step requires PHP 8.3+ and Composer installed on the host; install the PHP dependencies from the project root first:

```powershell
composer install
```

Keep each run below the API's 120-requests-per-minute limit:

```powershell
php artisan search:benchmark gar --iterations=80 --warmup=5 --max-ms=50 --url=http://127.0.0.1:8000/api/search
php artisan search:benchmark xylophone --iterations=20 --warmup=2 --max-ms=50 --url=http://127.0.0.1:8000/api/search
```

The benchmark reports p50, p95, and maximum latency, and fails if any measured request exceeds `--max-ms`. The API returns a `Server-Timing` header for controller processing time, which can help distinguish application time from Docker/host delays. A local benchmark result depends on the computer and Docker resources; it is not a guarantee for every machine or deployment.

## Troubleshooting

### Docker is unavailable

Start Docker Desktop and wait for its engine to finish starting. Check with:

```powershell
docker info
docker compose version
```

### A service is unhealthy or does not start

Check its status and logs:

```powershell
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail 100 db
docker compose --env-file .env.docker logs --tail 100 app
```

The app waits for MySQL and Redis health checks. On first startup, MySQL can take time to initialize. Ensure Docker has enough memory and disk space, then check the database logs before restarting.

### A port is already in use

Set a different `APP_PORT` or `PMA_PORT` in `.env.docker`, then recreate the affected service:

```powershell
docker compose --env-file .env.docker up -d --force-recreate app
```

For phpMyAdmin, include `--profile tools` and the `phpmyadmin` service name.

### Application key or database credentials changed

After changing credentials in `.env.docker`, recreate the services so the new environment is applied:

```powershell
docker compose --env-file .env.docker up -d --force-recreate
```

`DB_PASSWORD` in the app environment must match `MYSQL_PASSWORD`. Changing MySQL initialization credentials does not change the credentials of a database that has already been initialized in the persistent volume; preserve your existing password or intentionally recreate the database volume only if you are prepared to lose its data.

## API overview

### Search suggestions

`GET /api/search?q=London%20Road` returns up to eight suggestions, each with a short-lived `selection_token`. Queries are limited to 100 characters and five terms. Terms shorter than three characters are ignored until enough characters are typed. MySQL uses the indexed `search_record_terms` word-prefix table rather than scanning the full corpus.

### Record a selection

`POST /api/search/selections` accepts a JSON body containing a suggestion's `selection_token`:

```json
{
  "selection_token": "token-from-a-suggestion"
}
```

The API validates the token and expiry, then records its hash under a unique constraint so replaying the same token does not count twice.
