README.md

SPENCE - AI Powered Kitchen Inventory, Recipe Database and Nutritional Log

SPENCE harnesses the abilities of AI vision and LLMs to analyze grocery receipts, automatically sorting, categorising and tracking your nutrition from shopping cart to pantry, fridge or freezer to stovetop, and onwards as you use it to fuel your journey. One click ingesting of entire grocery receipts, with full and accurate price, quantity and marconutritional data, automatic merging of like items to keep your database clean. Create recipes to cook and consume and SPENCE will automatically deduct the quantities required from your inventory and track your progress with the daily and weekly nutritional logs. Input your details to receive a personalised diet plan for weight gain, maintenance or weight loss, alongside weight and body fat percentage tracking.

STOCK

The WAREHOUSE STOCK page lists all your current inventory on hand, sortable by a customisable list of 11 metrics and catergories (Name, Stock on Hand, Total Price Paid, Price per Unit, Energy per 100g, Energy per $1, Protein per 100g, Protein per $1, Fat per 100g, Carbs per 100g, Stock Location (Pantry, Fridge, Freezer) and Category (Meal Prep, Proteins, Dairy, Bread, Fruit and Veg, Cereal/Grains, Snacks/Confectionary, Drinks, Other). From the warehouse, you can Scan A New Receipt which will connect to your AI provider of choice using your exported API key in /core/watcher.sh. The AI vision model will analyze your grocery receipt and return clean title case, slightly genericised names for each product, also capturing quantities and prices paid, and macronutrient profiles. If any potential matches are detected with existing products (eg. "Full Cream Milk" and "Whole Milk") you will be prompted to merge either product into the other, aggregating their quantities and prices paid to provide your average cost basis. From the Stock page you can also edit quantities or prices should you have the need. Deleting items will remove the stock from your inventory, but does not delete the master product reference with macronutrient profiles and category and unit information. Inventory items are searchable by name.

EAT

The EAT tab allows you to "consume" items from your inventory by weight or by quantity based on the unit of the item. This removes the stock from you inventory and add the macronutrient data to you daily consumption log. Inventory items are sorted by category with Meal Prep items listed first for convenience and the entire inventory is searchable by name or by category. Each inventory item features three badges representing Energy, Protein and Cost per either 100g, 100ml or 'each' if it is consumed by the unit. Upon selecting an inventory item the EAT modal allows you to enter the amount to consume or quickly select any major fraction of your entire stock, or quick add by grams/mLs.

COOK

The COOK tab is where you find your created recipes, categorised and ordered by tag. They are searchable by name or by tag. Each recipe shows three badges representing Energy, Protein and Cost per Serve as defined in the RECIPEDB blueprint. The recipes are searchable by name or by tag. Selecting a recipe will open the COOK|RECIPE page shows the ingredients list alongside the recipe method in an easy to read format for easy reference while cooking. The ingredients list is dynamically checked and measured against your existing stock levels to ensure you have the required stock on hand (optional integration with 'ingredients!' app for quick adding missing ingredients to a simple and responsive dynamic shopping list). Ingredients are also checked for suitable substitutes from your inventory should you have any. Once you have the required stock on hand you are able to execute the cook, one or more batches at a time, as well as choosing how many to auto-consume and add to the daily consumption log. Servings not consumed will be added to your inventory under the category "Meal Prep" and location "Fridge".

RECIPEDB

The RECIPEDB tab allows you to view and craft recipes from any ingredients you have or have ever had on hand. The table shows all your current recipes with energy, macronutrient and cost details per serve. When building a recipe you are able to set the amount of servings the recipe make and add tags to help categorise your recipe for easy searching. Add and delete ingredients, specifying the product and quantity and attaching recipe instructions for viewing later on the recipe page while cooking. Deleting a recipe preserves it historical ingredients and quantities so as to maintain accurate tracking in the consumption log.

LOG

The LOG has three tabs, showing an overview of your daily and weekly nutrition, as well as your weight progress over time. The daily view shows at a glance figures for energy, protein, fat and carb intake, as well as a graphical representation of your macronutrient split and costing analysis, all compared against your target macros as defined in your goals in settings. The daily log also presents a chronological representation of all food consumed that day with macro and cost breakdown, with expandable recipes to view constituent ingredients. Deleting an item from here will return it to your inventory. The weekly tab offers a bar chart showing your macros across the span of either the last 7 days or this current Mon-Sun period, with averages shown as dotted lines across the chart. Each macro can be individually turned on or off by selecting the name in the upper right hand corner of the chart. Weekly averages are also viewable as figures below the chart with quick comparison to your targets for easy goal tracking over longer periods. The progress tab will show a graphical and tabular representation of your weight and body fat % over time, with weight on the left hand y axis and body fat % on the right hand y axis. Click "Weigh In" in the top right corner to add new data points.

SETTINGS

The SETTINGS page allows you to set and control your goals and macronutrient targets, as well as offering control over the master product list via editable fields and deduplication. The user profile tab with by default be uninitialized, enter your details to receive a basic diet plan based on your circumstances. This plan can be customised by selecting "Adjust Plan" where you can reselect your plan or override any suggestions as you like using the sliders. You can also access the "Weigh In" modal from the user profile tab. The Product Master Settings tab allows you to edit existing items should the name, category macros or weight/ea not be as accurate as you like. Weight/Ea is a constant derived when a line item on a receipt has both weight and quantity information eg. "Cheese Slices 10pk 250g", the AI vision model will attempt to categorise this as "Name": "Cheese Slices", "Unit": "ea", "Quantity": 10, "Weight/Ea": 0.025, with 0.025 representing 25 grams each per slice for a total of 250 grams. This ensures you can consume the slices logically by slice rather than having to enter an abstruse weight. For some products the AI vision model may "guess" if the product is generally consumed per unit but doesnt carry unit and weight information on the receipt line item eg. "Royal Gala Apples", the AI vision model may guess 0.200 as the Weight/Ea. You may edit this for each product if you find it to be incorrect. Deleting an item from the product master will delete any stock you have on hand and from the product master list, also ensuring that should the product be scanned again it will be ignored. (Useful for non-consumables you might not want to track, eg "Paper Shopping Bag".) You can also add products manually here (useful if you want to create recipes with ingredients you haven't scanned in yet). The deduplication tab will present you with a list of flagged possible matches as well as the ability to merge any two items from the product list. Once a product has been merged any sunsequent scans of that product will instead transfer the quantity and average the prices of the "merged from" product and the "mereged to" product.

REQUIREMENTS

Web Server (Apache or Nginx or similar)
PHP
API access to a model with vision capabilities

PLANNED FEATURES

- Packager/automated set up and configuration
- Dynamically suggested meals based on your targets and current consumption
- AI powered recipe reccomendation and suggestion portal. Search for recipes from the wider web based on your macro needs or the ingredients you currently have on hand or could make with just one or two more ingredients.
- Automated expiry date tracker to ensure you never waste food. 
- Improved mobile friendliness. Currently optimized for Chrome at 1920x1080

LICENCE

???

CAUTION

This entire project has been vibe coded using gemini-3-flash-preview, it could be an absolute dumpster fire and security nightmare so use at your own risk.
