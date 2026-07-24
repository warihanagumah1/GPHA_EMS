# GPHA Emergency Medical Services

GPHA EMS is a Laravel application for managing ambulance readiness, operational movements, mileage readings, radio and availability checks, weekly activities, audit history, and management reports.

The application integrates with GPHA Central Login for authentication, branch context, and component-level permissions.

## Main features

- Ambulance fleet registration, status, location, compliance dates, and odometer management.
- Dispatch and operational movement recording.
- Scheduled weekly and service mileage readings.
- Mileage movement summaries by ambulance and reporting period.
- Grouped radio communication and availability check sessions.
- Weekly operational activities, outcomes, owners, and follow-up actions.
- Filterable analytics dashboard and operational CSV export.
- Printable mileage, operational activity, and availability reports.
- Daily, weekly, monthly, quarterly, six-month, annual, and custom reporting periods.
- Branch-scoped records and detailed audit history.
- Responsive navigation, mobile filter panels, and 15-record pagination.

## Technology requirements

- PHP 8.3 or later.
- Composer 2.
- Node.js 20.19 or later.
- npm.
- SQLite, MySQL, MariaDB, or PostgreSQL.
- A web server such as Nginx or Apache.

The project currently uses Laravel 13, Livewire 3, Volt, Vite, and Tailwind CSS.

## Local installation

Clone or copy the project, then run:

```bash
cd EmergencyUnit
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
php artisan migrate
```

For local development:

```bash
composer run dev
```

Alternatively, run the processes separately:

```bash
php artisan serve
npm run dev
```

The default local application URL in `.env.example` is:

```text
http://127.0.0.1:8001
```

## Environment configuration

Never commit the production `.env` file or real SSO secrets.

Important application settings:

```env
APP_NAME="GPHA EMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ems.example.org
```

### Database

For SQLite:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/EmergencyUnit/database/database.sqlite
```

Create the file when starting with a new database:

```bash
touch database/database.sqlite
php artisan migrate
```

For MySQL or MariaDB:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gpha_ems
DB_USERNAME=gpha_ems
DB_PASSWORD=replace-with-a-secure-password
```

The application uses database-backed sessions, cache, and queue configuration:

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

### GPHA Central Login

Configure the values issued for the EMS application:

```env
GPHA_SSO_ISSUER=GPHACentralLogin
GPHA_SSO_AUDIENCE=EMS
GPHA_SSO_ALLOWED_AUDIENCES=EMS,CentralLogin
GPHA_SSO_MODULE_ID=replace-with-module-id
GPHA_SSO_APP_ID=EMS
GPHA_SSO_APP_KEY=replace-with-app-key
GPHA_SSO_SHARED_SECRET=replace-with-shared-secret
GPHA_SSO_CENTRAL_LOGIN_URL=https://central-login.example.org/auth/login
GPHA_SSO_RETURN_URL=https://ems.example.org/sso/login
GPHA_SSO_BASE_URL=https://central-login-api.example.org
GPHA_SSO_APP_ACCESS_PERMISSIONS_ENDPOINT=https://central-login-api.example.org/api/app-access/user-permissions
```

`GPHA_SSO_RETURN_URL` must point to the deployed EMS `/sso/login` route and must match the return URL registered in Central Login.

## Operational modules

### Ambulance fleet

The fleet module stores:

- Fleet and registration numbers.
- Make, model, and year.
- Base and current location.
- Availability status.
- Current odometer.
- Roadworthy and insurance expiry dates.

The fleet list is paginated at 15 records per page.

### Dispatch and movements

Movements can be filtered by:

- Search text.
- Ambulance.
- Status.
- Date range.
- Priority.
- Case category.
- Origin.
- Destination.

Movement records do not require distance or odometer values. Distance analytics are handled through the mileage module instead.

### Mileage readings

Mileage readings support scheduled weekly readings and service readings. The list can be filtered by ambulance, reading type, and date range.

For each filtered ambulance, total movement is calculated as:

```text
Last odometer reading − First odometer reading
```

Example:

```text
First reading:  60,182 km
Last reading:   60,430 km
Movement:          248 km
```

At least two readings are required to calculate movement. The summary clearly identifies ambulances that have only one reading.

### Radio and availability checks

Availability records are stored and displayed as complete check sessions rather than isolated unit rows.

Session-level filters include:

- Date range.
- Morning or afternoon session.
- All units responded.
- Has no response.

### Weekly activities

Activities can record:

- Category.
- Rich-text activity description.
- Outcome or decision.
- Follow-up requirement.
- Follow-up action and owner.
- Due date.

The activity list can be filtered by search text, category, follow-up status, and date range.

## Reports

The Reports page contains two related features:

1. A filterable operational dashboard.
2. A printable report generator.

Both use the same easy reporting-period choices.

### Available report types

Every reporting period can be used with all three report types:

- Ambulance Mileage Report.
- Operational Activities Report.
- Radio & Availability Report.

Generated reports store a frozen snapshot of the underlying records, summary findings, and recommendations. Later changes to operational records do not silently rewrite an already generated report.

