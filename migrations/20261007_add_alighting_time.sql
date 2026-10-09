ALTER TABLE trip_transactions
    ADD COLUMN alighting_time DATETIME NULL AFTER boarding_time;
