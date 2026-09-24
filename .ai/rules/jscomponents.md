---
paths:
  - 'resources/js/{components/super-admin-shell.tsx,lib/super-admin-navigation.ts,layouts/workspace-layout.tsx}'
---

# Jscomponents

## Super Admin navigation is registry driven
Super Admin management pages use SuperAdminShell with collapsible sections from lib/super-admin-navigation.ts (label, section, routeName, permission, availability, requiresBranch). Add destinations to the registry and bind the icon and Wayfinder route in destinationBindings. Planned items link to real protected placeholder routes and never show fake controls (since Phase 18 none remain: Notifications and Access Control are live, and the only unread badge is the real server count). No Super Admin standalone HTML is authoritative; follow the Owner/POS design language. Operational pages keep the POS shell with a Control Center link back.
