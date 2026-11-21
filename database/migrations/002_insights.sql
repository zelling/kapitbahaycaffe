CREATE TABLE insights (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    range_from DATE NOT NULL,
    range_to DATE NOT NULL,
    data_hash CHAR(64) NOT NULL,
    summary_json TEXT NOT NULL,
    model VARCHAR(80) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_insights_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_insights_range_hash (range_from, range_to, data_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;