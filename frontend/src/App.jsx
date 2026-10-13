import { useEffect, useMemo, useState } from 'react';
import {
  Bell,
  BookOpen,
  ChevronDown,
  GraduationCap,
  LayoutDashboard,
  LogOut,
  Menu,
  Search,
  Users,
  X,
  ChartNoAxesColumn,
  School,
} from 'lucide-react';
import './App.css';
import './Dashboard.css';
import schoolHero from './assets/school-hero.jpg';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:5000/api';
const CURRENT_YEAR = new Date().getFullYear();
const singularLabels = {
  Overview: 'Student',
  Students: 'Student',
  Teachers: 'Teacher',
  Attendance: 'Attendance',
  Results: 'Result',
  Fees: 'Fee',
};
function Icon({ name, size = 18 }) {
  const common = {
    width: size,
    height: size,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
  };

  const paths = {
    grid: <><rect x="3.5" y="3.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" /><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" /></>,
    users: <><path d="M16 20v-1.5a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4V20" /><circle cx="10" cy="7.5" r="3.5" /><path d="M20 20v-1.5a4 4 0 0 0-3-3.87M16 4.13a3.5 3.5 0 0 1 0 6.74" /></>,
    teacher: <><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M8 21h8M12 17v4M7 9h5M7 12h3M16 8v5l2-1.5 2 1.5V8z" /></>,
    check: <><circle cx="12" cy="12" r="9" /><path d="m8 12 2.5 2.5L16.5 9" /></>,
    chart: <><path d="M4 19.5h16" /><path d="M7 16v-5M12 16V5M17 16V9" /><circle cx="7" cy="9" r="1" /><circle cx="17" cy="7" r="1" /></>,
    wallet: <><rect x="3" y="5" width="18" height="15" rx="2" /><path d="M3 9h18M16 14h2" /><path d="M7 5V3h10v2" /></>,
  };

  return <svg {...common}>{paths[name] || paths.grid}</svg>;
}

const initialForm = {
  name: '',
  email: '',
  className: '',
  rollNumber: '',
  phone: '',
  status: 'Active',
};

const sidebarItems = [
  { label: 'Overview', title: 'Dashboard', icon: LayoutDashboard },
  { label: 'Teachers', title: 'Teachers', icon: School },
  { label: 'Students', title: 'Students', icon: Users },
  { label: 'Attendance', title: 'Attendance', icon: GraduationCap },
  { label: 'Results', title: 'Results', icon: BookOpen },
  { label: 'Fees', title: 'Fees', icon: ChartNoAxesColumn },
];

const emptyAcademicFilters = { className: '', session: '', subject: '', grade: '', attendance: '', status: '' };

function Sidebar({ activeTab, onNavigate, userName, userRole, isOpen, onClose }) {
  return (
    <>
      {isOpen && <button type="button" className="sidebar-backdrop" onClick={onClose} aria-label="Close navigation" />}
      <aside className={`sidebar ${isOpen ? 'sidebar-open' : ''}`}>
        <div className="brand-block">
          <div className="brand-icon"><GraduationCap size={22} aria-hidden="true" /></div>
          <div>
            <p className="eyebrow">School Portal</p>
            <h2>Student Desk</h2>
          </div>
          <button type="button" className="mobile-close" onClick={onClose} aria-label="Close navigation">
            <X size={20} />
          </button>
        </div>

        <nav className="nav-menu" aria-label="Main navigation">
          <p className="nav-label">Workspace</p>
          {sidebarItems.map(({ label, title, icon: ItemIcon }) => (
            <button
              key={label}
              type="button"
              aria-current={activeTab === label ? 'page' : undefined}
              className={`nav-item ${activeTab === label ? 'active' : ''}`}
              onClick={() => {
                onNavigate(label);
                onClose();
              }}
            >
              <ItemIcon size={18} strokeWidth={1.8} aria-hidden="true" />
              <span>{title}</span>
            </button>
          ))}
        </nav>
        <div className="sidebar-footer">
          <div className="sidebar-user-avatar">{userName.charAt(0).toUpperCase()}</div>
          <div><strong>{userName}</strong><span>{userRole} account</span></div>
        </div>
      </aside>
    </>
  );
}

