# Filling the 50 missing prices

The list is [missing-prices.csv](missing-prices.csv). It is UTF-8 with a BOM so Excel shows the Georgian names correctly.

| Column | What to do with it |
|---|---|
| ID, SKU, Name, Categories | Find the product. |
| Suggested GEL price | Filled in only where every priced sibling uses one price: square scarf 90 ₾, short kimono 295 ₾. Use it as the price to type. |
| GEL price (fill in) | Notes only. Nothing in this file is imported. |
| Edit link | Opens the product in WP admin. |

Enter the price in WP admin or in WP Sheet Editor.

## WP Sheet Editor

1. Open WP Sheet Editor → Products.
2. Filter **Regular price** “is empty” and status **Published**.
3. Type the price in the **Regular price** column.
4. Save.

The product appears in the Store on its own. Do not change catalog visibility.
