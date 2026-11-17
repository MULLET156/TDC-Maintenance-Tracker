# For agents working on the TDC Maintenance Tracker

This app is part of Toledo Dental Care's office apps and shares one look with the Lab Tracker, Supply Room, Maintenance Tracker and the TDC Office suite.

## Design rules (read first)

- **Follow the shared design:** https://github.com/MULLET156/TDC-Office-Plan/blob/main/DESIGN.md, and compare your pages with the pictures in https://github.com/MULLET156/TDC-Office-Plan/tree/main/brand/reference
- This app's accent colour is **#0E6B73 teal**. It is the only colour that differs from the other two apps. Base colours, status colours, fonts, header layout, status tiles, rows (solid status stripe, progress bars), search, Group by and the phone layout (bottom tab bar with More) must stay the same as in the other two apps.
- Don't restyle on your own. A design change goes through the **Shared Design** agent or Ryan, and is then made in all three apps together.
- `tdc-shared.css`, `tdc-shared.js` and `fonts/` are copies from TDC-Office-Plan `brand/` (DESIGN.md §9). **Never edit them here**; the Design check on each PR fails if they differ. Group headings use `tdc-gh`, toasts `tdc-toast`, dialogs `tdc-scrim`/`tdc-dialog`. Never load fonts from an outside site.
- Design for iPhones, iPads, Android phones and Android tablets (DESIGN.md §10): 44px targets on touch screens, 16px text fields, nothing under the notch or home bar. Also check 820 and 1180px wide with touch on.
- New screens reuse the existing pieces in this file (tiles, banner, search bar, Group by, rows, chips, progress bars, header buttons colour-coded by kind). Check them at 1400, 1100, 1000, 768 and 390px wide, in light and dark.

## Working on this repo

- The real site is https://lightseagreen-chinchilla-404358.hostingersite.com. **Merging to `main` deploys it live**, and staff use it during clinic hours (Mon–Wed 8–5, Thu 8–2).
- Work on a branch and open a pull request. Pull the latest `main` and check open PRs first so newer work isn't overwritten.
- **Merge as you go** (Ryan, 2026-11-16): merge your own PR once your checks pass, then open the live site right away and check what you changed; if anything broke, revert the PR at once. Contract changes still need Ryan's OK first.
- Test locally before a PR: copy `config.sample.php` to `config.php`, set `DB_DRIVER` to `sqlite`, run `php -S 127.0.0.1:8000` in this folder, and use made-up data only.
- No patient data, passwords or keys in code, commits, issues, PRs or screenshots.
- Write for Ryan: plain language, with a short "How to test" section in each PR.
