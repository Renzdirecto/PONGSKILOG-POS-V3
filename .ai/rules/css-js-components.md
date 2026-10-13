---
paths:
  - '{resources/views/app.blade.php,resources/js/pages/welcome.tsx,resources/css/landing.css,resources/js/components/landing-experience.*}'
---

# Css Js Components

## Landing V2 and Inertia v3 initial SEO
The approved reference is context/design/PONGSKILOG Landing Page v2.html; it supersedes V1. Keep genuine approved public reviews in place of Whole Menu. Initial SEO lives in x-inertia::head fallback with data-inertia keys (v3 uses data-inertia, not inertia) matching React Head head-key values so hydration removes duplicates. Internal routes retain authorization and return noindex; do not add self-serving Restaurant rating markup.
