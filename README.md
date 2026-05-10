# scorecan.com — St. Peter's Cricket Carnival 2026

6-a-side tennis-ball cricket scoring site.

**Stack:** PHP 8 + MySQL · GoDaddy cPanel · GitHub auto-deploy
**Local dev:** XAMPP — `http://localhost/scorecan-site/public/`
**Production:** scorecan.com (cPanel pulls from `tinkeringtechdev/scorecan-site`)

See **SETUP.md** for first-time setup (local + production).
See **docs/build-guide.md** for the original phased build plan.

## Project layout

```
scorecan-site/
├── public/              ← web root (Document Root points here)
│   ├── index.php  standings.php  fixtures.php  results.php  knockouts.php
│   ├── admin/  (login, dashboard, teams, fixtures, match, knockouts, export)
│   └── assets/ (style.css, app.js)
├── src/                 ← shared PHP, never browsed
│   ├── Db.php  Auth.php  Standings.php  Fixtures.php  Export.php
├── config.example.php   ← copy to config.php and edit
├── schema.sql
├── composer.json
├── .htaccess
└── .cpanel.yml          ← cron deploy script
```
