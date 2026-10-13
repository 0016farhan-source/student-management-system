const express = require('express');
const { fees, nextFeeId } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  res.json(fees);
});

router.post('/', authenticate, (req, res) => {
  const { studentId, studentName, amount, paid } = req.body;

  if (!studentId || !studentName || !amount) {
    return res.status(400).json({ message: 'Student and amount are required' });
  }

  const status = paid >= amount ? 'Paid' : paid > 0 ? 'Partial' : 'Pending';
  const entry = {
    id: nextFeeId(),
    studentId,
    studentName,
    amount,
    paid: paid || 0,
    status,
  };

  fees.push(entry);
  res.status(201).json(entry);
});

module.exports = router;