function Header({
  activeTab,
  userName,
  searchTerm,
  onSearchChange,
  onSearchSubmit,
  onLogout,
  onAdd,
  onMenu,
}) {
  const [showNotifications, setShowNotifications] = useState(false);
  const [showProfile, setShowProfile] = useState(false);
  const title = activeTab === 'Overview' ? 'Dashboard' : activeTab;

  return (
    <header className="topbar">
      <div className="topbar-title">
        <button type="button" className="mobile-menu" onClick={onMenu} aria-label="Open navigation">
          <Menu size={21} />
        </button>
        <div>
          <p className="eyebrow">{title}</p>
          <h1>School Management System</h1>
          <p className="page-caption">A Great Place For Education</p>
        </div>
        </div>

      <div className="topbar-actions">
        <form className="global-search" onSubmit={onSearchSubmit} role="search">
          <Search size={17} aria-hidden="true" />
          <input
            type="search"
            aria-label="Search students"
            placeholder="Search students"
            value={searchTerm}
            onChange={onSearchChange}
          />
        </form>
        <div className="header-popover-anchor">
          <button
            type="button"
            className={`icon-button header-icon-button ${showNotifications ? 'is-selected' : ''}`}
            aria-label="Notifications"
            aria-expanded={showNotifications}
            onClick={() => {
              setShowNotifications((open) => !open);
              setShowProfile(false);
            }}
          >
            <Bell size={18} />
          </button>
          {showNotifications && (
            <div className="header-popover notification-popover" role="status">
              <strong>Notifications</strong>
              <p>Notifications are not connected to this dashboard yet.</p>
            </div>
          )}
        </div>
        <div className="header-popover-anchor">
          <button
            type="button"
            className="profile-trigger"
            aria-expanded={showProfile}
            onClick={() => {
              setShowProfile((open) => !open);
              setShowNotifications(false);
            }}
          >
            <span className="admin-avatar">{userName.charAt(0).toUpperCase()}</span>
            <span className="profile-name">{userName}</span>
            <ChevronDown size={15} aria-hidden="true" />
          </button>
          {showProfile && (
            <div className="header-popover profile-popover">
              <strong>{userName}</strong>
              <button type="button" onClick={onLogout}><LogOut size={15} /> Sign out</button>
            </div>
          )}
        </div>
        <button type="button" className="primary-btn add-record-btn" onClick={onAdd}>
          <span aria-hidden="true">+</span> Add {singularLabels[activeTab]}
        </button>
      </div>
    </header>
  );
}

function HeroBanner({ userName }) {
  return (
    <section className="welcome-banner hero-banner">
      <img className="hero-photo" src={schoolHero} alt="Students learning together in a classroom" />
      <div className="hero-copy">
        <span className="welcome-label">A BETTER WAY TO LEARN</span>
        <h2>Our Awesome School</h2>
        <p>Inspiring curious minds and building brighter futures, every day.</p>
        {userName && <span className="hero-greeting">Welcome back, {userName.split(' ')[0]}</span>}
      </div>
      <div className="hero-decoration" aria-hidden="true">
        <BookOpen size={27} />
        <span>Learn · Grow · Succeed</span>
      </div>
    </section>
  );
}

function FilterField({ label, value, options, onChange }) {
  const fieldId = `academic-filter-${label.toLowerCase().replaceAll(' ', '-')}`;
  return (
    <label className="filter-field" htmlFor={fieldId}>
      <span>{label}</span>
      <div className="filter-select-wrap">
        <select id={fieldId} value={value} onChange={onChange}>
        <option value="">{label === 'Class' ? 'All classes' : `All ${label.toLowerCase()}s`}</option>
          {options.map((option) => <option key={option} value={option}>{option}</option>)}
        </select>
        <ChevronDown size={16} aria-hidden="true" />
      </div>
    </label>
  );
}

