-- Database schema for Vehicle Maintenance Tracker
-- SQLite Version
--
-- To create the database:
-- sqlite3 maintenance_tracker.db < database-sqlite.sql
--
-- How to drop tables in constraint order:
DROP TABLE IF EXISTS maintenance_history;
DROP TABLE IF EXISTS vehicle_notes;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS maintenance_operations;
DROP TABLE IF EXISTS maintenance_items;
DROP TABLE IF EXISTS vehicle_models;
DROP TABLE IF EXISTS users;

-- Enable foreign key support (required for SQLite)
PRAGMA foreign_keys = ON;

-- Users table
CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email TEXT,
    administrator INTEGER DEFAULT 0,
    date_format TEXT DEFAULT 'Y-m-d',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Vehicle models table
CREATE TABLE vehicle_models (
    model_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    manufacturer TEXT NOT NULL,
    model_name TEXT NOT NULL,
    vehicle_type TEXT DEFAULT 'car',
    year_range TEXT,
    unit_meter TEXT DEFAULT 'km' CHECK (unit_meter IN ('km', 'miles', 'hours')),
    unit_time TEXT DEFAULT 'days' CHECK (unit_time IN ('days', 'weeks', 'months', 'years')),
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE (user_id, manufacturer, model_name)
);

-- Maintenance items (components/parts that need maintenance)
CREATE TABLE maintenance_items (
    item_id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_id INTEGER NOT NULL,
    item_name TEXT NOT NULL,
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE CASCADE,
    UNIQUE (model_id, item_name)
);

-- Maintenance operations (specific actions to be performed on items)
CREATE TABLE maintenance_operations (
    operation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    operation_name TEXT NOT NULL,
    interval_time INTEGER DEFAULT NULL,
    interval_meter REAL DEFAULT NULL,
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES maintenance_items(item_id) ON DELETE CASCADE,
    UNIQUE (item_id, operation_name)
);

-- Vehicles table
CREATE TABLE vehicles (
    vehicle_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    model_id INTEGER,
    license_plate TEXT,
    vin TEXT,
    nickname TEXT,
    start_date TEXT,
    effective_meter REAL DEFAULT 0,
    effective_date TEXT,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES vehicle_models(model_id) ON DELETE RESTRICT,
    UNIQUE (user_id, nickname)
);

-- Vehicle notes (free-form log entries per vehicle)
CREATE TABLE vehicle_notes (
    note_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER NOT NULL,
    note_date TEXT NOT NULL DEFAULT (date('now')),
    note_meter REAL,
    note TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
);

-- Maintenance history
CREATE TABLE maintenance_history (
    history_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER NOT NULL,
    operation_id INTEGER,
    operation_date TEXT NOT NULL,
    operation_meter REAL,
    cost REAL,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE,
    FOREIGN KEY (operation_id) REFERENCES maintenance_operations(operation_id) ON DELETE RESTRICT
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
INSERT INTO users (username, password_hash, email, administrator)
    VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 1);
