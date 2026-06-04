CREATE DATABASE IF NOT EXISTS trackfare;
USE trackfare;

-- ============================================================
-- USERS & PROFILES
-- ============================================================

CREATE TABLE users (
    user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(180) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          ENUM('passenger','driver','admin') NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    lat           DECIMAL(9,6) NULL,
    lng           DECIMAL(9,6) NULL,
    PRIMARY KEY (user_id)
);

CREATE TABLE passenger_profiles (
    profile_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    wallet_balance  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (profile_id)
);

CREATE TABLE driver_profiles (
    profile_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    assigned_bus_id INT UNSIGNED,
    PRIMARY KEY (profile_id)
);

-- ============================================================
-- NFC CARDS
-- ============================================================

CREATE TABLE nfc_cards (
    card_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL UNIQUE,
    uid       VARCHAR(60) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (card_id)
);

-- ============================================================
-- ROUTES, STOPS, ROUTE-STOPS
-- ============================================================

CREATE TABLE routes (
    route_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_name   VARCHAR(120) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (route_id)
);

-- lat / lng stored as DECIMAL(9,6) → ready for Mapbox / Leaflet / PostGIS
-- Leaflet uses (lat, lng); Mapbox/GeoJSON uses [lng, lat] — both work with these columns
CREATE TABLE stops (
    stop_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    stop_name    VARCHAR(120) NOT NULL,
    municipality VARCHAR(120) NOT NULL,
    lat          DECIMAL(9,6) NOT NULL,   -- latitude  (e.g.  14.821028)
    lng          DECIMAL(9,6) NOT NULL,   -- longitude (e.g. 120.902972)
    PRIMARY KEY (stop_id)
);

CREATE TABLE route_stops (
    route_id   INT UNSIGNED NOT NULL,
    stop_id    INT UNSIGNED NOT NULL,
    stop_order INT NOT NULL
);

-- ============================================================
-- BUSES
-- ============================================================

CREATE TABLE buses (
    bus_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_number   VARCHAR(20) NOT NULL,
    plate_number VARCHAR(20),
    PRIMARY KEY (bus_id)
);

-- ============================================================
-- TRIPS & PASSENGERS
-- ============================================================

CREATE TABLE trips (
    trip_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_id            INT UNSIGNED NOT NULL,
    route_id          INT UNSIGNED NOT NULL,
    driver_id         INT UNSIGNED NOT NULL,
    status            VARCHAR(20) NOT NULL,
    current_stop_index INT NOT NULL DEFAULT 0,
    start_time        DATETIME NULL,
    end_time          DATETIME NULL,
    PRIMARY KEY (trip_id)
);

CREATE TABLE active_passengers (
    active_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    card_id         INT UNSIGNED NOT NULL,
    boarding_stop_id INT UNSIGNED NOT NULL,
    tap_state       ENUM('in','out') DEFAULT 'in',
    lat             DECIMAL(9,6) NULL,
    lng             DECIMAL(9,6) NULL,
    tap_in_time     DATETIME NULL,
    PRIMARY KEY (active_id),
    UNIQUE KEY uq_user (user_id)
);

CREATE TABLE trip_transactions (
    transaction_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id          INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    card_id          INT UNSIGNED NOT NULL,
    boarding_stop_id INT UNSIGNED NOT NULL,
    alighting_stop_id INT UNSIGNED NOT NULL,
    fare_amount      DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (transaction_id)
);

-- ============================================================
-- SEED DATA — USERS
-- ============================================================

INSERT INTO users (full_name, email, password, role) VALUES
('System Admin',          'admin@gmail.com',       'password', 'admin'),

('Juan Dela Cruz',        'driver@gmail.com',       'password', 'driver'),
('Maria Santos',          'maria@gmail.com',         'password', 'driver'),
('Ricardo Gomez',         'ricardo@gmail.com',       'password', 'driver'),
('Elena Santos',          'elena@gmail.com',         'password', 'driver'),
('Mark Reyes',            'mark@gmail.com',          'password', 'driver'),

('Aaron Catapang',        'aaron@gmail.com',         'password', 'passenger'),
('John Michael Gonzales', 'jm@gmail.com',            'password', 'passenger'),
('Maria Lopez',           'maria.lopez@gmail.com',   'password', 'passenger'),
('Angela Reyes',          'angela@gmail.com',        'password', 'passenger'),
('Jin Park',              'jin@gmail.com',           'password', 'passenger'),
('Ana Garcia',            'ana@gmail.com',           'password', 'passenger'),
('Luis Santos',           'luis@gmail.com',          'password', 'passenger'),
('Miguel Torres',         'miguel@gmail.com',        'password', 'passenger'),
('Rosa Fernandez',        'rosa@gmail.com',          'password', 'passenger'),
('Carlo Ramos',           'carlo@gmail.com',         'password', 'passenger');

INSERT INTO passenger_profiles (user_id, wallet_balance)
SELECT user_id, 100 FROM users WHERE role = 'passenger';

-- ============================================================
-- SEED DATA — NFC CARDS
-- ============================================================

