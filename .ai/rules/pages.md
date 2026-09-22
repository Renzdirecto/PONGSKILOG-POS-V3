---
paths:
  - '{app/Http/Controllers/**,resources/js/components/pos-paid.tsx,resources/js/pages/public-receipt.tsx}'
---

# Pages

## POS receipt sharing is separate from Customer QR ownership
Show QR shares a paid receipt through a temporary relative signature, with the current request origin for LAN access and the existing payment + 24h deadline. Never weaken CustomerQrSession cookie ownership or extend expiry on link creation. Public receipt responses must omit staff shared props and use the customer receipt projection/card.
