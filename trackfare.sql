CREATE DATABASE IF NOT EXISTS trackfare;
USE trackfare;

CREATE TABLE users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('passenger','driver','admin') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id)
);

CREATE TABLE passenger_profiles (
    profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (profile_id)
);

CREATE TABLE nfc_cards (
    card_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    uid VARCHAR(60) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (card_id)
);

CREATE TABLE routes (
    route_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_name VARCHAR(120) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (route_id)
);

CREATE TABLE stops (
    stop_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    stop_name VARCHAR(120) NOT NULL,
    municipality VARCHAR(120) NOT NULL,
    PRIMARY KEY (stop_id)
);

CREATE TABLE route_stops (
    route_id INT UNSIGNED NOT NULL,
    stop_id INT UNSIGNED NOT NULL,
    stop_order INT NOT NULL
);

CREATE TABLE buses (
    bus_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_number VARCHAR(20) NOT NULL,
    plate_number VARCHAR(20),
    PRIMARY KEY (bus_id)
);

CREATE TABLE driver_profiles (
    profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    assigned_bus_id INT UNSIGNED,
    PRIMARY KEY (profile_id)
);

CREATE TABLE trips (
    trip_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    PRIMARY KEY (trip_id)
);

CREATE TABLE active_passengers (
    active_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    card_id INT UNSIGNED NOT NULL,
    boarding_stop_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (active_id),
    UNIQUE KEY uq_user (user_id)
);

CREATE TABLE trip_transactions (
    transaction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    card_id INT UNSIGNED NOT NULL,
    boarding_stop_id INT UNSIGNED NOT NULL,
    alighting_stop_id INT UNSIGNED NOT NULL,
    fare_amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (transaction_id)
);

INSERT INTO users (full_name,email,password,role) VALUES
('System Admin','admin@gmail.com','password','admin'),

('Juan Dela Cruz','driver@gmail.com','password','driver'),
('Maria Santos','maria@gmail.com','password','driver'),
('Ricardo Gomez','ricardo@gmail.com','password','driver'),
('Elena Santos','elena@gmail.com','password','driver'),
('Mark Reyes','mark@gmail.com','password','driver'),

('Aaron Catapang','aaron@gmail.com','password','passenger'),
('John Michael Gonzales','jm@gmail.com','password','passenger'),
('Maria Lopez','maria.lopez@gmail.com','password','passenger'),
('Angela Reyes','angela@gmail.com','password','passenger'),
('Jin Park','jin@gmail.com','password','passenger'),
('Ana Garcia','ana@gmail.com','password','passenger'),
('Luis Santos','luis@gmail.com','password','passenger'),
('Miguel Torres','miguel@gmail.com','password','passenger'),
('Rosa Fernandez','rosa@gmail.com','password','passenger'),
('Carlo Ramos','carlo@gmail.com','password','passenger');

INSERT INTO passenger_profiles (user_id,wallet_balance)
SELECT user_id, 100 FROM users WHERE role='passenger';

	INSERT INTO nfc_cards (user_id,uid)
	VALUES
	((SELECT user_id FROM users WHERE full_name='Aaron Catapang'),'62 BB 1D 07'),
	((SELECT user_id FROM users WHERE full_name='John Michael Gonzales'),'9B 4C 13 07');

INSERT INTO routes (route_name,display_name) VALUES
('Balagtas-Monumento','Balagtas → Monumento');

INSERT INTO stops (stop_name,municipality) VALUES
('ULTRA MEGA','Balagtas, Bulacan'),
('BALAGTAS ARENA','Balagtas, Bulacan'),
('GOLDEN CITY','Bocaue, Bulacan'),
('DR. YANGA’S COLLEGE','Bocaue, Bulacan'),
('BOCAUE MARKET','Bocaue, Bulacan'),
('BUNLO JIL','Bocaue, Bulacan'),
('JONERS LOLOMBOY','Bocaue, Bulacan'),
('TOWN IN COUNTRY','Bocaue, Bulacan'),
('MARILAO TULAY','Marilao, Bulacan'),
('LIAS','Marilao, Bulacan'),
('SM MARILAO','Marilao, Bulacan'),
('MEDALLION HOMES','Meycauayan, Bulacan'),
('MALHACAN','Meycauayan, Bulacan'),
('BANGCAL','Meycauayan, Bulacan'),
('MALANDAY','Valenzuela City'),
('DALANDANAN','Valenzuela City'),
('BALUBARAN','Valenzuela City'),
('MALINTA','Valenzuela City'),
('KARUHATAN','Valenzuela City'),
('VICTONICA MONUMENTO','Caloocan City');

