-- Base aparte para correr las pruebas automatizadas contra MySQL.
CREATE DATABASE IF NOT EXISTS marcaje_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON marcaje_test.* TO 'marcaje'@'%';
FLUSH PRIVILEGES;
