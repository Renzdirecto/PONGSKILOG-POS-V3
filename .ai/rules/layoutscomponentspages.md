---
paths:
  - 'resources/js/{layouts,components,pages}/**/*.tsx'
---

# Layoutscomponentspages

## Owner management surfaces follow the decoded owner standalone
Treat context/design/PONGSKILOG-OWNER.html (including decoded __bundler/template) as the primary Owner UI/interaction reference. Preserve the real Laravel/Inertia authorization, branch context, catalog, inventory, image, and Store Session behavior. Use the 248px desktop sidebar at >=1180px, 96px tablet rail, and mobile top bar/bottom dock; unavailable later-phase destinations must remain visibly disabled with reasons. Since Phase 16B–D every Owner destination (Dashboard, Transactions, Reports, Products, Inventory, Staff, Settings) is real; a missing permission shows "No access". Phase 16E adds the Operations section (Pamalengke Plans, Overview, Ingredients, Recipes, Ingredient Stock, Pamamalengke, Purchases) following `context/design/PONGSKILOG Owner Operations v2 (standalone).html` inside the same shell; Transactions and Reports sit under Sales.
