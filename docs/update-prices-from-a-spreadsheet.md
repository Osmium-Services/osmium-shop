---
title: Update prices and weights from a spreadsheet
tags: csv, import, export, spreadsheet, prices, rrp, weight, bulk, excel
page: admin/shop/products/import
---

If many prices change at once, export your products, edit them in a spreadsheet and import them back.

## Steps

1. On the **Products** page, press **Export CSV**.
2. Open the file in Excel or Google Sheets.
3. Change the **Price (ex VAT)**, **RRP (ex VAT)** and **Weight (kg)** columns. Change nothing else.
4. Save it as CSV.
5. Press **Import CSV** on the Products page, choose the file and press **Import Prices**.

## What to know

- Only those three columns are read. Names, descriptions and other columns are ignored.
- Rows are matched by **ID**, so do not change the **ID** or **Type** columns.
- The file lists products and their options. For a product the price and weight are the base values. For an **Option** row they are the adjustment added to the product.
- Rows with no change are skipped.

When it finishes you see how many **Products Updated**, **Options Updated**, **Skipped (No Changes)** and **Errors** there were, with the error messages.

Each import is recorded in **Import History** on the Price History page, where you can **Undo import** to put the old prices back.
