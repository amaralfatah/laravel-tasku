---
paths:
  - 'api/**'
---

# Api

## Keep public/index.php out of the Vercel static output
`vercel.json` sets `outputDirectory: "public"`, so everything left in `public/` is served as a plain file, and Vercel runs the filesystem check *before* `rewrites`. A `public/index.php` therefore wins over the rewrite to `/api/index.php` and the browser downloads the PHP source (`Content-Type: application/x-httpd-php`) instead of reaching Laravel.

It is excluded in `.vercelignore` for that reason, which also keeps it out of the function bundle — so `api/index.php` boots Laravel directly from `vendor/autoload.php` + `bootstrap/app.php` rather than requiring it. Do not "restore" that require, and do not drop the `.vercelignore` entry.