### Reporting-period rules

The default selected reporting period is **This Week**.

“This” periods start at the beginning of the relevant calendar period and end today. “Last” periods use the most recently completed calendar period. EMS operates seven days a week, so reporting weeks run from Sunday through Saturday and always include the weekend.

The following examples assume today is **Wednesday, 19 August 2026**.

| Selection | Start date | End date | Meaning |
|---|---:|---:|---|
| Today | 19 Aug 2026 | 19 Aug 2026 | Records captured today only. |
| Yesterday | 18 Aug 2026 | 18 Aug 2026 | Records captured on the previous day. |
| This Week | 16 Aug 2026 | 19 Aug 2026 | Sunday of the current EMS week through today. |
| Last Week | 9 Aug 2026 | 15 Aug 2026 | The previous completed Sunday–Saturday week. |
| This Month | 1 Aug 2026 | 19 Aug 2026 | First day of the current month through today. |
| Last Month | 1 Jul 2026 | 31 Jul 2026 | The previous completed calendar month. |
| This Quarter | 1 Jul 2026 | 19 Aug 2026 | Start of the current calendar quarter through today. |
| Last Quarter | 1 Apr 2026 | 30 Jun 2026 | The previous completed calendar quarter. |
| Last 6 Months | 1 Feb 2026 | 31 Jul 2026 | The six completed calendar months before the current month. |
| This Year | 1 Jan 2026 | 19 Aug 2026 | First day of the current year through today. |
| Last Year | 1 Jan 2025 | 31 Dec 2025 | The previous completed calendar year. |
| Custom Dates | User selected | User selected | Any valid inclusive From/To date range. |

### Additional period examples

If today is **Monday, 4 January 2027**:

- This Week is Sunday, 3 January 2027 to Monday, 4 January 2027.
- Last Week is Sunday, 27 December 2026 to Saturday, 2 January 2027.
- Last Month is 1 December 2026 to 31 December 2026.
- Last Quarter is 1 October 2026 to 31 December 2026.
- Last 6 Months is 1 July 2026 to 31 December 2026.
- Last Year is 1 January 2026 to 31 December 2026.

These rules work correctly across month, quarter, and year boundaries.

### Reports dashboard filtering

The selected reporting period filters the entire dashboard:

- Total movements.
- Completed and active movements.
- Critical movements.
- Ambulances used.
- Completion rate.
- Movement trend.
- Status distribution.
- Availability checks and response rate.
- Recorded operational activities.

Ambulance and movement-status filters additionally narrow movement-related metrics. Availability and activity metrics are filtered by the selected reporting period because they are not tied to a single ambulance movement.

The blue period banner displays the active label and exact From/To dates.

### Operational CSV export

The operational CSV export uses the same period, ambulance, and status filters as the dashboard.

It contains movement reference, date, ambulance, registration, route, category, priority, and status. It does not include crew-lead or movement-distance columns because those values are not recorded in the movement workflow.

## Branch and permissions behavior

Operational records use the active Central Login branch context. Users only see records available within that scope.

The main Central Login permission components are:

- `AmbulanceFleet`
- `DispatchAndMovement`
- `ReadinessAndActivities`
- `EMSReports`
- `EMSActivityAndAudit`

Permissions such as `View`, `Manage`, `Export`, and `Approve` are checked by the relevant routes and actions.

## Pagination and mobile behavior

The following lists use 15 records per page:

- Ambulance fleet.
- Dispatch and movement logs.
- Mileage readings.
- Availability sessions.
- Weekly activities.
- Ambulance movement history.
- Audit history.

Filters remain in the query string while moving between pages.

On mobile devices, filter panels are collapsed behind a Show Filters button. The main navigation uses a hamburger icon that changes to a close icon while open.

## Testing

Run the complete test suite:

```bash
php artisan test
```

Run a specific test:

```bash
php artisan test --filter=test_name
```

Build the production frontend:

```bash
npm run build
```

Before deployment, both commands should complete successfully.

## Production deployment

The server document root must point to the Laravel `public` directory, not the project root.

Example first deployment:

```bash
cd /var/www/EmergencyUnit
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Set writable directory permissions. Replace `www-data` if the server uses another web-server account:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

For SQLite, the web server must also be able to write to the database file and its directory:

```bash
sudo chown -R www-data:www-data database
sudo chmod -R 775 database
```

For subsequent deployments:

```bash
cd /var/www/EmergencyUnit
php artisan down
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan up
```

Do not replace the production `.env` or regenerate `APP_KEY` during routine updates. Back up the production database before running migrations.

## Useful maintenance commands

```bash
php artisan about
php artisan route:list
php artisan migrate:status
php artisan optimize
php artisan optimize:clear
php artisan config:clear
php artisan view:clear
```

## Security notes

- Keep `.env`, SSO secrets, application keys, and database credentials outside source control.
- Use HTTPS in production.
- Set `APP_DEBUG=false` in production.
- Back up the database before deployments and destructive maintenance.
- Do not expose the application root, database files, storage files, or logs through the web server.
