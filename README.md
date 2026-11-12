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

The community dashboard is available to administrators, organization officers, and custodians for borrowing approvals and return tracking. Administrators also manage equipment and member roles. The member dashboard focuses on available equipment, borrowing requests, and personal request history. Both dashboards share equipment and borrowing data; their demo sessions are stored separately. Each dashboard links directly to the other.

## Prototype limitations

The server-backed sign-in and protected API use an HTTP-only session cookie validated against the `user_sessions` table, so the session works across Vercel function instances. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` in Vercel and import the schema in `gamitshare_db.sql` into TiDB Cloud. Login attempts are recorded in `login_attempts` for 90 days; passwords are never stored. The login controller creates or upgrades this table automatically when its database account has DDL permissions. The admin dashboard's local demo fallback is for preview only; write operations still require a valid Administrator session.

Equipment photos are currently saved to `uploads/equipment` on the application filesystem. Vercel's serverless filesystem is not persistent, so production photo uploads require persistent object storage; without it, add/edit equipment without a photo. The local sign-in page requires a secure browser context (localhost or HTTPS) for Web Crypto.
