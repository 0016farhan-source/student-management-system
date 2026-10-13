const express = require('express');
const { results, nextResultId } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  res.json(results);
});

router.post('/', authenticate, (req, res) => {
  const { studentId, studentName, subject, score } = req.body;

  if (!studentId || !studentName || !subject || score === undefined) {
    return res.status(400).json({ message: 'Student, subject and score are required' });
  }

  const grade = score >= 90 ? 'A+' : score >= 80 ? 'A' : score >= 70 ? 'B' : score >= 60 ? 'C' : 'D';
  const entry = {
    id: nextResultId(),
    studentId,
    studentName,
    subject,
    score,
    grade,
  };

  results.push(entry);
  res.status(201).json(entry);
});

module.exports = router;
