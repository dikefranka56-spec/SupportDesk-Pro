SUPPORTDESK PRO — COMPLETE BEGINNER PROJECT
============================================

This is a simple PHP + MySQL customer-support ticket application.
Every project file is directly inside this one folder. No subfolders.

FILES
-----
index.php       All pages and application actions
config.php      Database connection and automatic database/table setup
functions.php   Login/session helpers, form security and shared functions
style.css       Complete website design
script.js       Small browser interactions
database.sql    Database structure for reference/manual import
README.txt      This guide

INSTALL
-------
1. Extract this ZIP.
2. Copy the folder SupportDesk-Pro into C:\xampp\htdocs\
3. Open XAMPP Control Panel.
4. Start Apache and MySQL.
5. Visit http://localhost/SupportDesk-Pro/
6. Register your company and first administrator account.

DATABASE
--------
Database name: supportdesk_pro
Host: localhost
Username: root
Password: blank (standard XAMPP default)

The app creates its database and tables automatically on first load.
If your MySQL credentials differ, edit config.php.

FEATURES
--------
- Company/workspace registration
- User login and logout
- Password hashing
- CSRF token checks on forms
- Dashboard with ticket counts
- Create support tickets
- Ticket priorities and categories
- Ticket conversation/replies
- Ticket status: Open, In Progress, Closed
- Responsive design

IMPORTANT
---------
This is a local learning/development starter, not production-ready SaaS.
Before public launch, add email verification, password reset, rate limiting,
proper agent/customer invitation flows, audit logs, backups, HTTPS, and
additional authorization tests. Do not use real customer data yet.
