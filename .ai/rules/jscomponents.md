---
paths:
  - 'resources/js/{components/super-admin-shell.tsx,lib/super-admin-navigation.ts,layouts/workspace-layout.tsx}'
---

# Jscomponents

## Super Admin navigation is registry driven
Super Admin management pages use SuperAdminShell with collapsible sections from lib/super-admin-navigation.ts (label, section, routeName, permission, availability, requiresBranch); its operational section is labelled "Store Operations" (never "Cashier + Kitchen"). Add destinations to the registry and bind the icon and Wayfinder route in destinationBindings. Planned items link to real protected placeholder routes and never show fake controls (since Phase 18 none remain: Notifications and Access Control are live, and the only unread badge is the real server count). The Dashboard destination is the Executive Overview; shells show `auth.roleLabel` (never a raw role key) and operational chrome keys off permissions, not role names, so Custom Roles render correctly. No Super Admin standalone HTML is authoritative; follow the Owner/POS design language. Operational pages keep the POS shell with a Control Center link back.

## Navigation lists only what the account can open
The Owner / Custom Role management shell reads `lib/management-navigation.ts` (one registry: section, label, permission) and the operational POS shell filters its items by permission; neither renders disabled "No access" / "Coming later" rows. Add a management destination to the registry and bind its icon and href in `managementDestinationHref()` (`owner-workspace-shell.tsx`). Branch and business-wide Custom Roles see the same structure; a Branch-scoped account holding any management-only page (`hasManagementPages()`) uses the management shell for Dashboard/Reports too, and its POS shell "Management" link opens `managementLandingDestination()`. Backend middleware stays the control.
