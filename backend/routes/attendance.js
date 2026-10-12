const express = require('express');
const { attendance } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  res.json(attendance);
});

router.post('/', authenticate, (req, res) => {
  const { studentId, studentName, className, date, status } = req.body;

  if (!studentId || !studentName || !date || !status) {
    return res.status(400).json({ message: 'Student, date, and status are required' });
  }

  const record = {
    id: attendance.length ? attendance[attendance.length - 1].id + 1 : 1,
    studentId,
    studentName,
    className: className || 'General',
    date,
    status,
  };

  attendance.push(record);
  res.status(201).json(record);
});

module.exports = router;
