# Help page screenshots

The pictures in the Help page (`admin/help.php`) live in `assets/images/help/` as a `-light.webp` /
`-dark.webp` pair per page. They are taken from made-up data, never from the real database.

## Retaking them

```
node tools/help-screenshots/capture.mjs                  # all pages, both themes
node tools/help-screenshots/capture.mjs --only lookup,reports --themes light
```

Needs PHP, MySQL/MariaDB (root, no password, like the tests) and Chrome. Set `PHP` / `CHROME` if they are not in
the usual XAMPP / Program Files places. The script:

1. fills a **separate** database `hge_help_shots` (`seed.php`: fictional schools, users, graduates, applications,
   recycle bin, activity log, bulk-update review items),
2. serves the app against it with `php -S 127.0.0.1:8099` (`login.php` signs a sample user in; it refuses to run
   under Apache or against any other database),
3. photographs each page in headless Chrome, and
4. stops the server and drops `hge_help_shots`.

`hge_masterlist` is not touched. Roles: the dashboard, look-up and reports shots are taken as a plain user (so
the menu shows only what a plain user has), everything else as the sample administrator.

## Adding a page

Add a row to `shots` in `capture.mjs`, run it with `--only <name>`, then reference the file name (without
`-light.webp`) in `$helpFigures` in `admin/help.php`.
