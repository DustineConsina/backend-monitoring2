# PFDA Contract Monitoring System - Setup Guide

Complete setup guide for the PFDA Contract Monitoring System (Frontend Only).

## 📋 Prerequisites

Before you begin, ensure you have the following installed:

- **Node.js** 18.0 or higher ([Download](https://nodejs.org/))
- **npm** (comes with Node.js) or **yarn**
- **XAMPP** (for your separate backend MySQL database)
- **Git** (optional, for version control)
- **Code Editor** (VS Code recommended)

## 🚀 Frontend Setup (Current Project)

### Step 1: Install Dependencies

```bash
cd contract_monitoring
npm install
```

This will install all required packages:
- Next.js 16
- React 19
- TypeScript
- Tailwind CSS 4
- And other dependencies

### Step 2: Configure Environment Variables

Create a `.env.local` file in the root directory:

```bash
# On Windows
copy .env.local.example .env.local

# On Mac/Linux
cp .env.local.example .env.local
```

Edit `.env.local`:

```env
# Backend API URL (update with your backend URL)
NEXT_PUBLIC_API_URL=http://localhost:8000/api

# Application Info
NEXT_PUBLIC_APP_NAME=PFDA Contract Monitoring System
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

### Step 3: Start Development Server

```bash
npm run dev
```

The application will be available at: **http://localhost:3000**

### Step 4: Test the Application

1. Open browser to `http://localhost:3000`
2. You'll be redirected to `/login`
3. **Important:** The login will not work until you set up the backend!

## 🔧 Backend Setup Requirements

Your separate backend needs to implement the API endpoints as specified in `API_SPECIFICATION.md`.

### Backend Checklist:

- [ ] Implement all authentication endpoints (`/auth/*`)
- [ ] Implement contract management endpoints (`/contracts/*`)
- [ ] Implement payment endpoints (`/payments/*`)
- [ ] Implement rental space endpoints (`/rental-spaces/*`)
- [ ] Implement message endpoints (`/messages/*`)
- [ ] Implement notification endpoints (`/notifications/*`)
- [ ] Implement report endpoints (`/reports/*`)
- [ ] Implement tenant endpoints (`/tenants/*`)
- [ ] Implement audit log endpoints (`/audit-logs/*`)
- [ ] Set up MySQL database
- [ ] Configure CORS to allow frontend origin
- [ ] Implement JWT authentication
- [ ] Set up email service for notifications
- [ ] Implement QR code generation

### Recommended Backend Stack:

**Option 1: Node.js/Express**
```
- Express.js (server framework)
- Prisma ORM (database ORM)
- MySQL (database)
- Nodemailer (email notifications)
- jsonwebtoken (JWT authentication)
- qrcode (QR generation)
- bcryptjs (password hashing)
```

**Option 2: PHP/Laravel**
```
- Laravel 10+ (framework)
- MySQL (database)
- Laravel Sanctum (authentication)
- Laravel Mail (notifications)
- SimpleSoftwareIO/simple-qrcode (QR generation)
```

**Option 3: Python/FastAPI**
```
- FastAPI (framework)
- SQLAlchemy (ORM)
- MySQL (database)
- python-jose (JWT)
- qrcode (QR generation)
- python-multipart (file uploads)
```

## 🗄️ Database Setup (XAMPP MySQL)

### Step 1: Start XAMPP

1. Open XAMPP Control Panel
2. Start **Apache** and **MySQL** services

### Step 2: Create Database

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Click "New" to create a database
3. Name it: `contract_monitoring`
4. Set Collation: `utf8mb4_unicode_ci`

### Step 3: Database Schema

Use the Prisma schema provided in `prisma/schema.prisma` as reference for your backend database design. It includes:

**Tables:**
- `users` - User accounts (Admin, Staff, Tenant)
- `rental_space_types` - Space type definitions
- `rental_spaces` - Individual rental spaces
- `contracts` - Rental contracts
- `payments` - Payment records
- `notifications` - Email notifications
- `messages` - Chat messages
- `audit_logs` - Activity tracking

### Step 4: Seed Initial Data

Your backend should seed the database with:

**Admin User:**
- Email: `admin@pfda-bulan.gov.ph`
- Password: `admin123` (hashed)
- Role: ADMIN

**Rental Space Types:**
1. Food Stall (10 spaces, ₱150/m²)
2. Market Hall Bay (39 spaces, ₱200/m²)
3. Bañera Warehouse Bay (12 spaces, ₱180/m²)

**Rental Spaces:**
- Create all 61 rental spaces (10 + 39 + 12)
- Set appropriate square meters for each
- Set status to AVAILABLE

## 🔌 Connecting Frontend to Backend

### Step 1: Update API URL

In your `.env.local` file:

```env
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

Replace `8000` with your backend port.

### Step 2: Test Connection

1. Start your backend server
2. Start frontend: `npm run dev`
3. Open browser to `http://localhost:3000`
4. Try to login with admin credentials

## ✅ Verification Checklist

### Frontend Verification:

- [ ] Next.js dev server runs without errors
- [ ] Homepage redirects to `/login`
- [ ] Login page displays correctly
- [ ] Tailwind CSS styles are applied
- [ ] No console errors in browser

### Backend Verification:

- [ ] Backend server is running
- [ ] MySQL database is connected
- [ ] API endpoints respond correctly
- [ ] CORS is configured properly
- [ ] JWT tokens are generated on login
- [ ] Database is seeded with initial data

### Full System Test:

1. **Login Test:**
   - Navigate to login page
   - Enter admin credentials
   - Should redirect to dashboard

2. **Dashboard Test:**
   - View dashboard statistics
   - Check if data loads without errors

3. **Navigation Test:**
   - Click through all sidebar menu items
   - Verify pages load correctly

4. **Contract Test:**
   - View contracts list
   - Create a new contract (if backend ready)

5. **QR Code Test:**
   - View contract details
   - Generate QR code
   - Verify QR displays correctly

## 🐛 Troubleshooting

### Frontend Issues:

**Issue: "Failed to fetch" errors**
- Solution: Check if backend is running
- Verify API URL in `.env.local`
- Check browser console for CORS errors

**Issue: Login doesn't work**
- Solution: Verify backend authentication endpoint
- Check network tab in browser dev tools
- Verify credentials in database

**Issue: Pages show "Loading..." forever**
- Solution: Check API client for errors
- Verify backend endpoints return correct data
- Check browser console for errors

**Issue: Styles don't load**
- Solution: Run `npm install` again
- Clear Next.js cache: `rm -rf .next`
- Restart dev server

### Backend Issues:

**Issue: Cannot connect to MySQL**
- Solution: Ensure XAMPP MySQL is running
- Check database name in configuration
- Verify database user permissions

**Issue: CORS errors**
- Solution: Add frontend URL to CORS whitelist
- Enable credentials in CORS config
- Check preflight OPTIONS requests

**Issue: JWT authentication fails**
- Solution: Verify JWT secret is configured
- Check token expiration settings
- Ensure Authorization header format is correct

## 📦 Production Deployment

### Frontend Deployment:

1. **Build the application:**
   ```bash
   npm run build
   ```

2. **Test production build:**
   ```bash
   npm start
   ```

3. **Deploy to hosting:**
   - **Vercel** (recommended for Next.js)
   - **Netlify**
   - **AWS Amplify**
   - **DigitalOcean**
   - **Your own server**

### Environment Variables for Production:

```env
NEXT_PUBLIC_API_URL=https://api.yourdomain.com/api
NEXT_PUBLIC_APP_URL=https://yourdomain.com
```

## 🔒 Security Recommendations

1. **Environment Variables:**
   - Never commit `.env.local` to git
   - Use different credentials for production
   - Rotate JWT secrets regularly

2. **Authentication:**
   - Implement password strength requirements
   - Add rate limiting for login attempts
   - Use HTTPS in production

3. **Data Protection:**
   - Sanitize all user inputs
   - Implement CSRF protection
   - Use prepared statements (backend)

## 📚 Additional Resources

- [Next.js Documentation](https://nextjs.org/docs)
- [TypeScript Documentation](https://www.typescriptlang.org/docs/)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [API Specification](./API_SPECIFICATION.md)
- [README](./README.md)

## 💡 Tips for Development

1. **Hot Reload:**
   - Next.js automatically reloads on file changes
   - If changes don't appear, try restarting dev server

2. **TypeScript:**
   - Run `npm run build` to check for type errors
   - Use VS Code for better TypeScript support

3. **Debugging:**
   - Use browser DevTools Network tab for API calls
   - Check Console for JavaScript errors
   - Use React DevTools for component debugging

4. **Code Organization:**
   - Keep components small and focused
   - Create reusable utility functions in `/lib`
   - Use TypeScript types from `/types`

## 🆘 Getting Help

If you encounter issues:

1. Check the error message in browser console
2. Verify backend is running and responding
3. Check API endpoint URLs and responses
4. Review this setup guide steps
5. Refer to API_SPECIFICATION.md for endpoint details

## ✨ Next Steps

Once everything is working:

1. **Customize the UI** - Update colors, logos, styling
2. **Add Features** - Implement additional functionality
3. **Test Thoroughly** - Test all features with real data
4. **Optimize Performance** - Analyze and improve load times
5. **Document Changes** - Keep documentation updated
6. **Train Users** - Provide user guides for PFDA staff

---

**Good luck with your PFDA Contract Monitoring System! 🎉**