INSERT INTO nfc_cards (user_id, uid) VALUES
((SELECT user_id FROM users WHERE full_name = 'Aaron Catapang'),        '62 BB 1D 07'),
((SELECT user_id FROM users WHERE full_name = 'John Michael Gonzales'), '9B 4C 13 07');

-- ============================================================
-- SEED DATA — ROUTES
-- ============================================================

INSERT INTO routes (route_name, display_name) VALUES
('Balagtas-Monumento', 'Balagtas → Monumento'),
('Monumento-Balagtas', 'Monumento → Balagtas');

-- ============================================================
-- SEED DATA — STOPS (with decimal-degree coordinates)
--
-- Coordinates converted from DMS to DD: DD = D + M/60 + S/3600
-- Ready for Leaflet:  L.latLng(stop.lat, stop.lng)
-- Ready for Mapbox:   [stop.lng, stop.lat]   (GeoJSON order)
-- ============================================================

INSERT INTO stops (stop_name, municipality, lat, lng) VALUES
('ULTRA MEGA',          'Balagtas, Bulacan',  14.82005556, 120.90252778),  -- 14°49'15.7"N 120°54'10.7"E
('BALAGTAS ARENA',      'Balagtas, Bulacan',  14.812972, 120.912889),  -- 14°48'46.9"N 120°54'46.4"E
('GOLDEN CITY',         'Bocaue, Bulacan',    14.804250, 120.920278),  -- 14°48'15.3"N 120°55'13.0"E
('DR. YANGA\'S COLLEGE','Bocaue, Bulacan',    14.801861, 120.921472),  -- 14°48'06.7"N 120°55'19.3"E
('BOCAUE MARKET',       'Bocaue, Bulacan',    14.798556, 120.926194),  -- 14°47'54.8"N 120°55'34.3"E
('BUNLO JIL',           'Bocaue, Bulacan',    14.786611, 120.931778),  -- 14°47'11.8"N 120°55'54.4"E
('JONERS LOLOMBOY',     'Bocaue, Bulacan',    14.781139, 120.935333),  -- 14°46'52.1"N 120°56'07.2"E
('TOWN IN COUNTRY',     'Bocaue, Bulacan',    14.771556, 120.940333),  -- 14°46'17.6"N 120°56'25.2"E
('MARILAO TULAY',       'Marilao, Bulacan',   14.760833, 120.949583),  -- 14°45'39.0"N 120°56'58.5"E
('LIAS',                'Marilao, Bulacan',   14.757722, 120.952500),  -- 14°45'27.8"N 120°57'09.0"E
('SM MARILAO',          'Marilao, Bulacan',   14.754167, 120.954250),  -- 14°45'15.0"N 120°57'15.3"E
('MEDALLION HOMES',     'Meycauayan, Bulacan',14.750139, 120.956306),  -- 14°45'00.5"N 120°57'22.7"E
('MALHACAN',            'Meycauayan, Bulacan',14.738583, 120.960556),  -- 14°44'18.9"N 120°57'38.0"E
('BANGCAL',             'Meycauayan, Bulacan',14.723667, 120.959833),  -- 14°43'25.2"N 120°57'35.4"E
('MALANDAY',            'Valenzuela City',    14.715278, 120.957917),  -- 14°42'55.0"N 120°57'28.5"E
('DALANDANAN',          'Valenzuela City',    14.703583, 120.961667),  -- 14°42'12.9"N 120°57'42.0"E
('BALUBARAN',           'Valenzuela City',    14.697306, 120.963639),  -- 14°41'50.3"N 120°57'49.1"E
('MALINTA',             'Valenzuela City',    14.694444, 120.964139),  -- 14°41'40.0"N 120°57'50.9"E
('KARUHATAN',           'Valenzuela City',    14.686361, 120.976028),  -- 14°41'10.9"N 120°58'33.7"E
('VICTONICA MONUMENTO', 'Caloocan City',      14.65872222, 120.98447222); -- 14°39'28.4"N 120°59'02.2"E

-- ============================================================
-- SEED DATA — ROUTE STOPS
-- ============================================================

-- Route 1: Balagtas → Monumento (stop_id ascending)
INSERT INTO route_stops (route_id, stop_id, stop_order)
SELECT 1, stop_id, ROW_NUMBER() OVER (ORDER BY stop_id ASC) FROM stops;

-- Route 2: Monumento → Balagtas (reverse)
INSERT INTO route_stops (route_id, stop_id, stop_order)
SELECT 2, stop_id, ROW_NUMBER() OVER (ORDER BY stop_id DESC) FROM stops;

-- ============================================================
-- SEED DATA — BUSES
-- ============================================================

INSERT INTO buses (bus_number, plate_number) VALUES
('802', 'ABC-1234'),
('803', 'DEF-5678'),
('804', 'GHI-9012'),
('805', 'JKL-3456'),
('806', 'MNO-7890');

-- ============================================================
-- SEED DATA — DRIVER PROFILES
-- ============================================================

