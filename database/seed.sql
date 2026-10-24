USE kapetbahay;

-- Both hashes are bcrypt hashes for the documented local-only password: password.
INSERT INTO users (name, email, password_hash, role) VALUES
('KapetBahay Owner', 'owner@kapetbahay.test', '$2y$10$/7kwunjeng/AZyTX4dTCCeBIGkpt2YQ./vg2/yGt2CpbslOE5p3N.', 'owner'),
('KapetBahay Staff', 'staff@kapetbahay.test', '$2y$10$/7kwunjeng/AZyTX4dTCCeBIGkpt2YQ./vg2/yGt2CpbslOE5p3N.', 'staff');

INSERT INTO categories (name) VALUES
('Coffee'), ('Non-Coffee'), ('All-Day Breakfast'), ('Pastries');

INSERT INTO menu_items (category_id, name, description, price, image_url) VALUES
(1, 'Spanish Latte', 'Espresso with milk and condensed milk.', 145.00, NULL),
(1, 'Americano', 'Double espresso with hot water.', 105.00, NULL),
(1, 'Cappuccino', 'Espresso, steamed milk, and foam.', 135.00, NULL),
(1, 'Caramel Macchiato', 'Vanilla, espresso, milk, and caramel.', 165.00, NULL),
(2, 'Matcha Latte', 'Ceremonial matcha with milk.', 155.00, NULL),
(2, 'Chocolate Latte', 'Rich cocoa with steamed milk.', 145.00, NULL),
(3, 'Longganisa Breakfast', 'Longganisa, egg, garlic rice.', 225.00, NULL),
(3, 'Tapsilog', 'Beef tapa, egg, garlic rice.', 235.00, NULL),
(4, 'Butter Croissant', 'Fresh butter croissant.', 95.00, NULL),
(4, 'Banana Bread', 'House banana bread slice.', 85.00, NULL);

INSERT INTO ingredients (name, unit, stock_qty, min_stock_level, stock_updated_at) VALUES
-- 30-day usage / resulting remaining: Coffee beans 2844 / 1156; Fresh milk 28200 / 1800;
-- Condensed milk 2250 / 250; Vanilla syrup 300 / 800; Caramel sauce 225 / 375;
-- Matcha powder 320 / 130; Cocoa powder 90 / 810; Longganisa 36 / 9;
-- Beef tapa 8 / 27; Eggs 44 / 26.
('Coffee beans', 'g', 4000, 1200, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Fresh milk', 'ml', 30000, 2000, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Condensed milk', 'ml', 2500, 300, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Vanilla syrup', 'ml', 1100, 700, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Caramel sauce', 'ml', 600, 350, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Matcha powder', 'g', 450, 80, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Cocoa powder', 'g', 900, 250, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Longganisa', 'portion', 45, 5, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Beef tapa', 'portion', 35, 15, DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
('Eggs', 'piece', 70, 15, DATE_SUB(CURDATE(), INTERVAL 30 DAY));

-- Recipes use quantities in the corresponding ingredient unit.
INSERT INTO recipes (menu_item_id, ingredient_id, qty_per_item) VALUES
(1, 1, 18), (1, 2, 180), (1, 3, 25),
(2, 1, 18),
(3, 1, 18), (3, 2, 150),
(4, 1, 18), (4, 2, 180), (4, 4, 20), (4, 5, 15),
(5, 2, 180), (5, 6, 8),
(6, 2, 180), (6, 7, 18),
(7, 8, 1), (7, 10, 1),
(8, 9, 1), (8, 10, 1);

DELIMITER $$
CREATE PROCEDURE seed_sample_sales()
BEGIN
    DECLARE day_offset INT DEFAULT 0;
    DECLARE new_sale_id INT;
    DECLARE sale_total DECIMAL(10,2);

    WHILE day_offset < 30 DO
        INSERT INTO sales (user_id, total_amount, sold_at)
        VALUES (IF(MOD(day_offset, 4) = 0, 1, 2), 0, DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 29 DAY), INTERVAL day_offset DAY));
        SET new_sale_id = LAST_INSERT_ID();

        -- Coffee intentionally moves fastest; banana bread (item 10) gets no sales.
        INSERT INTO sale_items (sale_id, menu_item_id, quantity, unit_price) VALUES
        (new_sale_id, 1, 2 + MOD(day_offset, 3), 145.00),
        (new_sale_id, 2, 1 + MOD(day_offset, 2), 105.00),
        (new_sale_id, 5, IF(MOD(day_offset, 3) = 0, 2, 1), 155.00),
        (new_sale_id, 7, IF(MOD(day_offset, 5) = 0, 2, 1), 225.00);

        IF MOD(day_offset, 2) = 0 THEN
            INSERT INTO sale_items (sale_id, menu_item_id, quantity, unit_price) VALUES
            (new_sale_id, 4, 1, 165.00), (new_sale_id, 9, 1, 95.00);
        END IF;
        IF MOD(day_offset, 4) = 0 THEN
            INSERT INTO sale_items (sale_id, menu_item_id, quantity, unit_price) VALUES
            (new_sale_id, 3, 1, 135.00), (new_sale_id, 8, 1, 235.00);
        END IF;
        IF MOD(day_offset, 6) = 0 THEN
            INSERT INTO sale_items (sale_id, menu_item_id, quantity, unit_price) VALUES
            (new_sale_id, 6, 1, 145.00);
        END IF;

        SELECT SUM(quantity * unit_price) INTO sale_total FROM sale_items WHERE sale_id = new_sale_id;
        UPDATE sales SET total_amount = sale_total WHERE id = new_sale_id;
        SET day_offset = day_offset + 1;
    END WHILE;
END$$
DELIMITER ;

CALL seed_sample_sales();
DROP PROCEDURE seed_sample_sales;
