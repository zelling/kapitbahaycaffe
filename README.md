# KapetBahay Café Sales and Ingredient Usage Analytics System

## XAMPP setup

1. Start Apache and MySQL in the XAMPP Control Panel.
2. In phpMyAdmin, import [database/schema.sql](database/schema.sql), then import [database/seed.sql](database/seed.sql), into MySQL.
3. Review the database settings and `BASE_URL` in [config/db.php](config/db.php). The default assumes MySQL user `root` with no password and this project at `http://localhost/kapetbahay`.
4. Open `http://localhost/kapetbahay/`.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Owner | owner@kapetbahay.test | password |
| Staff | staff@kapetbahay.test | password | #remove staff account

These are throwaway local development accounts. Change or remove them before any non-demo use.

## Database notes

The seed data creates four menu categories, ten menu items, ten ingredients, recipes, and thirty days of sample sales. One menu item intentionally has no sales so product-performance reporting can verify zero-sales handling.