INSERT INTO driver_profiles (user_id, assigned_bus_id)
SELECT user_id, ROW_NUMBER() OVER ()
FROM users WHERE role = 'driver';

-- ============================================================
-- SEED DATA — TRIPS
-- ============================================================

INSERT INTO trips (bus_id, route_id, driver_id, status) VALUES
(1, 1, 2, 'active');

-- ============================================================
-- SEED DATA — ACTIVE PASSENGERS
-- ============================================================

INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES
(
    1,
    (SELECT user_id FROM users WHERE full_name = 'John Michael Gonzales'),
    (SELECT card_id FROM nfc_cards WHERE uid = '9B 4C 13 07'),
    9
);

-- ============================================================
-- SEED DATA — TRIP TRANSACTIONS (Trip 1)
-- ============================================================

INSERT INTO trip_transactions
    (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount)
VALUES
(1, (SELECT user_id FROM users WHERE full_name='Aaron Catapang'),        (SELECT card_id FROM nfc_cards WHERE uid='62 BB 1D 07'),  5, 11, 24.25),
(1, (SELECT user_id FROM users WHERE full_name='John Michael Gonzales'), (SELECT card_id FROM nfc_cards WHERE uid='9B 4C 13 07'),  9, 15, 22.00),
(1, (SELECT user_id FROM users WHERE full_name='Maria Lopez'),           1,  3, 10, 22.00),
(1, (SELECT user_id FROM users WHERE full_name='Angela Reyes'),          1,  8, 14, 19.75),
(1, (SELECT user_id FROM users WHERE full_name='Jin Park'),              1,  1, 20, 46.75),
(1, (SELECT user_id FROM users WHERE full_name='Ana Garcia'),            1,  4, 11, 24.25),
(1, (SELECT user_id FROM users WHERE full_name='Luis Santos'),           1, 10, 18, 24.25),
(1, (SELECT user_id FROM users WHERE full_name='Miguel Torres'),         1,  6, 13, 22.00),
(1, (SELECT user_id FROM users WHERE full_name='Rosa Fernandez'),        1,  2,  9, 19.75),
(1, (SELECT user_id FROM users WHERE full_name='Carlo Ramos'),           1, 11, 20, 31.00);

-- ============================================================
-- SEED DATA — MORE TRIPS (completed)
-- ============================================================

INSERT INTO trips (bus_id, route_id, driver_id, status) VALUES
(2, 1, 3, 'completed'),
(3, 1, 4, 'completed'),
(4, 1, 5, 'completed'),
(5, 1, 6, 'completed');

INSERT INTO trip_transactions
    (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount)
VALUES
(2,  7, 1,  1, 10, 22.00),
(2,  8, 2,  5, 15, 31.00),
(2,  9, 1,  3, 20, 42.25),
(3, 10, 1,  7, 14, 19.75),
(3, 11, 1,  1, 20, 46.75),
(4, 12, 1,  2, 12, 26.50),
(4, 13, 1,  6, 17, 33.25),
(5, 14, 1,  4,  9, 15.25),
(5, 15, 1, 10, 20, 28.75),
(5, 16, 1,  3, 18, 37.75);

-- ============================================================
-- SEED DATA — WALLET BALANCE UPDATES
-- ============================================================

UPDATE passenger_profiles SET wallet_balance = 650.00
WHERE user_id = (SELECT user_id FROM users WHERE full_name = 'Aaron Catapang');

UPDATE users SET lat = 14.697278, lng = 120.963722
WHERE full_name = 'Aaron Catapang';

UPDATE passenger_profiles SET wallet_balance = 420.00
WHERE user_id = (SELECT user_id FROM users WHERE full_name = 'John Michael Gonzales');

UPDATE passenger_profiles SET wallet_balance = 1180.00
WHERE user_id = (SELECT user_id FROM users WHERE full_name = 'Maria Lopez');

-- ============================================================
-- SEED DATA — ADDITIONAL NFC CARDS
-- ============================================================

INSERT INTO nfc_cards (user_id, uid) VALUES
((SELECT user_id FROM users WHERE full_name = 'Maria Lopez'),   'AA BB CC 01'),
((SELECT user_id FROM users WHERE full_name = 'Angela Reyes'),  'AA BB CC 02'),
((SELECT user_id FROM users WHERE full_name = 'Jin Park'),      'AA BB CC 03'),
((SELECT user_id FROM users WHERE full_name = 'Ana Garcia'),    'AA BB CC 04'),
((SELECT user_id FROM users WHERE full_name = 'Luis Santos'),   'AA BB CC 05');

-- ============================================================
-- UPGRADE (existing DBs only — skip on fresh import)
-- Run if you already have route 1 but not route 2:
--
-- INSERT INTO routes (route_name, display_name) VALUES
-- ('Monumento-Balagtas', 'Monumento → Balagtas');
--
-- INSERT INTO route_stops (route_id, stop_id, stop_order)
-- SELECT 2, stop_id, ROW_NUMBER() OVER (ORDER BY stop_id DESC) FROM stops;
-- ============================================================