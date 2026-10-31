#!/bin/sh
# Shell instead of .sql so the grant uses the MYSQL_USER configured in compose.
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS incidents_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON incidents_test.* TO '$MYSQL_USER'@'%';
SQL
