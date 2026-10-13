---
paths:
  - '{resources/js/pages/welcome.tsx,resources/js/components/landing-experience.*,resources/js/lib/landing.ts,resources/css/landing.css}'
---

# Css

## Public landing follows the standalone interactions and verified business facts
Use context/design/PONGSKILOG Landing Page.html as the appearance/interaction reference; port native React markup and logic, never ship its exported runtime. Preserve carousel physics, scroll transitions, filters, responsive behavior and lazy Leaflet map, with cleanup on leaving. Only independently verified business facts may be published; conflicting opening times are omitted, and staff entry uses existing Wayfinder auth routes.

## Phone landing: filters stay visible and the touch map is tap-to-interact
Manual QA 2 (v1.2.1): on phones the Pics | Prices toggle and every category chip render directly as one horizontally scrolling row with the active chip black; never hide filters behind an icon. A touch map starts locked (no drag or pinch handler) so page scrolling is never trapped; one tap unlocks it and a touch outside locks it again, while the marker, zoom buttons, Recenter and directions always work. Wednesday reads "Usually Closed" with the secondary line "Sometimes open — message to confirm."; hour rows wrap and are never `nowrap`. Phone headings step down in the 699px block; form controls stay at 16px or more.
