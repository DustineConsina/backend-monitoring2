# PFDA Contract Monitoring System

## Web-Based Contract Monitoring System with QR Code Support
**Philippine Fisheries Development Authority - Bulan, Sorsogon**

---

## 📋 Overview

A comprehensive web-based application designed to streamline the management of rental contracts for the Philippine Fisheries Development Authority Fish Port in Bulan, Sorsogon. The system manages 61 rental spaces:

- 10 Food Stalls
- 39 Market Hall Bays  
- 12 Bañera Warehouse Bays

This replaces manual paper-based processes with a centralized digital solution featuring automated notifications, QR codes, payment tracking, and comprehensive reporting.

---

## ✨ Key Features

- ✅ **Contract Management** - Digital storage, tracking, and lifecycle management
- 💰 **Payment Tracking** - Automated schedules, interest calculation, multiple payment methods
- 📱 **QR Code System** - Unique codes per tenant with contract and space details
- 📧 **Email Notifications** - Automated reminders for payments and contract expiry
- 💬 **Chat System** - Real-time communication between tenants and staff
- 📊 **Comprehensive Reports** - Contracts, payments, revenue, delinquency, and more
- 🔍 **Audit Trail** - Complete tracking of all system activities
- 📈 **Dashboard Analytics** - Real-time statistics and visualizations
- 🔐 **Role-Based Access** - Admin, Staff, and Tenant roles with specific permissions
- 🔎 **Advanced Search** - Filter and search across all modules

---

## 🚀 Quick Start

### 1. Install Dependencies
```bash
composer install
```

### 2. Configure Environment
```bash
copy .env.example .env
php artisan key:generate
```

Edit `.env` with your database and mail settings.

### 3. Setup Database
```bash
# Create database: pfda_contract_db
php artisan migrate
php artisan db:seed
php artisan storage:link
```

### 4. Run the Application
```bash
php artisan serve
```

Visit: `http://localhost:8000`

---

## 🔐 Default Login Credentials

**Admin:**
- Email: `admin@pfda.gov.ph`
- Password: `password123`

**Staff:**
- Email: `staff@pfda.gov.ph`  
- Password: `password123`

⚠️ **Change these passwords immediately after first login!**

---

## 📡 API Endpoints

### Authentication
- `POST /api/register` - Register new user
- `POST /api/login` - User login
- `POST /api/logout` - User logout
- `GET /api/me` - Get authenticated user

### Resources
- `/api/tenants` - Tenant management
- `/api/contracts` - Contract management
- `/api/payments` - Payment tracking
- `/api/rental-spaces` - Space management
- `/api/chat` - Chat system
- `/api/notifications` - Notification system
- `/api/reports` - Generate reports

**Full API documentation:** See [DOCUMENTATION.md](DOCUMENTATION.md)

---

## ⏰ Automated Tasks

The system includes scheduled tasks that run automatically:

- **Payment Reminders** - Sent 7 days before due date
- **Overdue Notifications** - Weekly reminders for overdue payments
- **Contract Expiry Alerts** - Notifications at 30, 14, 7, 3, 1 days before expiry
- **Interest Calculation** - Daily calculation of overdue payment interest
- **Contract Status Updates** - Automatic marking of expired contracts

Setup scheduler:
```bash
# Linux/Production
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1

# Windows/Development
powershell -ExecutionPolicy Bypass -File .\scripts\install-laravel-scheduler.ps1
```

The Windows scheduled task runs as the current user while that user is logged in.

### Vercel, Render, and Aiven deployment

The frontend is deployed to Vercel and the Laravel API to Render using the
included `render.yaml` and `Dockerfile`. Create a MySQL service on Aiven and
configure the Render service with its host, port, database, username, and
password. Upload Aiven's CA certificate as a Render secret file, then set
`MYSQL_ATTR_SSL_CA` to its mounted path (for example, `/etc/secrets/aiven-ca.pem`).

Set `APP_KEY` to a newly generated Laravel key. The blueprint sets `APP_URL` to
`https://backend-monitoring2.onrender.com` and `FRONTEND_URL` to
`https://frontend-monitoring2.vercel.app`. The frontend uses
`https://backend-monitoring2.onrender.com/api` as its production API URL; set
`NEXT_PUBLIC_API_URL` in Vercel only if you change the backend hostname.
Configure the Render `CLOUDINARY_URL` variable for media storage. If email
delivery is enabled, also configure the SMTP values declared in `render.yaml`.
The Render entrypoint validates required settings, runs migrations, and starts
Apache; it does not seed or overwrite production data.

Before switching existing data to Render, run
`php artisan assets:migrate-local-to-cloudinary --dry-run` on the current backend
with its database and local uploaded files available. Review the output, then run
`php artisan assets:migrate-local-to-cloudinary` from that backend configured to
use the Aiven database and Cloudinary credentials. The command keeps local
originals and updates database paths only after each successful Cloudinary
upload.

### Cloudinary uploads

Rental-space photos, tenant profile pictures, generated tenant QR codes, and
uploaded contract documents use Cloudinary. Configure either `CLOUDINARY_URL`
or `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, and `CLOUDINARY_API_SECRET`.
Older local image and document paths continue to resolve when their files remain
available on the original backend.

---

## 📚 Documentation

Detailed documentation is available in [DOCUMENTATION.md](DOCUMENTATION.md), including:

- Complete installation guide
- System requirements
- Configuration options
- API reference
- Troubleshooting guide
- User roles and permissions

---

## 🛠️ Technology Stack

- **Framework:** Laravel 11.x
- **Authentication:** Laravel Sanctum
- **Database:** MySQL 8.0+
- **QR Codes:** endroid/qr-code
- **PDF Generation:** barryvdh/laravel-dompdf
- **Testing:** Pest PHP

---

## 📦 Project Structure

```
backend_contract/
├── app/
│   ├── Console/Commands/      # Scheduled task commands
│   ├── Http/Controllers/      # API controllers
│   ├── Mail/                  # Email templates
│   └── Models/                # Database models
├── database/
│   ├── migrations/            # Database migrations
│   └── seeders/               # Data seeders
├── resources/
│   └── views/emails/          # Email views
├── routes/
│   ├── api.php               # API routes
│   └── console.php           # Scheduled tasks
└── storage/
    ├── app/public/           # Uploaded files
    └── logs/                 # Application logs
```

---

## 🔧 System Requirements

- PHP 8.2+
- Composer 2.x
- MySQL 8.0+ / MariaDB 10.3+
- Node.js 18+ (optional, for frontend)

**Required PHP Extensions:**
- OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, GD

---

## 🤝 Contributing

This is a proprietary system developed for PFDA Bulan, Sorsogon. For issues or suggestions, contact the development team.

---

## 📄 License

Proprietary - Philippine Fisheries Development Authority (PFDA), Bulan, Sorsogon

---

## 📞 Support

For technical support or questions, contact PFDA-IT Department.

---

## 🎯 Version

**Version 1.0.0** - Initial Release  
© 2024 Philippine Fisheries Development Authority

---

**Built with ❤️ for PFDA Bulan, Sorsogon**


We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
