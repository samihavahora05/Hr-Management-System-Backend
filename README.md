# ⚙️ HR Management System — Backend API

A secure, scalable, and high-performance **Enterprise Human Resource Management REST API** built with **Laravel 11**, **PHP 8.2+**, and structured with Domain-Driven design patterns. Powers the complete workflow for organizational workforce management, role-based authorization, payroll calculation, attendance tracking, and recruitment pipelines.

---

## 🌟 Architecture & Features

### 🛡️ 1. Authentication & Security
- **Multi-Role RBAC Middleware**: Custom `TokenAuthMiddleware` & `RoleAuthMiddleware` ensuring rigorous endpoint-level authorization for `admin`, `hr`, `manager`, `team_leader`, and `employee`.
- **Tenant & Identity Isolation**: Secure user boundaries, multi-department scoping, and audit trails.
- **Audit Logging Engine**: Automatic recording of critical actions (logins, role changes, salary revisions, leave approvals).

---

### 🧩 2. Core API Modules
- **👤 Employee Directory & Organization**:
  - Departments, Job Roles, Shifts, Branches, and Locations management.
  - Comprehensive Employee Master records with multi-tier reporting managers.
- **⏱️ Attendance & Timesheets**:
  - Check-in, check-out, duration computation, geo/ip logs, and status tags (`present`, `absent`, `half_day`, `late`).
  - Automated Anomaly Detection Job (`ScanAttendanceAnomalies`).
  - Project/task-based timesheets with manager approval cycles.
- **🏖️ Leave Management**:
  - Configurable leave types, annual balance tracking, multi-level approval workflows.
- **💵 Advanced Payroll & Compensation Engine**:
  - Base salary, custom allowances (HRA, Transport, Special), deductions (Tax, PF, Insurance), bonuses, and net pay computation.
  - Automated monthly payroll generation and salary slip data delivery.
- **🎯 Recruitment & Applicant Tracking (ATS)**:
  - Job openings, candidate status pipelines, interview schedules, ratings, and job offer letter generators.
  - Dynamic onboarding checklists with task completion tracking.
- **📊 Performance Appraisal (OKRs & KPIs)**:
  - Performance appraisal cycles, goal tracking, self & manager ratings, and review feedback.
- **💳 Finance (Claims & Loans)**:
  - Multi-category expense reimbursements with receipt attachments and approvals.
  - Employee loan applications, repayment schedules, and interest calculations.
- **📦 Assets & IT Helpdesk**:
  - Company asset inventory and assignments.
  - Helpdesk ticket lifecycle (`open`, `in_progress`, `resolved`, `closed`) with priority queues.
- **📢 Announcements & Notifications**:
  - Targeted broadcasts (company-wide, department-specific, role-specific) and real-time alert triggers.
- **🤖 HR AI Assistant API**:
  - Natural language query endpoints to assist with employee inquiries and operational lookup.

---

## 🛠️ Tech Stack

- **Framework**: [Laravel 11.x](https://laravel.com/)
- **Language**: [PHP 8.2+](https://www.php.net/)
- **Database Support**: MySQL / PostgreSQL / SQLite
- **Architecture**: RESTful JSON API with structured Controller-Service-Model design
- **Testing**: PHPUnit test suites with comprehensive Feature & Unit tests
- **Containerization**: Docker & Dockerfile support

---

## 📁 Project Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/          # REST API Controllers (Auth, Employee, Payroll, etc.)
│   │   └── Middleware/           # TokenAuthMiddleware, RoleAuthMiddleware
│   ├── Jobs/                     # Scheduled & Queued background tasks
│   ├── Models/                   # Eloquent ORM Models & Relationships
│   ├── Providers/                # Service Providers
│   └── Services/                 # Business logic (PayrollService, NotificationService)
├── bootstrap/                    # Application bootstrap & middleware registration
├── config/                       # Application configurations (CORS, Auth, Database, etc.)
├── database/
│   ├── factories/                # Model factories for testing & seeding
│   ├── migrations/               # Database schema migrations
│   └── seeders/                  # Demo & initial database seeders
├── routes/
│   ├── api.php                   # All HRMS REST API route definitions
│   └── web.php                   # Healthcheck & root endpoint
├── storage/                      # Logs, framework cache, and uploaded documents
├── tests/
│   ├── Feature/                  # Workflow & RBAC integration tests
│   └── Unit/                     # Unit test suites
├── artisan                       # Laravel CLI binary
└── composer.json                 # Project dependencies
```

---

## 🚀 Getting Started

### Prerequisites
- **PHP** >= 8.2 (with `pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` extensions)
- **Composer** (v2+)
- **MySQL / PostgreSQL / SQLite**

---

### Installation & Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/samihavahora05/Hr-Management-System-Backend.git
   cd Hr-Management-System-Backend
   ```

2. **Install PHP Dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment File**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure Database**:
   Update `.env` with your database credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=hrms_db
   DB_USERNAME=root
   DB_PASSWORD=your_password
   ```
   *(Or set `DB_CONNECTION=sqlite` and create `database/database.sqlite` for quick local setup)*

5. **Run Migrations & Seed Sample Data**:
   ```bash
   php artisan migrate --seed
   ```

6. **Start the API Server**:
   ```bash
   php artisan serve --port=8000
   ```
   The API will be live at `http://127.0.0.1:8000/api`.

---

## 🔑 Default Test Accounts (from DatabaseSeeder)

| Role | Email | Password | Access Level |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin@hrms.local` | `password` | Full system control & settings |
| **HR Manager** | `hr@hrms.local` | `password` | Recruitment, payroll, employees |
| **Manager** | `manager@hrms.local` | `password` | Team management & approvals |
| **Team Leader** | `lead@hrms.local` | `password` | Task allocation & timesheets |
| **Employee** | `employee@hrms.local` | `password` | Self-service portal |

---

## 🧪 Running Automated Tests

Run the full PHPUnit test suite covering authentication, permissions, workflows, and payroll calculations:

```bash
php artisan test
```

---

## 🐳 Docker Deployment

To run using Docker:
```bash
docker build -t hrms-backend .
docker run -p 8000:8000 hrms-backend
```

---

## 📄 License

This project is open-sourced software licensed under the **MIT License**.
