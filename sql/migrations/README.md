# Migrations

`sql/schema.sql` already contains every change through migration 025. Put later changes here as
`026_short_name.sql`, `027_...sql`, and so on. `install.sh` (and `dw-migrate`) applies each file
whose number is not yet in the `dw_schema_migrations` table, in order, then records it. Write migrations
so they are safe to run once on any install: no brand codes, emails or other tenant data.
