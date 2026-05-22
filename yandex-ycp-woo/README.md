# Yandex YCP WooCommerce plugin

## API base

`/ycp/api/v1`

## Endpoints

- `GET /warehouses`
- `GET /shops`
- `POST /checkout/basket/check`
- `POST /checkout`
- `POST /checkout/placed`
- `POST /checkout/cancel`
- `POST /order/cancel`

## Auth

`Authorization: Bearer <TOKEN>`

## Curl examples

```bash
curl -X GET https://site.ru/ycp/api/v1/warehouses \
  -H "Authorization: Bearer TOKEN"
```

```bash
curl -X POST https://site.ru/ycp/api/v1/checkout/basket/check \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"offer_id":"SKU-1","quantity":1}]}'
```

```bash
curl -X POST https://site.ru/ycp/api/v1/checkout \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"checkout_session_id":"sess-1","items":[{"offer_id":"SKU-1","quantity":1}]}'
```

See `docs/ycp-compliance.md` for endpoint-by-endpoint matrix.
