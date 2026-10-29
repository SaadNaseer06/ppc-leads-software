# Environments

| `APP_ENV` | Purpose | Database |
|-----------|---------|----------|
| `local` | Local machine | Local MySQL/MariaDB (XAMPP or `docker compose`) |
| `staging` | Shared test / Developer sandbox | Separate MySQL database |
| `production` | Live business data | The hosting's MySQL (cPanel) or a managed MySQL 8 |

## Rules

1. **Never** point a Developer account or a staging deploy at the production database.
2. Use a distinct `APP_KEY` per environment, and separate Tawk.to webhook secrets.
   Changing `APP_KEY` makes stored Tawk.to secrets unreadable (they are encrypted with it), so set them again afterwards.
3. Demo and load-test data (`php artisan db:seed`, `php artisan leads:load-test`) are for development/staging only.
   The load-test command refuses to run when `APP_ENV=production`.
4. The dashboard shows a warning when `APP_ENV=production` and the signed-in role is Developer.
5. `activity_logs` records assignments, exports, user and website changes for audit, without secrets.

## Schema changes

Schema changes are Laravel migrations in `database/migrations`.

```bash
php artisan make:migration add_something_to_leads   # write the change
php artisan migrate                                  # apply locally
php artisan migrate --force                          # staging/production deploy (never drops data)
```

The `leads` table can hold millions of rows. On large tables, adding an index or column locks or rebuilds the
table, so schedule those migrations off-peak (or use an online schema-change tool), and keep the covering indexes
described in the README in mind when adding filters.
