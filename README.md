# GamitShare

A responsive community equipment borrowing prototype built with semantic HTML, CSS, vanilla JavaScript, and Bootstrap 5 for the separate role dashboards.

## Project structure

```text
gamitshare/
├── views/
│   ├── admin/
│   │   └── dashboard.html # Separate administrator & steward dashboard
│   ├── auth/
│   │   └── login.html     # Member sign-in and account creation
│   ├── member/
│   │   └── dashboard.html # Separate member dashboard
│   └── assets/
│       ├── css/           # Stylesheets (dashboard.css, auth.css, variables.css)
│       └── js/            # Client scripts (dashboard.js, auth.js)
├── config/
│   └── database.php       # MySQL PDO database connection singleton
├── controller/
│   ├── helpers.php        # API middleware, sessions, role authorization & activity logger
│   ├── login.php          # User sign-in with PBKDF2 verification & session cookie
│   ├── signup.php         # User registration with PBKDF2 hashing
│   ├── session.php        # Session authentication check & logout
│   ├── equipment.php      # Equipment Inventory Management (CRUD, categories, filtering)
│   ├── requests.php       # Borrowing requests, approvals & return condition check-in
│   ├── suggestions.php    # Item suggestions & donations review & approval workflow
│   ├── members.php        # User account & role management
│   ├── reports.php        # Overview metrics, usage analytics, overdue items & CSV export
│   └── activity.php       # Community activity log feed
├── gamitshare_db.sql      # Complete MySQL database schema & seed data
└── README.md              # Setup notes & system documentation
```

## Run locally

Open `views/auth/login.html` to sign in or create a member account, or open `views/admin/dashboard.html` for the administrator dashboard in a modern browser. The member dashboard redirects to sign-in when no member session exists. No build step is required. The pages load Bootstrap 5 from jsDelivr; Google Fonts are optional, with system font fallbacks.

### Test Accounts

| Role | Name | Email | Password |
| :--- | :--- | :--- | :--- |
| **Administrator** | Maya Santos | `maya.santos@example.org` | `admin12345` |
| **Organization Officer** | Noah Cruz | `noah.cruz@example.org` | `officer12345` |
| **Custodian** | Rafi Dela Cruz | `rafi.dc@example.org` | `custodian12345` |
| **Member** | Lina Reyes | `lina.reyes@example.org` | `member12345` |
| **Member** | Ivo Mercado | `ivo.m@example.org` | `member12345` |
salcedo.lerald@gmail.com admin123

> Direct prototype access: You can also navigate directly to `views/admin/dashboard.html` to test the Administrator dashboard without signing in.

The community dashboard is available to administrators, organization officers, and custodians for borrowing approvals and return tracking. Administrators also manage equipment and member roles. The member dashboard focuses on available equipment, borrowing requests, and personal request history. Both dashboards share equipment and borrowing data; their demo sessions are stored separately. Each dashboard links directly to the other.

## Prototype limitations

This is a front-end demonstration, not a production authentication or authorization system. Member passwords are hashed with PBKDF2 and stored in the browser, but browser data and role checks can be changed by the person using it; this does not replace server-side authentication. A production deployment needs a trusted API, server-side authorization, secure account registration/login, validated file uploads, and a shared database. The local sign-in page requires a secure browser context (localhost or HTTPS) for Web Crypto.
