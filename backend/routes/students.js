const express = require('express');
const { students, nextStudentId } = require('../data/store');
const { authenticate } = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, (req, res) => {
  res.json(students);
});

router.get('/summary', authenticate, (req, res) => {
  const activeStudents = students.filter((student) => student.status === 'Active').length;

  res.json({
    total: students.length,
    active: activeStudents,
    inactive: students.length - activeStudents,
  });
});

router.post('/', authenticate, (req, res) => {
  const { name, email, className, rollNumber, phone, status = 'Active' } = req.body;

  if (!name || !email || !className || !rollNumber) {
    return res.status(400).json({ message: 'Name, email, class, and roll number are required' });
  }

  const newStudent = {
    id: nextStudentId(),
    name,
    email,
    className,
    rollNumber,
    phone: phone || '',
    status,
  };

  students.push(newStudent);
  res.status(201).json(newStudent);
});

router.put('/:id', authenticate, (req, res) => {
  const { id } = req.params;
  const studentIndex = students.findIndex((student) => student.id === Number(id));

  if (studentIndex === -1) {
    return res.status(404).json({ message: 'Student not found' });
  }

  students[studentIndex] = { ...students[studentIndex], ...req.body };
  res.json(students[studentIndex]);
});

router.delete('/:id', authenticate, (req, res) => {
  const { id } = req.params;
  const studentIndex = students.findIndex((student) => student.id === Number(id));

  if (studentIndex === -1) {
    return res.status(404).json({ message: 'Student not found' });
  }

  const removed = students.splice(studentIndex, 1)[0];
  res.json({ message: 'Student deleted', student: removed });
});

module.exports = router;
