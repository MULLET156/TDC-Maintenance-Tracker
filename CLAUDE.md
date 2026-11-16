# For agents working on the TDC Maintenance Tracker

This app is part of Toledo Dental Care's office apps and shares one look with the Lab Tracker, Supply Room, Maintenance Tracker and the TDC Office suite.

## Design rules (read first)

- **Follow the shared design:** https://github.com/MULLET156/TDC-Office-Plan/blob/main/DESIGN.md, and compare your pages with the pictures in https://github.com/MULLET156/TDC-Office-Plan/tree/main/brand/reference
- This app's accent colour is **#0E6B73 teal**. It is the only colour that differs from the other two apps. Base colours, status colours, fonts, header layout, status tiles, rows (solid status stripe, progress bars), search, Group by and the phone layout (bottom tab bar with More) must stay the same as in the other two apps.
- Don't restyle on your own. A design change goes through the **Shared Design** agent or Ryan, and is then made in all three apps together.
- New screens reuse the existing pieces in this file (tiles, banner, search bar, Group by, rows, chips, progress bars, header buttons colour-coded by kind). Check them at 1400, 1100, 1000, 768 and 390px wide, in light and dark.

## Working on this repo

- The real site is https://lightseagreen-chinchilla-404358.hostingersite.com. **Merging to `main` deploys it live**, and staff use it during clinic hours (Mon–Wed 8–5, Thu 8–2).
- Work on a branch and open a pull request. Pull the latest `main` and check open PRs first so newer work isn't overwritten.
- Test locally before a PR: copy `config.sample.php` to `config.php`, set `DB_DRIVER` to `sqlite`, run `php -S 127.0.0.1:8000` in this folder, and use made-up data only.
- No patient data, passwords or keys in code, commits, issues, PRs or screenshots.
- Write for Ryan: plain language, with a short "How to test" section in each PR.
