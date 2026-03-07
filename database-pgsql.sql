-- Database schema for Vehicle Maintenance Tracker
-- PostgreSQL Version
--
-- Create database (run separately as superuser if needed)
-- CREATE DATABASE maintenance_tracker;
-- \c maintenance_tracker;

-- How to drop tables in constraint order:
DROP TABLE maintenance_history;
DROP TABLE vehicle_notes;
DROP TABLE vehicles;
DROP TABLE maintenance_operations;
DROP TABLE maintenance_items;
DROP TABLE vehicle_models;
DROP TABLE users;

-- How to reset serial sequences after data import:
-- SELECT setval(pg_get_serial_sequence('users', 'user_id'), (SELECT MAX(user_id) FROM users));
-- SELECT setval(pg_get_serial_sequence('vehicle_models', 'model_id'), (SELECT MAX(model_id) FROM vehicle_models));
-- SELECT setval(pg_get_serial_sequence('maintenance_items', 'item_id'), (SELECT MAX(item_id) FROM maintenance_items));
-- SELECT setval(pg_get_serial_sequence('maintenance_operations', 'operation_id'), (SELECT MAX(operation_id) FROM maintenance_operations));
-- SELECT setval(pg_get_serial_sequence('vehicles', 'vehicle_id'), (SELECT MAX(vehicle_id) FROM vehicles));
-- SELECT setval(pg_get_serial_sequence('vehicle_notes', 'note_id'), (SELECT MAX(note_id) FROM vehicle_notes));
-- SELECT setval(pg_get_serial_sequence('maintenance_history', 'history_id'), (SELECT MAX(history_id) FROM maintenance_history));

-- Users table
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    administrator BOOLEAN DEFAULT FALSE,
    date_format VARCHAR(20) DEFAULT 'Y-m-d',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Vehicle models table
CREATE TABLE vehicle_models (
    model_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    manufacturer VARCHAR(100) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    vehicle_type VARCHAR(50) DEFAULT 'car',
    year_range VARCHAR(50),
    unit_meter VARCHAR(10) CHECK (unit_meter IN ('km', 'miles', 'hours')) DEFAULT 'km',
    unit_time VARCHAR(10) CHECK (unit_time IN ('days', 'weeks', 'months', 'years')) DEFAULT 'days',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vm_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT uk_user_manufacturer_model UNIQUE (user_id, manufacturer, model_name)
);

-- Maintenance items (components/parts that need maintenance)
CREATE TABLE maintenance_items (
    item_id SERIAL PRIMARY KEY,
    model_id INTEGER NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mi_model FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE CASCADE,
    CONSTRAINT uk_model_item UNIQUE (model_id, item_name)
);

-- Maintenance operations (specific actions to be performed on items)
CREATE TABLE maintenance_operations (
    operation_id SERIAL PRIMARY KEY,
    item_id INTEGER NOT NULL,
    operation_name VARCHAR(200) NOT NULL,
    interval_time INTEGER DEFAULT NULL,
    interval_meter NUMERIC(10,2) DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mo_item FOREIGN KEY (item_id) REFERENCES maintenance_items(item_id) ON DELETE CASCADE,
    CONSTRAINT uk_operation_name UNIQUE (item_id, operation_name)
);

-- Vehicles table
CREATE TABLE vehicles (
    vehicle_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    model_id INTEGER,
    license_plate VARCHAR(20),
    vin VARCHAR(50),
    nickname VARCHAR(100),
    start_date DATE,
    effective_meter NUMERIC(10,2) DEFAULT 0,
    effective_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_v_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_v_model FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE RESTRICT,
    CONSTRAINT uk_nickname UNIQUE (user_id, nickname)
);

-- Vehicle notes (free-form log entries per vehicle)
CREATE TABLE vehicle_notes (
    note_id SERIAL PRIMARY KEY,
    vehicle_id INTEGER NOT NULL,
    note_date DATE NOT NULL DEFAULT CURRENT_DATE,
    note_meter NUMERIC(10,2),
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vn_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
);

-- Maintenance history
CREATE TABLE maintenance_history (
    history_id SERIAL PRIMARY KEY,
    vehicle_id INTEGER NOT NULL,
    operation_id INTEGER,
    operation_date DATE NOT NULL,
    operation_meter NUMERIC(10,2),
    cost NUMERIC(10,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mh_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE,
    CONSTRAINT fk_mh_operation FOREIGN KEY (operation_id) REFERENCES maintenance_operations(operation_id) ON DELETE RESTRICT
);

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
INSERT INTO users (username, password_hash, email, administrator).
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', TRUE);
