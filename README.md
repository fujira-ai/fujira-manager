# Fujira Manager Starter Template

## Upload target
Upload this whole directory to:

`https://fujira.tokyo/fujira-manager/`

## First setup
1. Edit `app/config.php`
2. Import `sql/schema.sql`
3. Confirm `https://fujira.tokyo/fujira-manager/index.php`
4. Set LINE webhook to `https://fujira.tokyo/fujira-manager/webhook.php`
5. Add cron jobs from `docs/cron-example.txt`

## Included folders
- `app/Core` : AI secretary logic
- `app/Storage` : DB repositories
- `app/Services` : LINE / cron / debug services
- `app/Helpers` : small helper functions
- `cron` : scheduled jobs
- `admin` : future admin pages
- `logs` : app and cron logs
- `sql` : initial MySQL schema
