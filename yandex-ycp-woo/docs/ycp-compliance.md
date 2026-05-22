# YCP compliance matrix

Source used: https://yandex.ru/support/merchants-ru-ycp/ru/ (high-level public docs) and plugin contract from the existing integration.

| Endpoint | Method | Current status | Changes made |
|---|---|---|---|
| `/ycp/api/v1/warehouses` | GET | Implemented | Strict GET-only validation and normalized response container `warehouses`. |
| `/ycp/api/v1/shops` | GET | Added | Added dedicated shops endpoint and settings-backed response. |
| `/ycp/api/v1/checkout/basket/check` | POST | Implemented | Added JSON validation, strict stock rules (`0` when OOS), item-level availability. |
| `/ycp/api/v1/checkout` | POST | Reworked | Separated checkout-session creation from order placement, idempotent by `checkout_session_id`. |
| `/ycp/api/v1/checkout/placed` | POST | Reworked | Creates Woo order and stores `_ycp_session_id`, `_ycp_order_id`, `_ycp_request_id`, idempotent by `order_id`. |
| `/ycp/api/v1/checkout/cancel` | POST | Reworked | Idempotent cancellation by session id, cancels WC order only when allowed. |
| `/ycp/api/v1/order/cancel` | POST | Reworked | Idempotent order cancellation by YCP order id. |

## Error contract

Unified response:

```json
{
  "error": {
    "code": "validation_error",
    "message": "...",
    "details": {}
  }
}
```

HTTP codes used: `400`, `401`, `404`, `405`, `500`.

## Security and logging

- Only `Authorization: Bearer` is accepted.
- `X-API-Key` fallback removed from public API flow.
- Debug logging is opt-in (`off` by default) and no token/body is persisted.
