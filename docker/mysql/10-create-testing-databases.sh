#!/usr/bin/env bash
# Creates the test database and lets the application user create the per-process databases
# (testing_test_1, testing_test_2, ...) that parallel test runs use.
mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<'SQL'
CREATE DATABASE IF NOT EXISTS testing;
GRANT ALL PRIVILEGES ON `testing%`.* TO 'sail'@'%';
SQL