function App() {
  const [activeTab, setActiveTab] = useState('Overview');
  const [students, setStudents] = useState([]);
  const [teachers, setTeachers] = useState([]);
  const [attendance, setAttendance] = useState([]);
  const [results, setResults] = useState([]);
  const [fees, setFees] = useState([]);
  const [dashboard, setDashboard] = useState({ students: 0, teachers: 0, activeStudents: 0, presentToday: 0, totalCollection: 0, averageScore: 0 });
  const [token, setToken] = useState(() => sessionStorage.getItem('student-desk-token') || '');
  const [loading, setLoading] = useState(() => Boolean(sessionStorage.getItem('student-desk-token')));
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [loginLoading, setLoginLoading] = useState(false);
  const [selectedStudent, setSelectedStudent] = useState(null);
  const [form, setForm] = useState(initialForm);
  const [currentUser, setCurrentUser] = useState({ name: 'Administrator', role: 'admin' });
  const [searchTerm, setSearchTerm] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [moduleForm, setModuleForm] = useState({});
  const [notice, setNotice] = useState('');
  const [requestError, setRequestError] = useState('');
  const [saving, setSaving] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [academicFilters, setAcademicFilters] = useState(emptyAcademicFilters);
  const authHeaders = useMemo(
    () => ({
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    }),
    [token]
  );

  useEffect(() => {
    if (!token) return;

    const loadData = async () => {
      try {
        const [studentsRes, summaryRes, teachersRes, attendanceRes, resultsRes, feesRes] = await Promise.all([
          fetch(`${API_URL}/students`, { headers: authHeaders }),
          fetch(`${API_URL}/dashboard`, { headers: authHeaders }),
          fetch(`${API_URL}/teachers`, { headers: authHeaders }),
          fetch(`${API_URL}/attendance`, { headers: authHeaders }),
          fetch(`${API_URL}/results`, { headers: authHeaders }),
          fetch(`${API_URL}/fees`, { headers: authHeaders }),
        ]);

        const responses = [studentsRes, summaryRes, teachersRes, attendanceRes, resultsRes, feesRes];
        if (responses.some((response) => response.status === 401)) {
          sessionStorage.removeItem('student-desk-token');
          setToken('');
          setRequestError('Your session has expired. Please log in again.');
          return;
        }

        const failedResponse = responses.find((response) => !response.ok);
        if (failedResponse) {
          throw new Error(`Could not load dashboard data (HTTP ${failedResponse.status})`);
        }

        if (studentsRes.ok) {
          const studentData = await studentsRes.json();
          setStudents(studentData);
        }

        if (summaryRes.ok) {
          const summaryData = await summaryRes.json();
          setDashboard(summaryData);
        }

        if (teachersRes.ok) {
          setTeachers(await teachersRes.json());
        }

        if (attendanceRes.ok) {
          setAttendance(await attendanceRes.json());
        }

        if (resultsRes.ok) {
          setResults(await resultsRes.json());
        }

        if (feesRes.ok) {
          setFees(await feesRes.json());
        }
      } catch (error) {
        console.error('Failed to load dashboard data:', error);
        setRequestError(error instanceof Error ? error.message : 'Could not load dashboard data.');
      } finally {
        setLoading(false);
      }
    };

    loadData();
  }, [authHeaders, token]);

  const handleLogout = () => {
    sessionStorage.removeItem('student-desk-token');
    setToken('');
    setCurrentUser({ name: 'Administrator', role: 'admin' });
    setActiveTab('Overview');
  };

  const handleLogin = async (event) => {
    event.preventDefault();
    setLoginLoading(true);
    setRequestError('');
    try {
      const response = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: loginEmail, password: loginPassword }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not sign in.');
      sessionStorage.setItem('student-desk-token', data.token);
      setCurrentUser(data.user);
      setToken(data.token);
    } catch (error) {
      setRequestError(error instanceof Error ? error.message : 'Could not connect to the server.');
    } finally {
      setLoginLoading(false);
    }
  };

  const handleAddClick = () => {
    setNotice('');
    setRequestError('');
    if (activeTab === 'Students') {
      setSelectedStudent(null);
      setForm(initialForm);
      document.getElementById('student-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      document.querySelector('#student-form input')?.focus({ preventScroll: true });
      return;
    }
    if (activeTab === 'Overview') {
      setActiveTab('Students');
      return;
    }

    const now = new Date();
    const today = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    setModuleForm(
      activeTab === 'Teachers'
        ? { name: '', subject: '', email: '', phone: '' }
        : activeTab === 'Attendance'
          ? { studentId: '', date: today, status: 'Present' }
          : activeTab === 'Results'
            ? { studentId: '', subject: '', score: '' }
            : { studentId: '', amount: '', paid: '0' }
    );
    setModalOpen(true);
  };

  const handleModuleSubmit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setNotice('');
    setRequestError('');

    const endpoints = {
      Teachers: 'teachers',
      Attendance: 'attendance',
      Results: 'results',
      Fees: 'fees',
    };
    const endpoint = endpoints[activeTab];
    let payload = moduleForm;

    if (activeTab !== 'Teachers') {
      const student = students.find((item) => item.id === Number(moduleForm.studentId));
      if (!student) {
        setRequestError('Please select a student.');
        setSaving(false);
        return;
      }
      payload = {
        ...moduleForm,
        studentId: student.id,
        studentName: student.name,
        className: student.className,
      };
      if (activeTab === 'Results' || activeTab === 'Fees') {
        payload = { ...payload, score: Number(moduleForm.score), amount: Number(moduleForm.amount), paid: Number(moduleForm.paid || 0) };
      }
    }

    try {
      const response = await fetch(`${API_URL}/${endpoint}`, {
        method: 'POST',
        headers: authHeaders,
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || `Could not save ${activeTab.toLowerCase()} record`);
      }

      if (activeTab === 'Teachers') {
        setTeachers((current) => [data, ...current]);
        setDashboard((current) => ({ ...current, teachers: current.teachers + 1 }));
      }
      if (activeTab === 'Attendance') setAttendance((current) => [data, ...current]);
      if (activeTab === 'Results') {
        setResults((current) => [data, ...current]);
        setDashboard((current) => ({
          ...current,
          averageScore: Number(
            ((results.reduce((sum, result) => sum + result.score, data.score)) / (results.length + 1)).toFixed(1)
          ),
        }));
      }
      if (activeTab === 'Fees') {
        setFees((current) => [data, ...current]);
        setDashboard((current) => ({ ...current, totalCollection: current.totalCollection + data.paid }));
      }
      setModalOpen(false);
      setNotice(`${singularLabels[activeTab]} record added successfully.`);
    } catch (error) {
      setRequestError(error instanceof Error ? error.message : 'Could not save this record.');
    } finally {
      setSaving(false);
    }
  };

  const handleModuleChange = (event) => {
    const { name, value } = event.target;
    setModuleForm((current) => ({ ...current, [name]: value }));
  };

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  };

  const handleSubmit = async (event) => {
    event.preventDefault();

    if (!token) {
      return;
    }
    setSaving(true);
    setRequestError('');
    setNotice('');

    const method = selectedStudent ? 'PUT' : 'POST';
    const url = selectedStudent ? `${API_URL}/students/${selectedStudent.id}` : `${API_URL}/students`;

    try {
      const response = await fetch(url, {
        method,
        headers: authHeaders,
        body: JSON.stringify(form),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'Student submission failed');
      }

      const updated = data;
      if (selectedStudent) {
        setStudents((current) => current.map((student) => (student.id === updated.id ? updated : student)));
        if (selectedStudent.status !== updated.status) {
          setDashboard((current) => ({
            ...current,
            activeStudents: current.activeStudents + (updated.status === 'Active' ? 1 : -1),
          }));
        }
      } else {
        setStudents((current) => [updated, ...current]);
        setDashboard((current) => ({
          ...current,
          students: current.students + 1,
          activeStudents: current.activeStudents + (updated.status === 'Active' ? 1 : 0),
        }));
      }

      setForm(initialForm);
      setSelectedStudent(null);
      setNotice(`Student ${selectedStudent ? 'updated' : 'added'} successfully.`);
    } catch (error) {
      setRequestError(error instanceof Error ? error.message : 'Could not save this student.');
    } finally {
      setSaving(false);
    }
  };

  const handleEdit = (student) => {
    setSelectedStudent(student);
    setForm({
      name: student.name,
      email: student.email,
      className: student.className,
      rollNumber: student.rollNumber,
      phone: student.phone,
      status: student.status,
    });
  };

  const handleDelete = async (id) => {
    if (!token) return;
    setRequestError('');
    setNotice('');

    try {
      const response = await fetch(`${API_URL}/students/${id}`, {
        method: 'DELETE',
        headers: authHeaders,
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'Delete failed');
      }

      const deletedStudent = students.find((student) => student.id === id);
      setStudents((current) => current.filter((student) => student.id !== id));
      if (deletedStudent) {
        setDashboard((current) => ({
          ...current,
          students: Math.max(0, current.students - 1),
          activeStudents: Math.max(0, current.activeStudents - (deletedStudent.status === 'Active' ? 1 : 0)),
        }));
      }
      setNotice('Student deleted successfully.');
    } catch (error) {
      setRequestError(error instanceof Error ? error.message : 'Could not delete this student.');
    }
  };

  const visibleStudents = students.filter((student) =>
    [student.name, student.email, student.className, student.rollNumber]
      .some((value) => value.toLowerCase().includes(searchTerm.toLowerCase()))
  );
  const handleSearchChange = (event) => {
    setSearchTerm(event.target.value);
    if (event.target.value) setActiveTab('Students');
  };
  const handleSearchSubmit = (event) => {
    event.preventDefault();
    setActiveTab('Students');
  };
  const filterOptions = {
    className: [...new Set(students.map((student) => student.className).filter(Boolean))].sort(),
    session: [...new Set(attendance.map((record) => record.date?.slice(0, 4)).filter(Boolean))].sort().reverse(),
    subject: [...new Set(results.map((result) => result.subject).filter(Boolean))].sort(),
    grade: [...new Set(results.map((result) => result.grade).filter(Boolean))].sort(),
    attendance: [...new Set(attendance.map((record) => record.status).filter(Boolean))].sort(),
    status: [...new Set(students.map((student) => student.status).filter(Boolean))].sort(),
  };
  const academicResults = results.filter((result) =>
    (!academicFilters.subject || result.subject === academicFilters.subject)
    && (!academicFilters.grade || result.grade === academicFilters.grade)
  );
  const selectedResultStudentIds = new Set(academicResults.map((result) => result.studentId));
  const hasResultFilter = Boolean(academicFilters.subject || academicFilters.grade);
  const academicAttendance = attendance.filter((record) =>
    (!academicFilters.session || record.date?.startsWith(academicFilters.session))
    && (!academicFilters.attendance || record.status === academicFilters.attendance)
    && (!hasResultFilter || selectedResultStudentIds.has(record.studentId))
    && (!academicFilters.className || students.some((student) => student.id === record.studentId && student.className === academicFilters.className))
    && (!academicFilters.status || students.some((student) => student.id === record.studentId && student.status === academicFilters.status))
  );
  const hasAttendanceFilter = Boolean(academicFilters.session || academicFilters.attendance);
  const academicStudentIds = new Set(academicAttendance.map((record) => record.studentId));
  const academicStudents = students.filter((student) =>
    (!academicFilters.className || student.className === academicFilters.className)
    && (!academicFilters.status || student.status === academicFilters.status)
    && (!hasResultFilter || selectedResultStudentIds.has(student.id))
    && (!hasAttendanceFilter || academicStudentIds.has(student.id))
  );
  const academicStudentIdsFiltered = new Set(academicStudents.map((student) => student.id));
  const visibleAcademicResults = academicResults.filter((result) => academicStudentIdsFiltered.has(result.studentId));
  const academicTeachers = academicFilters.subject
    ? teachers.filter((teacher) => teacher.subject === academicFilters.subject)
    : teachers;
  const academicFees = fees.filter((fee) => academicStudentIdsFiltered.has(fee.studentId));
  const attendancePresent = academicAttendance.filter((record) => record.status === 'Present').length;
  const attendancePercent = academicAttendance.length
    ? Math.round((attendancePresent / academicAttendance.length) * 100)
    : 0;
  const classBreakdown = Object.entries(
    academicStudents.reduce((groups, student) => {
      groups[student.className] = (groups[student.className] || 0) + 1;
      return groups;
    }, {})
  ).sort((left, right) => right[1] - left[1]).slice(0, 5);
  const dueFees = academicFees.reduce((total, fee) => total + Math.max(0, fee.amount - fee.paid), 0);
  const academicAverageScore = visibleAcademicResults.length
    ? Number((visibleAcademicResults.reduce((sum, result) => sum + result.score, 0) / visibleAcademicResults.length).toFixed(1))
    : 0;
  if (!token) {
    return (
      <main className="login-page">
        <section className="login-card" aria-labelledby="login-title">
          <div className="login-brand">
            <div className="login-icon"><GraduationCap size={24} aria-hidden="true" /></div>
            <div><p className="eyebrow">School Portal</p><h1 id="login-title">Student Desk</h1></div>
          </div>
          <div className="form-header"><h2>Welcome back</h2><p>Sign in to manage your school.</p></div>
          <form className="login-form" onSubmit={handleLogin}>
            <label>Email<input type="email" autoComplete="username" value={loginEmail} onChange={(event) => setLoginEmail(event.target.value)} required /></label>
            <label>Password<input type="password" autoComplete="current-password" value={loginPassword} onChange={(event) => setLoginPassword(event.target.value)} required /></label>
            {requestError && <p className="login-error" role="alert">{requestError}</p>}
            <button className="primary-btn" type="submit" disabled={loginLoading}>{loginLoading ? 'Signing in…' : 'Sign in'}</button>
          </form>
          <p className="demo-hint">
            Demo account:{' '}
            <button
              type="button"
              className="demo-fill-btn"
              onClick={() => {
                setLoginEmail('admin@school.com');
                setLoginPassword('123456');
                setRequestError('');
              }}
            >
              Use demo account
            </button>
          </p>
        </section>
      </main>
    );
  }

  return (
    <div className="dashboard-shell">
      <Sidebar
        activeTab={activeTab}
        onNavigate={setActiveTab}
        userName={currentUser.name}
        userRole={currentUser.role}
        isOpen={mobileMenuOpen}
        onClose={() => setMobileMenuOpen(false)}
      />

      <main className="main-panel">
        <Header
          activeTab={activeTab}
          userName={currentUser.name}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          onSearchSubmit={handleSearchSubmit}
          onLogout={handleLogout}
          onAdd={handleAddClick}
          onMenu={() => setMobileMenuOpen(true)}
        />

        {notice && <div className="feedback success-feedback" role="status">{notice}</div>}
        {requestError && <div className="feedback error-feedback" role="alert">{requestError}</div>}

        {activeTab === 'Overview' && (
          <>
            <HeroBanner userName={currentUser.name} />

            <section className="academic-filters" aria-labelledby="filter-title">
              <div className="filter-heading">
                <div>
                  <h2 id="filter-title">Academic filters</h2>
                  <p id="filter-catalog-note">Filter the overview using the academic records in your account.</p>
                </div>
                <button type="button" className="filter-reset-btn" onClick={() => setAcademicFilters(emptyAcademicFilters)}>Clear filters</button>
              </div>
              <div className="filter-grid">
                {[
                  ['Class', 'className'],
                  ['Session', 'session'],
                  ['Subject', 'subject'],
                  ['Grade', 'grade'],
                  ['Attendance', 'attendance'],
                  ['Student status', 'status'],
                ].map(([label, key]) => (
                  <FilterField
                    key={key}
                    label={label}
                    value={academicFilters[key]}
                    options={filterOptions[key]}
                    onChange={(event) => setAcademicFilters((current) => ({ ...current, [key]: event.target.value }))}
                  />
                ))}
              </div>
            </section>

            <section className="stats-grid">
              <div className="stat-card accent">
                <div className="stat-heading"><span>Total Students</span><i><Icon name="users" /></i></div>
                <strong>{loading ? '...' : academicStudents.length}</strong>
                <small>Matching enrolled learners</small>
              </div>
              <div className="stat-card success">
                <div className="stat-heading"><span>Teaching Staff</span><i><Icon name="teacher" /></i></div>
                <strong>{loading ? '...' : academicTeachers.length}</strong>
                <small>{academicFilters.subject ? `Teaching ${academicFilters.subject}` : 'Registered teachers'}</small>
              </div>
              <div className="stat-card warning">
                <div className="stat-heading"><span>Attendance Rate</span><i><Icon name="check" /></i></div>
                <strong>{loading ? '...' : `${attendancePercent}%`}</strong>
                <small>{attendancePresent} present of {academicAttendance.length} matching records</small>
              </div>
              <div className="stat-card dark">
                <div className="stat-heading"><span>Average Score</span><i><Icon name="chart" /></i></div>
                <strong>{loading ? '...' : `${academicAverageScore}%`}</strong>
                <small>Across matching results</small>
              </div>
            </section>

            <section className="overview-grid">
              <div className="panel">
                <div className="panel-header panel-heading">
                  <div><h3>Students by class</h3><span>Current enrollment distribution</span></div>
                  <button type="button" className="text-button" onClick={() => setActiveTab('Students')}>View students</button>
                </div>
                {classBreakdown.length ? (
                  <div className="class-breakdown">
                    {classBreakdown.map(([className, count]) => (
                      <div className="class-row" key={className}>
                        <div className="class-row-label"><span>{className}</span><strong>{count}</strong></div>
                        <div className="progress-track"><span style={{ width: `${Math.max(8, Math.round((count / academicStudents.length) * 100))}%` }} /></div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="empty-overview"><Icon name="users" size={24} /><p>Student enrollment details will appear here.</p></div>
                )}
              </div>

              <div className="panel">
                <div className="panel-header panel-heading">
                  <div><h3>Fee collection</h3><span>Payment summary</span></div>
                  <i className="summary-icon"><Icon name="wallet" /></i>
                </div>
                <div className="finance-summary">
                  <div><span>Collected</span><strong>৳ {academicFees.reduce((sum, fee) => sum + fee.paid, 0).toLocaleString()}</strong></div>
                  <div><span>Outstanding</span><strong>৳ {dueFees.toLocaleString()}</strong></div>
                  <button type="button" className="text-button" onClick={() => setActiveTab('Fees')}>Open fee records <span aria-hidden="true">→</span></button>
                </div>
              </div>
            </section>
          </>
        )}

        {activeTab === 'Students' && (
          <section className="content-grid">
            <div className="panel table-panel">
              <div className="panel-header">
                <div>
                  <h3>Student List</h3>
                  <span>{visibleStudents.length} of {students.length} records</span>
                </div>
                <label className="search-field">
                  <span className="sr-only">Search students</span>
                  <input
                    type="search"
                    value={searchTerm}
                    onChange={(event) => setSearchTerm(event.target.value)}
                    placeholder="Search students..."
                  />
                </label>
              </div>

              <table>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Class</th>
                    <th>Roll</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleStudents.map((student) => (
                    <tr key={student.id}>
                      <td>
                        <div className="student-cell">
                          <div className="avatar">{student.name.charAt(0)}</div>
                          <div>
                            <strong>{student.name}</strong>
                            <small>{student.email}</small>
                          </div>
                        </div>
                      </td>
                      <td>{student.className}</td>
                      <td>{student.rollNumber}</td>
                      <td>
                        <span className={`status ${student.status.toLowerCase()}`}>{student.status}</span>
                      </td>
                      <td className="action-cell">
                        <button type="button" className="ghost-btn" onClick={() => handleEdit(student)}>Edit</button>
                        <button
                          type="button"
                          className="danger-btn"
                          onClick={() => {
                            if (window.confirm(`Delete ${student.name} from the student list?`)) {
                              handleDelete(student.id);
                            }
                          }}
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                  {visibleStudents.length === 0 && (
                    <tr>
                      <td colSpan="5" className="empty-state">No students match your search.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <div className="panel form-panel" id="student-form">
              <div className="panel-header">
                <h3>{selectedStudent ? 'Edit Student' : 'Add Student'}</h3>
              </div>

              <form onSubmit={handleSubmit} className="student-form">
                <label>
                  Full Name
                  <input name="name" value={form.name} onChange={handleChange} placeholder="Enter student name" required />
                </label>
                <label>
                  Email
                  <input type="email" name="email" value={form.email} onChange={handleChange} placeholder="student@email.com" required />
                </label>
                <label>
                  Class
                  <input name="className" value={form.className} onChange={handleChange} placeholder="Grade 10" required />
                </label>
                <label>
                  Roll Number
                  <input name="rollNumber" value={form.rollNumber} onChange={handleChange} placeholder="10-A-01" required />
                </label>
                <label>
                  Phone
                  <input name="phone" value={form.phone} onChange={handleChange} placeholder="+880" />
                </label>
                <label>
                  Status
                  <select name="status" value={form.status} onChange={handleChange}>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </label>

                <div className="form-actions">
                  <button type="submit" className="primary-btn" disabled={saving}>
                    {saving ? 'Saving...' : selectedStudent ? 'Update Student' : 'Save Student'}
                  </button>
                  {selectedStudent && (
                    <button type="button" className="secondary-btn" onClick={() => {
                      setSelectedStudent(null);
                      setForm(initialForm);
                    }}>
                      Cancel
                    </button>
                  )}
                </div>
              </form>
            </div>
          </section>
        )}

        {activeTab === 'Teachers' && (
          <div className="panel">
            <div className="panel-header">
              <h3>Teacher Directory</h3>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Subject</th>
                  <th>Email</th>
                  <th>Phone</th>
                </tr>
              </thead>
              <tbody>
                {teachers.map((teacher) => (
                  <tr key={teacher.id}>
                    <td>{teacher.name}</td>
                    <td>{teacher.subject}</td>
                    <td>{teacher.email}</td>
                    <td>{teacher.phone}</td>
                  </tr>
                ))}
                {teachers.length === 0 && <tr><td colSpan="4" className="empty-state">No teacher records yet.</td></tr>}
              </tbody>
            </table>
          </div>
        )}

        {activeTab === 'Attendance' && (
          <div className="panel">
            <div className="panel-header">
              <h3>Attendance Tracker</h3>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Class</th>
                  <th>Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {attendance.map((record) => (
                  <tr key={record.id}>
                    <td>{record.studentName}</td>
                    <td>{record.className}</td>
                    <td>{record.date}</td>
                    <td><span className={`status ${record.status === 'Present' ? 'active' : 'inactive'}`}>{record.status}</span></td>
                  </tr>
                ))}
                {attendance.length === 0 && <tr><td colSpan="4" className="empty-state">No attendance records yet.</td></tr>}
              </tbody>
            </table>
          </div>
        )}

        {activeTab === 'Results' && (
          <div className="panel">
            <div className="panel-header">
              <h3>Academic Results</h3>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Subject</th>
                  <th>Score</th>
                  <th>Grade</th>
                </tr>
              </thead>
              <tbody>
                {results.map((item) => (
                  <tr key={item.id}>
                    <td>{item.studentName}</td>
                    <td>{item.subject}</td>
                    <td>{item.score}</td>
                    <td>{item.grade}</td>
                  </tr>
                ))}
                {results.length === 0 && <tr><td colSpan="4" className="empty-state">No result records yet.</td></tr>}
              </tbody>
            </table>
          </div>
        )}

        {activeTab === 'Fees' && (
          <div className="panel">
            <div className="panel-header">
              <h3>Fees Management</h3>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Amount</th>
                  <th>Paid</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {fees.map((fee) => (
                  <tr key={fee.id}>
                    <td>{fee.studentName}</td>
                    <td>৳ {fee.amount}</td>
                    <td>৳ {fee.paid}</td>
                    <td><span className={`status ${fee.status === 'Paid' ? 'active' : 'inactive'}`}>{fee.status}</span></td>
                  </tr>
                ))}
                {fees.length === 0 && <tr><td colSpan="4" className="empty-state">No fee records yet.</td></tr>}
              </tbody>
            </table>
          </div>
        )}

        {modalOpen && (
          <div className="modal-backdrop" role="presentation" onMouseDown={(event) => {
            if (event.target === event.currentTarget) setModalOpen(false);
          }}>
            <section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="module-form-title">
              <div className="panel-header">
                <div>
                  <p className="eyebrow">Create record</p>
                  <h3 id="module-form-title">Add {singularLabels[activeTab]}</h3>
                </div>
                <button type="button" className="icon-button" onClick={() => setModalOpen(false)} aria-label="Close form">×</button>
              </div>
              <form className="student-form" onSubmit={handleModuleSubmit}>
                {activeTab === 'Teachers' ? (
                  <>
                    <label>Full name<input name="name" value={moduleForm.name || ''} onChange={handleModuleChange} required /></label>
                    <label>Subject<input name="subject" value={moduleForm.subject || ''} onChange={handleModuleChange} required /></label>
                    <label>Email<input type="email" name="email" value={moduleForm.email || ''} onChange={handleModuleChange} required /></label>
                    <label>Phone<input name="phone" value={moduleForm.phone || ''} onChange={handleModuleChange} /></label>
                  </>
                ) : (
                  <>
                    <label>
                      Student
                      <select name="studentId" value={moduleForm.studentId || ''} onChange={handleModuleChange} required>
                        <option value="">Select a student</option>
                        {students.map((student) => (
                          <option key={student.id} value={student.id}>{student.name} · {student.rollNumber}</option>
                        ))}
                      </select>
                    </label>
                    {activeTab === 'Attendance' && (
                      <>
                        <label>Date<input type="date" name="date" value={moduleForm.date || ''} onChange={handleModuleChange} required /></label>
                        <label>Status<select name="status" value={moduleForm.status || 'Present'} onChange={handleModuleChange}><option>Present</option><option>Absent</option><option>Late</option></select></label>
                      </>
                    )}
                    {activeTab === 'Results' && (
                      <>
                        <label>Subject<input name="subject" value={moduleForm.subject || ''} onChange={handleModuleChange} required /></label>
                        <label>Score (0–100)<input type="number" name="score" min="0" max="100" value={moduleForm.score || ''} onChange={handleModuleChange} required /></label>
                      </>
                    )}
                    {activeTab === 'Fees' && (
                      <>
                        <label>Total amount (৳)<input type="number" name="amount" min="1" value={moduleForm.amount || ''} onChange={handleModuleChange} required /></label>
                        <label>Paid amount (৳)<input type="number" name="paid" min="0" value={moduleForm.paid ?? '0'} onChange={handleModuleChange} required /></label>
                      </>
                    )}
                  </>
                )}
                {requestError && <p className="login-error" role="alert">{requestError}</p>}
                <div className="form-actions">
                  <button type="button" className="secondary-btn" onClick={() => setModalOpen(false)}>Cancel</button>
                  <button type="submit" className="primary-btn" disabled={saving}>{saving ? 'Saving...' : 'Save record'}</button>
                </div>
              </form>
            </section>
          </div>
        )}
      </main>
      <footer className="frontend-copyright">Copyright © {CURRENT_YEAR} Farhan Ahmed</footer>
    </div>
  );
}

export default App;