INSERT INTO route_stops (route_id,stop_id,stop_order)
SELECT 1, stop_id, ROW_NUMBER() OVER() FROM stops;

INSERT INTO buses (bus_number,plate_number) VALUES
('802','ABC-1234'),
('803','DEF-5678'),
('804','GHI-9012'),
('805','JKL-3456'),
('806','MNO-7890');

INSERT INTO driver_profiles (user_id,assigned_bus_id)
SELECT user_id, ROW_NUMBER() OVER()
FROM users WHERE role='driver';

INSERT INTO trips (bus_id,route_id,driver_id,status)
VALUES
(1,1,2,'active');

INSERT INTO active_passengers
(trip_id,user_id,card_id,boarding_stop_id)
VALUES
(
    1,
    (SELECT user_id FROM users WHERE full_name='John Michael Gonzales'),
    (SELECT card_id FROM nfc_cards WHERE uid='9B 4C 13 07'),
    9
);

INSERT INTO trip_transactions
(trip_id,user_id,card_id,boarding_stop_id,alighting_stop_id,fare_amount)
VALUES

(
    1,
    (SELECT user_id FROM users WHERE full_name='Aaron Catapang'),
    (SELECT card_id FROM nfc_cards WHERE uid='62 BB 1D 07'),
    5,
    11,
    24.25
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='John Michael Gonzales'),
    (SELECT card_id FROM nfc_cards WHERE uid='9B 4C 13 07'),
    9,
    15,
    22.00
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Maria Lopez'),
    1,
    3,
    10,
    22.00
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Angela Reyes'),
    1,
    8,
    14,
    19.75
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Jin Park'),
    1,
    1,
    20,
    46.75
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Ana Garcia'),
    1,
    4,
    11,
    24.25
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Luis Santos'),
    1,
    10,
    18,
    24.25
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Miguel Torres'),
    1,
    6,
    13,
    22.00
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Rosa Fernandez'),
    1,
    2,
    9,
    19.75
),

(
    1,
    (SELECT user_id FROM users WHERE full_name='Carlo Ramos'),
    1,
    11,
    20,
    31.00
);

INSERT INTO trips (bus_id,route_id,driver_id,status)
VALUES
(2,1,3,'completed'),
(3,1,4,'completed'),
(4,1,5,'completed'),
(5,1,6,'completed');

INSERT INTO trip_transactions
(trip_id,user_id,card_id,boarding_stop_id,alighting_stop_id,fare_amount)
VALUES

(2,7,1,1,10,22.00),
(2,8,2,5,15,31.00),
(2,9,1,3,20,42.25),

(3,10,1,7,14,19.75),
(3,11,1,1,20,46.75),

(4,12,1,2,12,26.50),
(4,13,1,6,17,33.25),

(5,14,1,4,9,15.25),
(5,15,1,10,20,28.75),
(5,16,1,3,18,37.75);

UPDATE passenger_profiles
SET wallet_balance = 650.00
WHERE user_id = (
    SELECT user_id
    FROM users
    WHERE full_name='Aaron Catapang'
);

UPDATE passenger_profiles
SET wallet_balance = 420.00
WHERE user_id = (
    SELECT user_id
    FROM users
    WHERE full_name='John Michael Gonzales'
);

UPDATE passenger_profiles
SET wallet_balance = 1180.00
WHERE user_id = (
    SELECT user_id
    FROM users
    WHERE full_name='Maria Lopez'
);

INSERT INTO nfc_cards (user_id,uid)
VALUES
((SELECT user_id FROM users WHERE full_name='Maria Lopez'),'AA BB CC 01'),
((SELECT user_id FROM users WHERE full_name='Angela Reyes'),'AA BB CC 02'),
((SELECT user_id FROM users WHERE full_name='Jin Park'),'AA BB CC 03'),
((SELECT user_id FROM users WHERE full_name='Ana Garcia'),'AA BB CC 04'),
((SELECT user_id FROM users WHERE full_name='Luis Santos'),'AA BB CC 05');

ALTER TABLE trips
ADD current_stop_index INT NOT NULL DEFAULT 0,
ADD start_time DATETIME NULL,
ADD end_time DATETIME NULL;

ALTER TABLE active_passengers
ADD tap_state ENUM('in','out') DEFAULT 'in';

-- UPDATE nfc_cards SET uid = '62 BB 1D 07' WHERE user_id = 1;
-- UPDATE nfc_cards SET uid = '9B 4C 13 07' WHERE user_id = 2;