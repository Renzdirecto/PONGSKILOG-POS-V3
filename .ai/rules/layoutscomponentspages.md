---
paths:
  - 'resources/js/{layouts,components,pages}/**/*.tsx'
---

# Layoutscomponentspages

## Owner management surfaces follow the decoded owner standalone
Treat context/design/PONGSKILOG-OWNER.html (including decoded __bundler/template) as the primary Owner UI/interaction reference. Preserve the real Laravel/Inertia authorization, branch context, catalog, inventory, image, and Store Session behavior. Use the 248px desktop sidebar at >=1180px (collapsible to a 76px icon rail, per-device preference), 96px tablet rail, and mobile top bar/bottom dock. Since Phase 16B–D every Owner destination (Dashboard, Transactions, Reports, Products, Inventory, Staff, Settings) is real. Since Phase 18 Manual QA refinement #1 a destination without permission is not rendered at all (no "No access" row); the list comes from `lib/management-navigation.ts` with sections Overview, Store Operations, Sales, Catalog, Operations, Administration (never "Cashier + Kitchen" for management/Custom Role shells). Phase 16E adds the Operations section (Pamalengke Plans, Overview, Ingredients, Recipes, Ingredient Stock, Pamamalengke, Purchases) following `context/design/PONGSKILOG Owner Operations v2 (standalone).html` inside the same shell; Transactions and Reports sit under Sales.
