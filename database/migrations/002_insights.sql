CREATE TABLE insights (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_key VARCHAR(40) NOT NULL,
    statistics_hash CHAR(64) NOT NULL,
    summary TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_insights_period_hash (period_key, statistics_hash),
    INDEX idx_insights_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
