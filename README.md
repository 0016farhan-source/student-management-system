# Student Management System

## Run locally

Use two terminals from the project folder.

**Terminal 1 — API server**

```powershell
cd backend
npm install
npm start
```

The API listens on `http://localhost:5000`. Opening that URL should return a small JSON message confirming the server is running.

**Terminal 2 — web app**

```powershell
cd frontend
npm install
npm run dev
```

Open the `Local` URL Vite prints (usually `http://localhost:5173`). If that port is busy, Vite prints another port; use that URL instead.

## Demo sign in

- Email: `admin@school.com`
- Password: `123456`

The API currently keeps its demo records in memory. Records reset when the API server restarts. MySQL settings in `backend/.env` are not required for this demo API.

## Deploy frontend to Netlify and API to Render

Netlify hosts the frontend; the Express API needs its own public Node web service. This repository includes `render.yaml` for deploying the API to Render and `netlify.toml` for the frontend build settings.

1. In Render, create a Blueprint from this repository and deploy the `student-management-api` service from `render.yaml`. Wait for its public `onrender.com` URL.
2. In Netlify, open **Project configuration → Environment variables** and add `VITE_API_URL` with the value `https://YOUR-API.onrender.com/api` (replace the host with the Render service URL).
3. Trigger a new Netlify deploy so Vite includes the API URL in the built frontend.
4. Confirm `https://YOUR-API.onrender.com/` returns the API-running message, then try the Netlify login again.

The API demo store is in memory, so its records reset when Render restarts or redeploys the service. The login API also needs `JWT_SECRET`, which the Render Blueprint generates for the service.
