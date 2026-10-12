const express = require('express');
const { students, teachers, attendance, results, fees } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  const activeStudents = students.filter((student) => student.status === 'Active').length;
  const presentCount = attendance.filter((item) => item.status === 'Present').length;
  const totalCollection = fees.reduce((sum, item) => sum + item.paid, 0);
  const avgScore = results.length
    ? results.reduce((sum, item) => sum + item.score, 0) / results.length
    : 0;

  res.json({
    students: students.length,
    teachers: teachers.length,
    activeStudents,
    presentToday: presentCount,
    totalCollection,
    averageScore: Number(avgScore.toFixed(1)),
  });
});

module.exports = router;
