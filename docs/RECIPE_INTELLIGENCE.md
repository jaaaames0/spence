# Recipe Intelligence

## Purpose

Recipe Intelligence combines recipe discovery, kitchen-aware recommendations, and RecipeDB import. It is deliberately not a standalone "AI Chef" or shopping predictor: every recommendation must be traceable to a source recipe, the current inventory, and the active nutrition plan.

## User flow

1. Search for a dish or browse candidates using a licensed recipe API or a compliant recipe URL import.
2. Filter or rank results by available ingredients, one/two/three permitted missing ingredients, expiring stock, active-plan macro fit, time, and estimated cost.
3. Review the selected recipe before import:
   - source and attribution;
   - parsed ingredients and method;
   - exact Product Master matches, substitutions, missing items, and uncertain matches;
   - source nutrition alongside SPENCE-recalculated nutrition and cost.
4. Confirm the ingredient mapping and serving count.
5. Import an editable recipe into RecipeDB and optionally send confirmed missing ingredients to the Ingredients shopping-list app.

## Source strategy

1. **Licensed recipe API:** preferred for broad discovery, ingredient-based search, and nutrition filters.
2. **Manual URL import:** parse standard Schema.org `Recipe` JSON-LD from one URL where the source's terms allow it.
3. **Authorised source adapters:** add dedicated importers only for sources that explicitly permit the integration.

Do not scrape search-result pages or bypass source restrictions. Retain source URL and attribution. Where a source prohibits copying recipe ingredients or method, keep it as a link-only discovery result rather than importing its content.

## Ingredient matching states

| State | Outcome |
| --- | --- |
| Exact match | Use the mapped Product Master item, inventory quantity, nutrition, and lot-based cost. |
| Confirmed substitute | Recalculate SPENCE nutrition and cost from the chosen substitute. |
| Missing recognised item | Offer it for the Ingredients shopping list. |
| Uncertain | Require user review before import. |

AI may assist with normalising ingredient text, units, and match suggestions, but must not silently invent quantities, nutrition, or costs.

## Calculation rules

- Source nutrition is retained as a labelled reference.
- After approval, SPENCE's Product Master mappings are authoritative for recipe macros and cost.
- Ranking considers kitchen coverage, number and cost of gaps, expiring-stock use, active-plan fit, and preparation time.
- A recipe may be suggested even when it is not an exact target fit; the interface should explain the trade-off instead of hiding it.

## Ingredients integration

The current sibling app stores a shared JSON shopping list. Before writing to it, define an authenticated integration contract containing at least item name, quantity, unit, source recipe, and recipe-import origin. Only confirmed missing ingredients should be sent.

## Delivery order

1. Persist imported-recipe source metadata and review/match state.
2. Implement manual JSON-LD URL import and the review screen.
3. Add Product Master matching, inventory coverage, costing, and Ingredients handoff.
4. Add a licensed discovery provider and ranking/filtering.
5. Add AI-assisted normalisation and authorised source-specific adapters if useful.
