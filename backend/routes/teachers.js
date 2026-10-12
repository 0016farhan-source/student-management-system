const express = require('express');
const { teachers, nextTeacherId } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  res.json(teachers);
});

router.post('/', authenticate, (req, res) => {
  const { name, subject, email, phone } = req.body;

  if (!name || !subject || !email) {
    return res.status(400).json({ message: 'Name, subject, and email are required' });
  }

  const newTeacher = {
    id: nextTeacherId(),
    name,
    subject,
    email,
    phone: phone || '',
  };

  teachers.push(newTeacher);
  res.status(201).json(newTeacher);
});

module.exports = router;
