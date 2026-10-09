# PFDA Contract Monitoring System

## Web-Based Contract Monitoring System with QR Code Support for Philippine Fisheries Development Authority Rental Spaces in Bulan, Sorsogon

---

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation Guide](#installation-guide)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Running the Application](#running-the-application)
- [API Documentation](#api-documentation)
- [Scheduled Tasks](#scheduled-tasks)
- [User Roles](#user-roles)
- [Default Credentials](#default-credentials)
- [Troubleshooting](#troubleshooting)

---

## 🎯 Overview

The PFDA Contract Monitoring System is a comprehensive web-based application designed to streamline the management of rental contracts for the Philippine Fisheries Development Authority Fish Port in Bulan, Sorsogon. The system manages 61 rental spaces across three categories:

- **10 Food Stalls**
- **39 Market Hall Bays**
- **12 Bañera Warehouse Bays**

The system replaces manual paper-based processes with a centralized digital solution that includes automated notifications, QR code generation, payment tracking, and comprehensive reporting.

---

## ✨ Features

### 1. **Contract Management**
- Digital storage of all contract records
- Create, view, update, and track contracts
- Activate, terminate, and renew contracts
- Automatic contract expiration monitoring
- Contract status tracking (Active, Expired, Terminated, Pending)
- Upload and store digital contract documents

### 2. **Payment Management**
- Automated monthly payment schedule generation
- Real-time payment tracking
- Automatic late payment interest calculation (configurable per contract)
- Payment recording with multiple payment methods (Cash, Check, Bank Transfer)
- Payment history and balance tracking
- Overdue payment monitoring

### 3. **QR Code System**
- Unique QR code generation for each tenant
- QR codes display:
  - Tenant information
  - Active contract details
  - Rental space information and map
  - Space size in square meters
- Quick tenant verification via QR code scanning

### 4. **Automated Email Notifications**
- Payment due reminders (7 days before due date)
- Overdue payment alerts (weekly reminders)
- Contract expiration notifications (30, 14, 7, 3, and 1 day before expiry)
- Payment confirmation receipts
- Contract activation/termination notifications

### 5. **Real-time Communication**
- Built-in chat system between tenants and PFDA staff
- Message read status tracking
- Unread message notifications
- Conversation history

### 6. **Comprehensive Audit Trail**
- Track all user actions (create, view, update, delete)
- Record IP addresses and timestamps
- Store old and new values for updates
- User activity monitoring
- Complete audit log reporting

### 7. **Advanced Reporting**
- **Contracts Report**: Active, expired, and terminated contracts
- **Payments Report**: Paid, pending, and overdue payments
- **Delinquency Report**: Tenants with outstanding balances
- **Revenue Report**: Monthly and yearly revenue analysis
- **Tenants Report**: Complete tenant information and status
- **Expiring Contracts Report**: Contracts nearing expiration
- **Audit Log Report**: System activity tracking
- Export reports to PDF format

### 8. **Search and Filter**
- Advanced search functionality across all modules
- Filter by status, date range, tenant, space type
- Sorting capabilities
- Pagination for large datasets

### 9. **Dashboard Analytics**
- Real-time statistics and metrics
- Revenue tracking (monthly and yearly)
- Space utilization rates
- Payment status overview
- Contract status distribution
- Delinquent tenant monitoring
- Visual charts and graphs

### 10. **Tenant Portal**
- View active contracts
- Check payment schedules
- View payment history
- Receive notifications
- Communicate with PFDA staff via chat
- Access QR code

---

## 💻 System Requirements

### Server Requirements
- PHP 8.2 or higher
- Composer 2.x
- MySQL 8.0 or higher / MariaDB 10.3+
- Node.js 18+ and NPM (for frontend assets)
- Web server (Apache/Nginx)

### PHP Extensions Required
- OpenSSL PHP Extension
- PDO PHP Extension
- Mbstring PHP Extension
- Tokenizer PHP Extension
- XML PHP Extension
- Ctype PHP Extension
- JSON PHP Extension
- BCMath PHP Extension
- Fileinfo PHP Extension
- GD PHP Extension (for QR codes)

---

## 📦 Installation Guide

### Step 1: Clone or Extract the Project

```bash
cd C:\xampp\htdocs
# If you have the files already, skip this step
```

### Step 2: Install PHP Dependencies

```bash
cd backend_contract
composer install
```

Required Composer packages:
```bash
composer require simplesoftwareio/simple-qrcode
composer require barryvdh/laravel-dompdf
```

### Step 3: Install Node Dependencies (Optional for frontend)

```bash
npm install
npm run build
```

### Step 4: Generate Application Key

```bash
php artisan key:generate
```

---

## ⚙️ Configuration

### Step 1: Create Environment File

Copy `.env.example` to `.env`:

```bash
copy .env.example .env
```

### Step 2: Configure Database

Edit `.env` file and update database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pfda_contract_db
DB_USERNAME=root
DB_PASSWORD=
```

### Step 3: Configure Mail Settings

For email notifications, configure your mail settings in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="PFDA Contract System"
```

**Note:** For Gmail, you need to use an [App Password](https://support.google.com/accounts/answer/185833).

### Step 4: Configure Application URL

```env
APP_URL=http://localhost:8000
```

### Step 5: Configure Storage

```bash
php artisan storage:link
```

This creates a symbolic link from `public/storage` to `storage/app/public`.

---

## 🗄️ Database Setup

### Step 1: Create Database

Create a new MySQL database named `pfda_contract_db` (or your preferred name):

```sql
CREATE DATABASE pfda_contract_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Step 2: Run Migrations

```bash
php artisan migrate
```

This will create all necessary tables:
- users
- tenants
- rental_spaces
- contracts
- payments
- chat_messages
- audit_logs
- notifications
- cache, jobs, sessions tables

### Step 3: Seed Initial Data

```bash
php artisan db:seed
```

This will create:
- Admin user (Joannaruby@pfda.gov.ph / password123 for local development only)
- Staff user (staff@pfda.gov.ph / password123)
- 10 Food Stalls
- 39 Market Hall Bays
- 12 Bañera Warehouse Bays

---

## 🚀 Running the Application

### Development Server

Start the Laravel development server:

```bash
php artisan serve
```

The application will be available at: `http://localhost:8000` or `http://127.0.0.1:8000`

### Production Deployment

For production, configure your web server (Apache/Nginx) to point to the `public` directory.

**Apache .htaccess** (already included in public folder)

**Nginx Configuration Example:**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/backend_contract/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 📡 API Documentation

### Base URL
```
http://localhost:8000/api
```

### Authentication
The API uses Laravel Sanctum for authentication. After login, include the token in the Authorization header:

```
Authorization: Bearer YOUR_TOKEN_HERE
```

### Public Endpoints

#### Register
```http
POST /api/register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "tenant",
  "phone": "09123456789",
  "address": "Bulan, Sorsogon"
}
```

#### Login
```http
POST /api/login
Content-Type: application/json

{
  "email": "Joannaruby@pfda.gov.ph",
  "password": "password123"
}

Response:
{
  "success": true,
  "message": "Login successful",
  "user": {...},
  "token": "your-auth-token"
}
```

### Protected Endpoints (Require Authentication)

#### Dashboard
```http
GET /api/dashboard
Authorization: Bearer {token}
```

#### Tenants
```http
GET /api/tenants                    # List all tenants
GET /api/tenants/{id}               # Get tenant details
POST /api/tenants                   # Create tenant
PUT /api/tenants/{id}               # Update tenant
DELETE /api/tenants/{id}            # Delete tenant
GET /api/tenants/{id}/qr-code       # Get tenant QR code
POST /api/qr-scan                   # Scan QR code
```

#### Contracts
```http
GET /api/contracts                  # List all contracts
GET /api/contracts/{id}             # Get contract details
POST /api/contracts                 # Create contract
PUT /api/contracts/{id}             # Update contract
DELETE /api/contracts/{id}          # Delete contract
POST /api/contracts/{id}/activate   # Activate contract
POST /api/contracts/{id}/terminate  # Terminate contract
POST /api/contracts/{id}/renew      # Renew contract
```

#### Payments
```http
GET /api/payments                        # List all payments
GET /api/payments/{id}                   # Get payment details
POST /api/payments/{id}/record           # Record payment
GET /api/tenants/{id}/payment-summary    # Get payment summary
GET /api/tenants/{id}/payment-history    # Get payment history
```

#### Rental Spaces
```http
GET /api/rental-spaces                   # List all spaces
GET /api/rental-spaces/{id}              # Get space details
POST /api/rental-spaces                  # Create space
PUT /api/rental-spaces/{id}              # Update space
DELETE /api/rental-spaces/{id}           # Delete space
GET /api/rental-spaces-available         # Get available spaces
GET /api/rental-spaces-statistics        # Get statistics
```

#### Chat
```http
GET /api/chat/conversations              # Get conversations
GET /api/chat/messages/{userId}          # Get messages with user
POST /api/chat/send                      # Send message
GET /api/chat/unread-count               # Get unread count
```

#### Notifications
```http
GET /api/notifications                   # Get all notifications
GET /api/notifications/unread            # Get unread notifications
GET /api/notifications/unread-count      # Get unread count
PATCH /api/notifications/{id}/read       # Mark as read
POST /api/notifications/mark-all-read    # Mark all as read
```

#### Reports (Admin/Staff only)
```http
GET /api/reports/contracts?format=pdf
GET /api/reports/payments?format=pdf
GET /api/reports/delinquency?format=pdf
GET /api/reports/revenue?year=2024
GET /api/reports/tenants
GET /api/reports/expiring-contracts?days=30
GET /api/reports/audit-log
```

---

## ⏰ Scheduled Tasks

The system uses Laravel's task scheduler for automated operations. These commands run automatically:

### Daily Tasks

1. **Send Payment Reminders**
   ```bash
   php artisan payments:send-reminders
   ```
   - Sends reminders for payments due in the next 7 days
   - Sends overdue payment notifications (weekly)

2. **Send Contract Expiry Notifications**
   ```bash
   php artisan contracts:send-expiry-notifications
   ```
   - Notifies tenants of expiring contracts (30, 14, 7, 3, 1 day before)

3. **Calculate Overdue Interest**
   ```bash
   php artisan payments:calculate-overdue-interest
   ```
   - Applies interest to overdue payments based on contract rate

4. **Update Expired Contracts**
   ```bash
   php artisan contracts:update-expired
   ```
   - Automatically marks contracts as expired after end date

### Setting Up Cron (Linux/Production)

Add to crontab:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Windows Task Scheduler (Development)

For XAMPP on Windows, you can manually run:
```bash
php artisan schedule:work
```

Or set up Windows Task Scheduler to run the scheduler every minute.

---

## 👥 User Roles

### Admin
- Full system access
- Manage all tenants, contracts, and payments
- Generate reports
- View audit logs
- Manage rental spaces
- System configuration

### Staff
- Manage tenants and contracts
- Record payments
- View reports
- Communicate with tenants
- Monitor contracts and payments

### Tenant
- View own contracts
- Check payment schedules
- View payment history
- Communicate with PFDA staff
- Access QR code
- Receive notifications

---

## 🔐 Default Credentials

### Admin Account
- **Name:** Joanna Ruby Layosa
- **Email:** Joannaruby@pfda.gov.ph
- **Password:** password123
- **Role:** Admin

### Staff Account
- **Email:** staff@pfda.gov.ph
- **Password:** password123
- **Role:** Staff

**⚠️ IMPORTANT:** Change these passwords immediately after first login in production!

---

## 🔧 Troubleshooting

### Common Issues

#### 1. "Class not found" errors
```bash
composer dump-autoload
```

#### 2. Storage permission errors
```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

#### 3. QR Code generation fails
Ensure GD extension is enabled in php.ini:
```ini
extension=gd
```

#### 4. Email notifications not sending
- Check mail configuration in `.env`
- Verify mail server credentials
- Check Laravel logs: `storage/logs/laravel.log`

#### 5. Scheduled tasks not running
- Ensure cron is set up correctly
- Manually test: `php artisan schedule:run`

#### 6. Database migration errors
```bash
php artisan migrate:fresh --seed
```
**⚠️ WARNING:** This will delete all data!

### Logs

Check application logs:
```bash
tail -f storage/logs/laravel.log
```

---

## 📚 Additional Resources

### Laravel Documentation
- https://laravel.com/docs/11.x

### API Testing
Use Postman, Insomnia, or Thunder Client (VS Code) to test the API endpoints.

### Support
For issues or questions, contact PFDA-IT Department.

---

## 📄 License

This system is proprietary software developed for the Philippine Fisheries Development Authority (PFDA), Bulan, Sorsogon.

---

## 👨‍💻 Development Team

Developed for PFDA Bulan, Sorsogon
© 2024 Philippine Fisheries Development Authority

---

## 🎉 Getting Started Checklist

- [ ] Install PHP, Composer, MySQL
- [ ] Clone/extract project files
- [ ] Run `composer install`
- [ ] Copy `.env.example` to `.env`
- [ ] Configure database in `.env`
- [ ] Run `php artisan key:generate`
- [ ] Create database
- [ ] Run `php artisan migrate`
- [ ] Run `php artisan db:seed`
- [ ] Run `php artisan storage:link`
- [ ] Start server: `php artisan serve`
- [ ] Login with default admin credentials
- [ ] Change default passwords
- [ ] Configure email settings
- [ ] Set up scheduled tasks
- [ ] Test all features

---

**System is now ready to use! 🚀**
