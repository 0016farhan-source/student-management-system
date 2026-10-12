const bcrypt = require('bcryptjs');

const users = [
  {
    id: 1,
    name: 'System Admin',
    email: 'admin@school.com',
    password: bcrypt.hashSync('admin123', 10),
    role: 'admin',
  },
];

const students = [
  {
    id: 1,
    name: 'Arif Rahman',
    email: 'arif@example.com',
    className: 'Grade 10',
    rollNumber: '10-A-01',
    phone: '+8801712345678',
    status: 'Active',
  },
  {
    id: 2,
    name: 'Nusrat Jahan',
    email: 'nusrat@example.com',
    className: 'Grade 9',
    rollNumber: '09-B-02',
    phone: '+8801887654321',
    status: 'Active',
  },
  {
    id: 3,
    name: 'Mehedi Hasan',
    email: 'mehedi@example.com',
    className: 'Grade 11',
    rollNumber: '11-C-03',
    phone: '+8801500011222',
    status: 'Inactive',
  },
];

const teachers = [
  {
    id: 1,
    name: 'Rafsan Karim',
    subject: 'Mathematics',
    email: 'rafsan.math@example.com',
    phone: '+8801700001111',
  },
  {
    id: 2,
    name: 'Shahida Akter',
    subject: 'Biology',
    email: 'shahida.bio@example.com',
    phone: '+8801700002222',
  },
];

const attendance = [
  { id: 1, studentId: 1, studentName: 'Arif Rahman', className: 'Grade 10', date: '2026-10-10', status: 'Present' },
  { id: 2, studentId: 2, studentName: 'Nusrat Jahan', className: 'Grade 9', date: '2026-10-10', status: 'Present' },
  { id: 3, studentId: 3, studentName: 'Mehedi Hasan', className: 'Grade 11', date: '2026-10-10', status: 'Absent' },
];

const results = [
  { id: 1, studentId: 1, studentName: 'Arif Rahman', subject: 'Mathematics', score: 92, grade: 'A+' },
  { id: 2, studentId: 2, studentName: 'Nusrat Jahan', subject: 'Chemistry', score: 88, grade: 'A' },
  { id: 3, studentId: 3, studentName: 'Mehedi Hasan', subject: 'Physics', score: 64, grade: 'B' },
];

const fees = [
  { id: 1, studentId: 1, studentName: 'Arif Rahman', amount: 12000, paid: 12000, status: 'Paid' },
  { id: 2, studentId: 2, studentName: 'Nusrat Jahan', amount: 12000, paid: 8000, status: 'Partial' },
  { id: 3, studentId: 3, studentName: 'Mehedi Hasan', amount: 12000, paid: 3000, status: 'Pending' },
];

const nextStudentId = () => students.reduce((max, student) => Math.max(max, student.id), 0) + 1;
const nextTeacherId = () => teachers.reduce((max, teacher) => Math.max(max, teacher.id), 0) + 1;

module.exports = {
  users,
  students,
  teachers,
  attendance,
  results,
  fees,
  nextStudentId,
  nextTeacherId,
};
