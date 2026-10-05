USE trackfare;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'driver_profiles' AND COLUMN_NAME = 'wallet_balance');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE driver_profiles ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER assigned_bus_id',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS fare_splits (
    split_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id INT UNSIGNED NOT NULL UNIQUE,
    trip_id        INT UNSIGNED NOT NULL,
    passenger_id   INT UNSIGNED NOT NULL,
    driver_id      INT UNSIGNED NOT NULL,
    fare_amount    DECIMAL(10,2) NOT NULL,
    driver_share   DECIMAL(10,2) NOT NULL,
    admin_share    DECIMAL(10,2) NOT NULL,
    driver_rate    DECIMAL(5,4) NOT NULL DEFAULT 0.2000,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (split_id),
    KEY idx_driver_created (driver_id, created_at),
    KEY idx_trip (trip_id),
    CONSTRAINT chk_split_sum CHECK (driver_share + admin_share = fare_amount)
);