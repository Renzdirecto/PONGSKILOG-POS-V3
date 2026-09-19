---
paths:
  - resources/js/components/cashier-pos.tsx
---

# Components

## Keep POS reservation responses across Strict Mode effect replay
Do not invalidate or discard the in-flight order-reservation promise from a useEffect cleanup. React Strict Mode performs a simulated cleanup/replay in development; the server-side reservation is idempotent, so the successful response must still populate the stable order number.
