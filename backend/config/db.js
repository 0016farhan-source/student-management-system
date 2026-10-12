const mysql = require('mysql2/promise');

let pool = null;

const hasDbConfig = () => {
  const { DB_HOST, DB_USER, DB_PASSWORD, DB_NAME } = process.env;
  return Boolean(DB_HOST && DB_USER && DB_PASSWORD && DB_NAME);
};

const connect = async () => {
  if (!hasDbConfig()) {
    return null;
  }

  if (!pool) {
    pool = mysql.createPool({
      host: process.env.DB_HOST,
      user: process.env.DB_USER,
      password: process.env.DB_PASSWORD,
      database: process.env.DB_NAME,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0,
    });
  }

  return pool;
};

module.exports = { connect };
