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
