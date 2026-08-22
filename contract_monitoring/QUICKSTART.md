# 🚀 Quick Start Guide

Get the PFDA Contract Monitoring System running in 5 minutes!

## Prerequisites

- Node.js 18+ installed
- Your separate backend API running

## Step 1: Install Dependencies (1 minute)

```bash
npm install
```

## Step 2: Configure Environment (30 seconds)

Create `.env.local` file in the root directory:

```env
NEXT_PUBLIC_API_URL=http://localhost:8000/api
NEXT_PUBLIC_APP_NAME=PFDA Contract Monitoring System
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

**Change `http://localhost:8000` to your actual backend URL!**

## Step 3: Start Development Server (30 seconds)

```bash
npm run dev
```

## Step 4: Open in Browser

Navigate to: **http://localhost:3000**

You should see the login page!

## Step 5: Login

Use the credentials from your backend:

```
Email: admin@pfda-bulan.gov.ph
Password: (your backend admin password)
```

## ✅ That's It!

You should now see the dashboard with all features working!

## 🐛 Troubleshooting

**Can't login?**
- Make sure your backend is running
- Check the backend URL in `.env.local`
- Verify CORS is enabled on backend

**Nothing loads?**
- Check browser console for errors
- Verify API endpoints are working
- Make sure backend returns correct data format

**Styles look broken?**
- Clear browser cache
- Restart dev server
- Run `npm install` again

## 📚 Need More Help?

- See [SETUP_GUIDE.md](./SETUP_GUIDE.md) for detailed setup
- See [API_SPECIFICATION.md](./API_SPECIFICATION.md) for backend requirements
- See [README.md](./README.md) for full documentation

## 🎯 What to Build Next (Backend)

Your backend needs to implement these endpoints:

1. **Authentication:** `/auth/login`, `/auth/me`
2. **Dashboard Stats:** `/reports/dashboard-stats`
3. **Contracts:** `/contracts` (GET, POST, PUT, DELETE)
4. **Payments:** `/payments` (GET, POST, PUT)
5. **And more...** (see API_SPECIFICATION.md)

## 🔧 Useful Commands

```bash
# Start development server
npm run dev

# Build for production
npm run build

# Start production server
npm start

# Check for errors
npm run lint
```

## 📱 Access Points

- **Frontend:** http://localhost:3000
- **Backend:** http://localhost:8000 (your separate server)
- **Database:** http://localhost/phpmyadmin (XAMPP)

---

**Happy Coding! 🎉**