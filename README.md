# Paradise Group Real Estate Lead SaaS + CRM (PHP + MySQL)

Production-ready multi-project real estate lead generation website and CRM built with:

- Core PHP (no framework)
- MySQL
- Tailwind CSS CDN
- Vanilla JavaScript
- PHPMailer (SMTP)
- Google Sheets API (service account)
- Session-based authentication
- WhatsApp click-to-chat integration

## Features

- Multi-project landing pages on `/project/{slug}`
- Lead capture form with anti-spam + validation
- Lead saved in MySQL with assignment and status
- Automatic email notification to admin via SMTP
- Automatic sync to Google Sheets
- Super admin panel:
  - Dashboard analytics
  - Create/manage projects
  - Create/manage sales agents
  - Assign leads
  - Filter/search/paginate leads
  - Reports by project and agent
- Sales agent panel:
  - Login dashboard
  - Assigned leads only
  - Status updates (New, Contacted, Follow-up, Closed)
  - WhatsApp quick contact per lead

## Folder Structure

- `index.php` - project listing homepage
- `project/index.php` - project landing page
- `api/submit_lead.php` - public lead submission endpoint
- `admin/login.php` - login
- `superadmin/*` - super admin panel
- `agent/*` - agent panel
- `core/*` - config, auth, DB, services
- `database/schema.sql` - DB schema
- `install.php` - one-click installer

## Local Setup (XAMPP)

1. Create database (example):
   - `paradise_crm`
2. Update DB config in `config.php`:
   - `db.host`, `db.name`, `db.user`, `db.pass`
3. Install PHPMailer (recommended):
   - Run `composer install`
   - If composer is not available on hosting, upload `vendor` folder from local machine.
4. Open installer in browser:
   - `http://localhost/paradisegroup/install.php`
5. Login:
   - URL: `http://localhost/paradisegroup/admin/login.php`
   - Email: `admin@paradisegroup.local`
   - Password: `admin123`

## SMTP Setup (PHPMailer)

Update `config.php` > `smtp`:

- `host`
- `port`
- `username`
- `password`
- `from_email`
- `from_name`
- `secure` (`tls` or `ssl`)

Admin notification email recipient:

- `contact.admin_email`

## Google Sheets Setup

1. Create Google Cloud project
2. Enable `Google Sheets API`
3. Create service account and generate key
4. Share target sheet with service account email
5. In `config.php` set:
   - `google_sheets.enabled = true`
   - `google_sheets.spreadsheet_id`
   - `google_sheets.sheet_name`
   - `google_sheets.service_account_email`
   - `google_sheets.private_key`

Expected sheet columns (A to I):

1. Date
2. Name
3. Mobile
4. Email
5. City
6. Message
7. Project
8. Assigned Agent
9. Status

## WhatsApp Integration

Configured automatically per project (or fallback default):

- Floating button on landing pages
- Quick contact links in CRM lead tables
- Format: `https://wa.me/91XXXXXXXXXX?text=...`

## Hostinger Deployment

1. Upload full project to `public_html` (or subfolder)
2. Import `database/schema.sql` in Hostinger MySQL
3. Update `config.php`:
   - `app.base_url`
   - DB credentials
   - SMTP credentials
   - Google Sheets credentials
4. Run `composer install` locally and upload `vendor` if SSH composer unavailable
5. Run `install.php` once then keep `.installed.lock`
6. Ensure `.htaccess` rewrite is enabled (Apache)

## Security Notes

- Change default admin password immediately
- Keep `config.php` secure and outside public repo if possible
- Use HTTPS in production
- Restrict write permission for `logs/`

## Optional Enhancements

- Lead scoring (hot/warm/cold)
- SMS integration
- WhatsApp bot automation
- Download brochure tracking
- Advanced dashboards/charts
