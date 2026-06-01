# Inventory API v1

Base URL: `/api/inventory/v1`

All responses return:
```
{
  "success": true,
  "message": "OK",
  "data": {},
  "meta": {
    "request_id": "uuid",
    "api_version": "v1",
    "timestamp": "2026-03-18T00:00:00Z"
  }
}
```

Auth uses bearer tokens. Header:
```
Authorization: Bearer <token>
```

## Auth
- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`
- `POST /auth/logout-all`
- `POST /auth/refresh`
- `GET /auth/tokens`
- `DELETE /auth/tokens/current`
- `DELETE /auth/tokens/{tokenId}`

## Core
- `GET /dashboard`
- `GET /item-groups`
- `POST /item-groups`
- `GET /item-groups/{item_group}`
- `PUT|PATCH /item-groups/{item_group}`
- `DELETE /item-groups/{item_group}`
- `GET /items`
- `POST /items`
- `GET /items/{item}`
- `PUT|PATCH /items/{item}`
- `DELETE /items/{item}`
- `GET /receipts`
- `POST /receipts`
- `GET /receipts/{receipt}`
- `GET /assignments`
- `POST /assignments`
- `PUT|PATCH /assignments/{assignment}`
- `DELETE /assignments/{assignment}`
- `GET /deployments`
- `POST /deployments`
- `GET /movements`
- `GET /movements/transfer-form`
- `GET /movements/return-form`
- `POST /movements`
- `GET /teams`
- `POST /teams`
- `GET /teams/{team}`
- `PUT|PATCH /teams/{team}`
- `DELETE /teams/{team}`
- `POST /teams/{team}/members`
- `DELETE /teams/{team}/members/{member}`
- `GET /team-assignments`
- `POST /team-assignments`
- `GET /team-deployments`
- `POST /team-deployments`
- `GET /logs`
- `GET /alerts/low-stock`
- `GET /routers`

## Technician
- `GET /tech/dashboard`
- `GET /tech/items`
- `GET /tech/items/{item}`
- `GET /tech/sites/lookup`

## Settings (Admin)
- `GET /settings/users`
- `POST /settings/users`
- `PUT|PATCH /settings/users/{user}`
- `POST /settings/users/{user}/send-login`
- `POST /settings/users/{user}/reset-login`

Rate limits:
- Login: `throttle:inventory-api-login` (config `INVENTORY_API_LOGIN_RATE_LIMIT_PER_MINUTE`, default `20`)
- Authenticated: `throttle:inventory-api` (config `INVENTORY_API_RATE_LIMIT_PER_MINUTE`, default `120`)
