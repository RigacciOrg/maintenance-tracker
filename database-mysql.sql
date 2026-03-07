-- Database schema for Vehicle Maintenance Tracker
-- MySQL Version
--
-- Create database (run separately if needed)
-- CREATE DATABASE maintenance_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE maintenance_tracker;

-- How to drop tables in constraint order:
DROP TABLE IF EXISTS maintenance_history;
DROP TABLE IF EXISTS vehicle_notes;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS maintenance_operations;
DROP TABLE IF EXISTS maintenance_items;
DROP TABLE IF EXISTS vehicle_models;
DROP TABLE IF EXISTS users;

-- How to reset auto_increment after data import:
-- ALTER TABLE users AUTO_INCREMENT = (SELECT MAX(user_id) + 1 FROM users);
-- ALTER TABLE vehicle_models AUTO_INCREMENT = (SELECT MAX(model_id) + 1 FROM vehicle_models);
-- ALTER TABLE maintenance_items AUTO_INCREMENT = (SELECT MAX(item_id) + 1 FROM maintenance_items);
-- ALTER TABLE maintenance_operations AUTO_INCREMENT = (SELECT MAX(operation_id) + 1 FROM maintenance_operations);
-- ALTER TABLE vehicles AUTO_INCREMENT = (SELECT MAX(vehicle_id) + 1 FROM vehicles);
-- ALTER TABLE vehicle_notes AUTO_INCREMENT = (SELECT MAX(note_id) + 1 FROM vehicle_notes);
-- ALTER TABLE maintenance_history AUTO_INCREMENT = (SELECT MAX(history_id) + 1 FROM maintenance_history);

-- Users table
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    administrator BOOLEAN DEFAULT FALSE,
    date_format VARCHAR(20) DEFAULT 'Y-m-d',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle models table
CREATE TABLE vehicle_models (
    model_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    manufacturer VARCHAR(100) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    vehicle_type VARCHAR(50) DEFAULT 'car',
    year_range VARCHAR(50),
    unit_meter VARCHAR(10) DEFAULT 'km',
    unit_time VARCHAR(10) DEFAULT 'days',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vm_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT uk_user_manufacturer_model UNIQUE (user_id, manufacturer, model_name),
    CONSTRAINT chk_unit_meter CHECK (unit_meter IN ('km', 'miles', 'hours')),
    CONSTRAINT chk_unit_time CHECK (unit_time IN ('days', 'weeks', 'months', 'years'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance items (components/parts that need maintenance)
CREATE TABLE maintenance_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    model_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mi_model FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE CASCADE,
    CONSTRAINT uk_model_item UNIQUE (model_id, item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance operations (specific actions to be performed on items)
CREATE TABLE maintenance_operations (
    operation_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    operation_name VARCHAR(200) NOT NULL,
    interval_time INT DEFAULT NULL,
    interval_meter DECIMAL(10,2) DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mo_item FOREIGN KEY (item_id) REFERENCES maintenance_items(item_id) ON DELETE CASCADE,
    CONSTRAINT uk_operation_name UNIQUE (item_id, operation_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicles table
CREATE TABLE vehicles (
    vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    model_id INT,
    license_plate VARCHAR(20),
    vin VARCHAR(50),
    nickname VARCHAR(100),
    start_date DATE,
    effective_meter DECIMAL(10,2) DEFAULT 0,
    effective_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_v_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_v_model FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE RESTRICT,
    CONSTRAINT uk_nickname UNIQUE (user_id, nickname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle notes (free-form log entries per vehicle)
CREATE TABLE vehicle_notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    note_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    note_meter DECIMAL(10,2),
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vn_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance history
CREATE TABLE maintenance_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    operation_id INT,
    operation_date DATE NOT NULL,
    operation_meter DECIMAL(10,2),
    cost DECIMAL(10,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mh_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE,
    CONSTRAINT fk_mh_operation FOREIGN KEY (operation_id) REFERENCES maintenance_operations(operation_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for better query performance
CREATE INDEX idx_vehicle_models_user_id ON vehicle_models(user_id);
CREATE INDEX idx_maintenance_items_model_id ON maintenance_items(model_id);
CREATE INDEX idx_maintenance_operations_item_id ON maintenance_operations(item_id);
CREATE INDEX idx_vehicles_user_id ON vehicles(user_id);
CREATE INDEX idx_vehicles_model_id ON vehicles(model_id);
CREATE INDEX idx_maintenance_history_vehicle_id ON maintenance_history(vehicle_id);
CREATE INDEX idx_maintenance_history_operation_id ON maintenance_history(operation_id);
CREATE INDEX idx_vehicle_notes_vehicle_id ON vehicle_notes(vehicle_id);

-- Create 'admin' user with password 'password' (change immediately after first login)
INSERT INTO users (username, password_hash, email, administrator)
    VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', TRUE);
