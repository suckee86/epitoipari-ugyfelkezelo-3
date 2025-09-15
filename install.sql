CREATE DATABASE IF NOT EXISTS epitoipari_ugyfelkezelo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE epitoipari_ugyfelkezelo;
--admin@example.com
--admin123

-- Felhasználók tábla
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'felmero', 'kivitelezo', 'ellenorzo', 'auditor') NOT NULL,
    active BOOLEAN DEFAULT 1
);

-- Projektek tábla
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(150) NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    template_type ENUM('arajanlat', 'megallapodas') NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Teszt admin felhasználó jelszó: admin123
INSERT INTO users (name, email, password, role, active)
VALUES ('Admin Teszt', 'admin@example.com', 
        '$2y$10$usqG/Nh.F1sKtZK3umtoIut1dOB3PbUwBz8o1us0Rm6YAHl3A8ENq', 
        'admin', 1);
