# Roadmap

## Done

- Receipt scanning with AI catalogue matching, merge review, expiry estimates and a receipt-total check
- Quick Eat by photo, text description or existing product, linked to the catalogue
- Recipes with live costing, versioning and cook-time substitutions (forks)
- Spice rack, spend and wastage tracking
- Nutrition plans with calibrated maintenance and training-day targets, judged against the plan in force on each date
- Dashboard, weekly trends, progress, and the food leaderboard
- JSON and CSV export
- Read-only FORGE integration
- Guest tenants

## Next: Recipe Intelligence

One combined feature for recipe discovery, planning and import. See [docs/RECIPE_INTELLIGENCE.md](docs/RECIPE_INTELLIGENCE.md).

- **Discover** recipes through licensed APIs, or import a single URL that carries Schema.org `Recipe` JSON-LD, where the source's terms allow it.
- **Match** each ingredient to the product master and inventory, and show exact matches, substitutes, missing items and uncertain matches for review.
- **Rank** candidates by kitchen coverage, missing ingredients, expiring stock used, fit with the active plan, and cost.
- **Import** the approved recipe into RecipeDB with SPENCE-calculated macros and cost, and send confirmed missing ingredients to the shopping list.

A shopping-list predictor and an "AI chef" are not planned as separate features. Their useful parts belong inside Recipe Intelligence.

## Deferred

These wait until SPENCE is packaged for other people to install:

- an installer and a configuration wizard
- a data wipe that keeps the product catalogue for sharing
