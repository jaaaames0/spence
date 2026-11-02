# User guide

A tour of each page. On a phone, the icon bar at the top switches between them.

## First run

Open **Settings** and fill in your profile: date of birth, sex, height, weight, body-fat estimate, activity level, goal and weekly food budget. SPENCE builds a starting nutrition plan from it, and you can change the plan at any time.

## Home

The dashboard shows:
- today's energy and protein against your targets
- a 7-day energy trend
- the value of everything in stock
- this month's projected spend
- anything expiring in the next three days
- your top energy and protein sources over the last 30 days

The list icon on a sources card opens the **food leaderboard**. It ranks every food you've eaten by times eaten, serves, weight, kJ, protein or protein per 1000 kJ. You can view it by meal or by ingredient, and group variants by type (all the bacons together, for example).

The header has shortcuts to scan a receipt or photograph food straight from the dashboard.

## Stock

Everything you have on hand, with quantity, price paid, unit price, macros, location and category. The columns are configurable, and on mobile you tap a row to see the secondary details.

**Scanning a receipt.** Choose *Scan Receipt* and photograph the receipt. SPENCE then:
- adds each line with a clean product name, quantity, price, category and estimated macros
- for items sold by the piece but eaten by weight (cheese slices, apples), guesses the weight of one, so you can later eat "2 slices" rather than "42 g"
- estimates an expiry date for perishables
- recognises products you've bought before and restocks them, even when the receipt words them differently
- asks you to review possible duplicates, side by side; tick the ones to merge, and choose which name and macros to keep
- warns you if the scanned lines don't add up to the receipt's printed total, which usually means a price shifted a line

**Editing.** You can edit quantities, prices and expiry dates inline. Lowering a quantity by hand is counted as waste. Raising it is treated as a correction.

**Wasted vs delete.** Both remove a lot. Use **wasted** (the recycle icon) for food that went off, so its value counts toward your waste figure. Use **delete** (the trash icon) for mistakes; it records nothing.

**Spice rack** (the flame icon). Mark pantry staples as stocked or out. Each cook that uses a spice counts toward a restock reminder after 40 uses. Spices on a receipt land on the rack automatically. If something classed as a spice isn't on the rack, such as a curry paste, SPENCE asks whether to move it there.

**Manual add.** Adds stock without a receipt.

## Eat

Your inventory, Meal Prep first, with energy, protein and cost badges for each item. Pick an item and enter how much you ate, by weight, by count, or as a fraction of what's left. It's deducted from stock and logged.

**Quick Eat** logs food that didn't come from your inventory, such as a meal out or a snack at work:
- **Photo.** Photograph the food or its label, and SPENCE estimates each item's macros and cost.
- **New food (describe).** Type what you ate, for example "large flat white and a ham and cheese croissant".
- **Existing product.** Log a product from your catalogue without touching stock.

Each item on the review card has a chip that controls how it's logged:
- **Linked** to an existing product, with its real macros and price.
- **New product**, added to your catalogue for next time.
- **Just log**, which records it without changing the catalogue. This is the default for meals.

Tap the chip to cycle through the options. Quick Eat entries can be corrected later from the daily log.

## Cook

Your recipes, grouped by tag, with energy, protein and cost per serve. Open a recipe to see its ingredients and method side by side. Each ingredient is checked against your stock.

- **Batch multiplier** cooks several batches at once.
- **Eat Now** sets how many serves to log immediately. The rest go into stock as Meal Prep in the fridge.
- **Substitutions and amounts.** Tap an ingredient to swap it for something in stock, or type a different amount. The live badges update as you change things. The saved recipe is untouched, and SPENCE records what you actually cooked, so your log stays accurate.
- **Spice check** shows whether each spice the recipe needs is stocked.
- **Get Ingredients** (optional) sends missing items to an external shopping list, if one is configured.

## RecipeDB

Build and edit recipes from any product you have or have had. You set:
- the number of serves
- tags
- ingredients and their amounts
- required spices
- the method

A live panel shows energy, protein, fat, carbs and cost per serve as you build.

Editing a recipe's ingredients saves a new version. Earlier log entries and Meal Prep keep the numbers they were cooked with.

## Log

- **Daily view.** Energy, protein, fat and carbs against that day's targets, plus your macro split, cost, and a timeline of everything eaten. Recipes expand to show their ingredients and costs. Deleting an entry returns the food to stock; Quick Eat entries have no stock to return. You can also mark a day as incomplete so it doesn't skew calibration.
- **Weekly trends.** A chart of the last 7 days or this Monday–Sunday week, with averages and a comparison to your targets.
- **Progress.** Weight and body fat over time. *Weigh In* adds a reading. With FORGE connected, its bodyweight and body-fat readings are included too.

## Settings

- **User profile.** Your active nutrition plan:
  - **Plan modes:** Fat Loss, Maintenance, Lean Gain, High Gain, Dirty Bulk or Custom.
  - **Training days:** an optional extra energy allowance.
  - **Macro split:** sliders adjust it.
  - **Energy calibration:** once SPENCE has enough intake and weigh-in data, it estimates your real maintenance and can base your targets on it. The panel shows how confident the estimate is.
  - **Export:** a full JSON backup, or CSVs of inventory, consumption, recipes and vitals.
- **Product master.** Edit names, categories, units, macros and weight per item. You can add products by hand, for recipes that use things you haven't bought yet. *Drop* a product (paper bags, cleaning supplies) so future scans ignore it.
- **Deduplication.** Review flagged near-duplicates, or merge any two products by hand. Once merged, future scans of either name restock the surviving product.
