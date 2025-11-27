-- =====================================================================
-- Performance Indexes for microbiology_logs Table
-- Purpose:
--   These indexes significantly improve lookup & filtering speed for:
--     - Dashboard queries
--     - Documents search
--     - Row-level search (data_show)
--     - Statistics module
--
-- NOTE:
--   1) SHOW INDEXES is safe to execute and helps confirm current index state.
--   2) MySQL does NOT support "CREATE INDEX IF NOT EXISTS".
--      This script keeps your syntax unchanged, but you may need conditional
--      checks or manual verification depending on server version.
-- =====================================================================


-- =====================================================================
-- Display existing indexes before modifications
-- Useful for confirming which indexes already exist.
-- =====================================================================
SHOW INDEXES FROM microbiology_logs;


-- =====================================================================
-- INDEX CREATION
-- These indexes target the most common filters:
--
--   table_date        → used heavily in dashboard + docs
--   product           → used in searches & statistics
--   table_name        → documents grouping & searches
--   code              → lot-based filtering
--   (table_name, table_date) → composite accelerates grouped searches
--   (product, table_date)    → composite improves filtering in stats
--
-- MySQL Warning:
--   "IF NOT EXISTS" is not officially supported in MySQL < 8.0.21.
--   Some installations will error if the index already exists.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_table_date ON microbiology_logs(table_date);

CREATE INDEX IF NOT EXISTS idx_product ON microbiology_logs(product);

CREATE INDEX IF NOT EXISTS idx_table_name ON microbiology_logs(table_name);

CREATE INDEX IF NOT EXISTS idx_code ON microbiology_logs(code);

CREATE INDEX IF NOT EXISTS idx_table_name_date 
    ON microbiology_logs(table_name, table_date);

CREATE INDEX IF NOT EXISTS idx_product_date 
    ON microbiology_logs(product, table_date);


-- =====================================================================
-- Display indexes again to verify that all intended indexes exist.
-- =====================================================================
SHOW INDEXES FROM microbiology_logs;
