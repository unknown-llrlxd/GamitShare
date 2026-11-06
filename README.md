# GamitShare

A responsive community equipment borrowing prototype built with semantic HTML, CSS, vanilla JavaScript, and Bootstrap 5 for the separate role dashboards.

## Project structure

```text
gamitshare/
├── admin ui/
│   ├── admin/
│   │   └── admin.html # Separate administrator dashboard
│   ├── member.html   # Separate member dashboard
│   ├── dashboard.css  # Shared dashboard styling and responsive layouts
│   └── dashboard.js   # Shared role-dashboard behavior and local persistence
└── README.md    # Setup notes and prototype limitations
```

## Run locally

Open `admin ui/admin/admin.html` for the administrator dashboard or `admin ui/member.html` for the member dashboard in a modern browser. These are the only application dashboards. No build step is required. The dashboards load Bootstrap 5 from jsDelivr; Google Fonts are optional, with system font fallbacks.

The community dashboard is available to administrators, organization officers, and custodians for borrowing approvals and return tracking. Administrators also manage equipment and member roles. The member dashboard focuses on available equipment, borrowing requests, and personal request history. Both dashboards share equipment and borrowing data; their demo sessions are stored separately. Each dashboard links directly to the other.

## Prototype limitations

This is a front-end demonstration, not a production authentication or authorization system. Role checks and records are stored in the browser and can be changed by the person using it. A production deployment needs a trusted API, server-side authorization, secure account registration/login, validated file uploads, and a shared database.
