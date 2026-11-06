ALTER TABLE ingredients
    ADD COLUMN low_stock_notified_at DATETIME NULL AFTER stock_updated_at;
