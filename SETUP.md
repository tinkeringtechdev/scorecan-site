# scorecan.com — setup & deploy

## A. Local development on XAMPP (macOS)

The repo lives at `/Applications/XAMPP/xamppfiles/htdocs/scorecan-site/`.
Local URL: **http://localhost/scorecan-site/** (the root `.htaccess` redirects into `/public/`).

### A1. Start XAMPP

Open the XAMPP control panel and start **Apache** and **MySQL** (or MariaDB).

### A2. Create the database

Open phpMyAdmin: <http://localhost/phpmyadmin>

1. **Databases** tab → New → name **`scorecan_db`** → utf8mb4_unicode_ci → Create.
2. Select `scorecan_db` → **Import** → upload **`schema.sql`** → Go.

This creates all tables, seeds one tournament, 16 placeholder teams, and an `admin` user.

### A3. Create your local config

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/scorecan-site
cp config.example.php config.php
```

Default values already match XAMPP (`root` / no password). Edit if your setup differs.

### A4. (Optional) Install Composer dependencies for full Excel export

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/scorecan-site
composer install
```

If you don't have Composer locally, the Excel button will fall back to a CSV export — no breakage.

### A5. Visit the site

- Public: <http://localhost/scorecan-site/>
- Admin: <http://localhost/scorecan-site/public/admin/>
- Default login: **admin / changeme** — change it immediately by inserting a new bcrypt hash via phpMyAdmin (or build a "change password" page later).

---

## B. Production deploy via cPanel + GitHub

The cPanel Git Version Control feature pulls the repo on every cron tick. The
`.cpanel.yml` in this repo runs after each pull and:

1. Mirrors `/public/*` into `~/public_html/`
2. Mirrors `/src/` into `~/public_html/_lib/src/` (HTTP-denied via `.htaccess`)
3. Copies `schema.sql` to `~/public_html/_lib/schema.sql`
4. Runs `composer install` if Composer is on PATH

### B1. One-time cPanel setup

1. **MySQL Databases** → create `..._scorecan_db` and a user with All Privileges.
2. **Git Version Control** → Create → clone URL `https://github.com/tinkeringtechdev/scorecan-site.git` → repo path `/home/$USER/repositories/scorecan-site`.
   *(Private repo: use a Personal Access Token in the URL.)*
3. **File Manager** → in `~/public_html/_lib/`, create **`config.php`** (paste the contents of `config.example.php` and put real DB creds). This file is **not** wiped by deploys.
   - Do the same in `~/public_html/config.php` (the bootstrap also looks here).
4. **phpMyAdmin** → import `~/public_html/_lib/schema.sql` into the database you created.
5. The cron job already pulls every 5 minutes; first push triggers full deploy.

### B2. Verify

- Visit https://scorecan.com → should show standings (empty tables initially).
- Visit https://scorecan.com/admin/ → log in.
- Generate fixtures, enter a test match, watch the standings update.

---

## C. Verification checklist (run after each phase)

- [ ] **Phase 1** — Pushing a change to `main` is reflected at scorecan.com within 5 min.
- [ ] **Phase 2** — Insert one test match by editing it in `/admin/match.php`. Standings tally is correct.
- [ ] **Phase 3** — Re-enter at least 4 matches from last year's score sheet via the admin UI; verify NRR matches the source spreadsheet to 3 decimal places.
- [ ] **Phase 4** — Click Export → open the .xlsx → confirm Points Table, Results2026, Teams sheets are populated.
- [ ] **Tournament-day rehearsal** — enter every match from a previous year. Per-match entry should take **under 90 seconds**.

## D. Common gotchas

- **"src/ not found"** — config.php missing or in the wrong place. Bootstrap looks at `/config.php` (root) and `/public/config.php` and `/public/_lib/config.php`.
- **NRR shows 0.000 for every team** — at least one match must have `status = 'complete'` to contribute. Check `matches.status`.
- **"All out" not affecting NRR** — make sure the form's `home_all_out` / `away_all_out` checkbox is checked. Behind the scenes the calc swaps in `overs_per_side * 6` for that side's `balls_faced`.
- **Auto-deploy didn't pull** — cPanel → Git Version Control → Manage → "Pull or Deploy" can be triggered manually.
- **Pretty URLs** — not used (every file has `.php`). Drop a `RewriteRule` in `/public/.htaccess` if needed.

## E. What's NOT built (deferred features)

- Concurrent admin editing (last-save-wins is fine for one scorer).
- Live ball-by-ball scoring (admin enters final score sheet at match end).
- Player stats (top batsman/bowler) — Phase 5 if requested.
- Sponsor logos, photos — out of scope for now.
- Native mobile app — responsive web is enough.
