# Student Management System

## Run locally

Use two terminals from the project folder.

**Terminal 1 — API server**

```powershell
cd backend
npm install
npm start
```

The API listens on `http://localhost:5000`.

**Terminal 2 — web app**

```powershell
cd frontend
npm install
npm run dev
```

Open the `Local` URL Vite prints (usually `http://localhost:5173`). If that port is busy, use the alternate URL Vite prints.

## Demo sign in

- Email: `admin@school.com`
- Password: `123456`

The API currently keeps demo records in memory. Records reset when the API process restarts. MySQL settings in `backend/.env` are not required for this demo API.

## Deploy to Netlify

The frontend and Express API both deploy to the same Netlify site. Netlify Functions serves the API, and `/api/*` is routed to that function by `netlify.toml`; no separate API host or `VITE_API_URL` is needed.

1. Connect this GitHub repository to Netlify and use the repository root as the base directory. The build command and publish directory are already in `netlify.toml`.
2. In Netlify **Project configuration → Environment variables**, set `JWT_SECRET` to a private random value. Do not commit this value.
3. Deploy the latest `master` branch. Wait for the deploy to finish, then open `https://YOUR-SITE.netlify.app/api/health`; it should return an API-running JSON message.
4. Sign in with `admin@school.com` and `123456`, or choose **Use demo account** on the login form.

Netlify Functions run the existing Express routes on demand. Demo records are kept in memory and may reset between function instances or redeploys; persistent school records need a hosted database.