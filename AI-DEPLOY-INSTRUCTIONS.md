# Put this demo website online, read me first

You have been handed this whole folder and asked to put a demo website online. This
file tells you everything you need. It is a small, safe, **read-only** PHP app: no
database, no build step, no background services, no API keys, and it writes nothing
to disk. Deploying it is a five-minute job on ordinary shared hosting.

(Ignore `README.md` and `INSTALL.md` in this folder. Those describe the full
self-hosted product install with systemd and Tailscale, and do **not** apply to this
demo. This file is the one to follow.)

## What this is

A public demo of **D'everyman**, a self-hosted dashboard and visual pipeline builder
for AI coding agents. It is intentionally **disconnected**: it runs the complete user
interface, but every button that would do something real (start an agent, run a
pipeline, deploy, save, enter credentials) is disabled and shows a small
"disabled in this demo" message. There is nothing to secure and nothing it can touch,
so it is safe to expose publicly.

## Requirements

- **PHP 8.0 or newer.** That is the only dependency.
- A web server that runs PHP (Apache, LiteSpeed, or nginx + php-fpm, any standard
  shared host is fine).
- The ability to set the website's **document root (web root)** to the
  **`launcher/public`** folder inside this package. Most hosts let you do this when
  you add a domain or subdomain.
- No database, no cron jobs, no writable folders, no environment variables. Nothing.

## Deploy steps

1. **Upload the entire folder** to the server, keeping the structure intact (the app
   uses relative paths, so do not rearrange files). For example into `~/deveryman/`.
2. **Point the site's document root at the `launcher/public` folder**, e.g.
   `~/deveryman/launcher/public`.
   - cPanel / Plesk: create the domain or subdomain and set its "Document Root" to
     that path.
   - nginx: `root /home/USER/deveryman/launcher/public;` with the usual
     `location ~ \.php$ { … fastcgi_pass … }` block.
3. **Visit the site.** You should see a dark page titled "D'everyman" with a yellow
   "Demo" banner. That's it, you're done.

## How to verify it works

- The home page shows the Demo banner and two sample projects (Acme Store, Field
  Notes).
- Click **Pipeline templates -> DPA standard -> Open in builder**: you get an
  interactive node-graph canvas (drag the nodes, scroll to zoom, drag empty space to
  pan). This is the centrepiece, make sure it loads.
- Click any action button (for example "AI credentials", or "Save pipeline" inside the
  builder). It should show a "disabled in this demo" message. That is expected and
  correct.

## Do not change these

- **Keep the file named `.demo-mode`** at the top level of the upload. It is the
  switch that keeps the app in safe, disconnected demo mode. If it is deleted, the app
  would start asking for a login and trying to run real system commands.
- **Do not** add a config file or set any `DEVERYMAN_*` environment variables. The
  demo is meant to run with none; a stray config could make it try to read real data.
- Nothing needs write permissions. (PHP sessions are used only for form tokens; the
  default session path on shared hosts is fine.)

## If you cannot change the document root (shared hosting, fixed `public_html`)

Common case: the host serves from a fixed `public_html/` and you are dropping this
into a subfolder (e.g. `public_html/portfolio/deveryman/`) with no way to repoint the
document root. **Do not move the app's files around**, the include paths depend on the
folder layout. Instead, on Apache/LiteSpeed, add a **one-file `.htaccess`** at the top
of the uploaded app folder that internally serves everything from `launcher/public`
while the browser URL stays on your subfolder:

```apache
# .htaccess  (place it inside the uploaded app folder, next to this file)
RewriteEngine On
# Adjust this path prefix to match where the app lives under your web root.
# Example for  https://yoursite/portfolio/deveryman/  ->  /portfolio/deveryman/
RewriteCond %{REQUEST_URI} !^/portfolio/deveryman/launcher/public/
RewriteRule ^(.*)$ launcher/public/$1 [L]
```

The app's asset and link paths are all **relative**, so this works: `litegraph.css`,
`litegraph.js`, `pipeline-canvas.js`, and the page links all resolve correctly under
the subpath. (An earlier build had a couple of root-absolute `/vendor` and `/js`
references that 404'd under a subpath; those are fixed in this package.)

Verify after: open `.../pipeline-builder.php?template=dpa-standard` and confirm
`litegraph.css`, `litegraph.js`, and `pipeline-canvas.js` all return `200` and the
node canvas draws.

If you are on nginx (no `.htaccess`), either set the document root to `launcher/public`
(preferred) or add an equivalent internal rewrite in the server block.

That's everything. Enjoy.
