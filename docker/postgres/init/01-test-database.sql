-- Runs once, on first initialisation of the local PostgreSQL volume.
-- Creates the database used by the test suite inside the Compose stack.
CREATE DATABASE vendra_test OWNER vendra;
