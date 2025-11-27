-- =====================================================================
-- MicrobiologyApp Database Schema
-- Version: 1.0
-- This schema defines all core tables used by the application:
--   - users: authentication & authorization
--   - microbiology_logs: all microbiological sample entries
--   - audit_logs: tracking of modify actions for security & traceability
--   - products_cache: synchronized product data from external API
-- =====================================================================


-- =====================================================================
-- Create main database (UTF-8 MB4 for full Unicode support)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS microbiologyapp 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE microbiologyapp;


-- =====================================================================
-- USERS TABLE
-- Stores login information, hashed passwords, roles, and timestamps.
-- Includes:
--   - Unique username constraint
--   - ENUM role system (admin/user)
--   - Automatic timestamp maintenance
-- =====================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Username used for login (unique)
    name VARCHAR(100) NOT NULL UNIQUE,

    -- Hashed password using password_hash() in PHP
    password_hash VARCHAR(255) NOT NULL,

    -- Optional full display name
    fullname VARCHAR(255),

    -- Access role: admin or user (defaults to admin for first install)
    role ENUM('admin','user') NOT NULL DEFAULT 'admin',

    -- Timestamps automatically managed by MySQL
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Index to speed up login search
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- MICROBIOLOGY LOGS TABLE
-- Stores every microbiological record submitted through the application.
-- Supports:
--   - Header fields per table (name, description, date)
--   - Multiple rows for each table (row_index)
--   - Sample data: product, batch, expiration
--   - Microbiological measurements (DECIMAL for accurate numeric storage)
--   - Evaluation fields and comments
--   - Multiple indexing paths for optimized filtering and reporting
-- =====================================================================
CREATE TABLE IF NOT EXISTS microbiology_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Log sheet name (e.g., “Yogurt line – finished products”)
    table_name VARCHAR(200) NOT NULL,

    -- Optional description for the whole sheet
    table_description TEXT,

    -- Date of the log sheet
    table_date DATE NOT NULL,

    -- Incubation profile used for the tests
    incubation_profile VARCHAR(50) NULL,

    -- Index number of row within the sheet (1,2,3...)
    row_index INT NOT NULL DEFAULT 1,

    -- Product and related identification fields
    product VARCHAR(200),
    code VARCHAR(100),
    expiration_date DATE,

    -- Microbiological results (stored as decimal values)
    enterobacteriacea DECIMAL(10,2),
    tmc_30 DECIMAL(10,2),
    yeasts_molds DECIMAL(10,2),
    bacillus DECIMAL(10,2),

    -- Evaluation notes by day
    eval_2nd VARCHAR(500),
    eval_3rd VARCHAR(500),
    eval_4th VARCHAR(500),

    -- Stress test + general comments
    stress_test VARCHAR(500),
    comments TEXT,

    -- Automatic timestamps for auditing and sorting
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes optimized for filtering and reporting
    INDEX idx_table_date (table_date),
    INDEX idx_table_name (table_name),
    INDEX idx_product (product),
    INDEX idx_code (code),
    INDEX idx_table_name_date (table_name, table_date),
    INDEX idx_product_date (product, table_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- AUDIT LOGS TABLE
-- Tracks changes made to any record, storing:
--   - User performing the action
--   - Target table and record ID
--   - JSON snapshots of old and new values
--   - User agent & IP for security
--   - Timestamps
--   - Foreign key refers to users table
-- This is essential for traceability.
-- =====================================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- The user who performed the action
    user_id INT NOT NULL,

    -- Action type (create/update/delete/login/etc.)
    action VARCHAR(50) NOT NULL,

    -- Name of the affected table
    table_name VARCHAR(100) NOT NULL,

    -- ID of the affected record (may be NULL for general actions)
    record_id INT,

    -- JSON fields capturing differences before/after the change
    old_values JSON,
    new_values JSON,

    -- Security context data
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes for fast audit lookup
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_record (table_name, record_id),
    INDEX idx_action (action),

    -- Enforce that audit entries reference valid users
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- PRODUCTS CACHE TABLE
-- Stores synchronized product data imported periodically from an external API.
-- Cached fields:
--   - code, name (text descriptors)
--   - mtrl (key numeric field)
--   - updated_at auto-updates whenever record changes
-- Includes:
--   - UNIQUE constraint for MTRL numbers
--   - Indexes for API-driven lookups
-- =====================================================================
CREATE TABLE IF NOT EXISTS products_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- API product code (short text)
    code VARCHAR(50) NOT NULL,

    -- Product descriptive name
    name VARCHAR(255) NOT NULL,

    -- Unique material number
    mtrl INT NOT NULL,

    -- Auto-updated timestamp
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,

    -- Ensure we don't store duplicates of the same MTRL
    UNIQUE KEY uq_products_mtrl (mtrl),

    -- Fast search helpers
    INDEX idx_products_code (code),
    INDEX idx_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
