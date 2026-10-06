-- Creates a database dedicated to automated tests, separate from the
-- MYSQL_DATABASE used for development, so test runs (migrations, factories,
-- the isolation test) never read or write development data.
--
-- Only runs on a fresh mysql_data volume (MySQL's own initdb behaviour).
-- The username is hardcoded to match backend/.env.example's default
-- (MYSQL_USER=barber_booking); if you change that username, re-run the
-- GRANT below manually for the new one.
CREATE DATABASE IF NOT EXISTS barber_booking_test;
GRANT ALL PRIVILEGES ON barber_booking_test.* TO 'barber_booking'@'%';
FLUSH PRIVILEGES;
