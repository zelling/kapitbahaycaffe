ALTER TABLE users
    ADD COLUMN identity_status ENUM('pending', 'verified') NOT NULL DEFAULT 'pending' AFTER role,
    ADD COLUMN identity_verified_at DATETIME NULL AFTER identity_status;

CREATE TABLE identity_checks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    stage VARCHAR(20) NOT NULL,
    result VARCHAR(20) NOT NULL,
    id_type VARCHAR(20) NULL,
    id_number_masked VARCHAR(20) NULL,
    age_at_check TINYINT UNSIGNED NULL,
    match_distance DECIMAL(6,4) NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_identity_checks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_identity_checks_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
