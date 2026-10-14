-- ONE-TIME: the server agent's database user (prepared, NOT switched on yet: run only after the owner's OK).
-- Read-only: it can look at data and make dumps (pg_dump needs only SELECT); it cannot change or remove
-- anything (no INSERT, UPDATE, DELETE, TRUNCATE, DROP or ALTER). Backups themselves run through
-- scripts/ops/backup-now with the application's own credentials.
--
-- Run as the database owner, in the application database:
--   psql -d <database> -v pw="'<a long random password>'" -f scripts/ops/opsbot-db.sql

CREATE ROLE opsbot_ro LOGIN PASSWORD :pw NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE :"DBNAME" TO opsbot_ro;
GRANT USAGE ON SCHEMA public TO opsbot_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO opsbot_ro;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO opsbot_ro;
-- Tables added by later migrations are readable too (run as the role that runs the migrations).
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO opsbot_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO opsbot_ro;
-- Belt and braces: even a future GRANT mistake cannot let it write.
ALTER ROLE opsbot_ro SET default_transaction_read_only = on;
