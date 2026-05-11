# PeetyAI SaaS

AI-powered WhatsApp grocery ordering platform with a multi-tenant retailer dashboard.

## Stack

- Backend: Laravel 11, PHP 8.3+, Sanctum, Scout/Meilisearch, Octane, Redis queues
- Frontend: Next.js App Router, TypeScript, TailwindCSS, Zustand, React Query
- Realtime: Laravel broadcasting + Pusher-compatible transport
- Integrations: Meta WhatsApp Cloud API, OpenAI-compatible AI provider
- DevOps: Docker Compose, Nginx, GitHub Actions CI

## Monorepo Structure

- `backend`: Laravel API and worker services
- `frontend`: Next.js dashboard UI
- `deploy/nginx`: reverse proxy config

## Core Backend Modules

- WhatsApp webhook endpoint: `/api/webhooks/whatsapp`
- AI order parser: `App\Services\AI\AIOrderService`
- Product matching: `App\Services\ProductMatchingService`
- Cart merge logic: `App\Services\CartService`
- Order lifecycle + status logs: `App\Services\OrderService`
- Queue worker job: `App\Jobs\ProcessIncomingMessage`

## API Endpoints

- `POST /api/auth/token`
- `GET /api/webhooks/whatsapp`
- `POST /api/webhooks/whatsapp`
- `GET|POST|PUT /api/orders`
- `GET|POST /api/carts`
- `GET|POST|PUT|DELETE /api/products`
- `GET|POST /api/customers`
- `GET /api/dashboard/retailer`
- `GET /api/owner/summary`

## Local Development

### 1. Environment

Backend:

```bash
cd backend
cp .env.example .env
php artisan key:generate
```

Frontend:

```bash
cd frontend
cp .env.example .env.local
```

### 2. Run with Docker

```bash
docker compose up --build
```

### 3. Run Migrations

```bash
docker compose exec backend php artisan migrate
```

### 4. Optional Search Sync

```bash
docker compose exec backend php artisan scout:import "App\Models\Product"
```

## Production Notes

- Horizon requires Linux with `pcntl` and `posix` enabled.
- Configure `QUEUE_CONNECTION=redis`, run workers and Horizon in separate processes.
- Store media and exports on S3-compatible storage (`FILESYSTEM_DISK=s3`, `AWS_ENDPOINT` for DigitalOcean Spaces).
- Use managed Redis and managed DB in production.
- Put Octane behind Nginx with autoscaled app containers.

## Testing

Backend:

```bash
cd backend
php artisan test
```

Frontend:

```bash
cd frontend
npm run lint
npm run build
```
