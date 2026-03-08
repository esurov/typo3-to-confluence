# TYPO3 to Confluence Exporter

A CLI tool that connects to a TYPO3 MySQL database, reads all pages and file attachments, and exports them as a Confluence HTML export ZIP file. The export matches the format produced by Confluence's "Space Tools > Content Tools > Export" (HTML export) and can be imported into tools like the Intravox Nextcloud extension.

## Quick Start (PHAR)

Copy the pre-built `typo3-to-confluence.phar` to the TYPO3 server and run:

```bash
php typo3-to-confluence.phar \
  --db-host=127.0.0.1 \
  --db-name=typo3 \
  --db-user=root \
  --db-password=secret \
  --fileadmin=/var/www/html/fileadmin \
  --output=/tmp
```

The PHAR requires PHP 8.2+ with the `pdo_mysql` and `zlib` extensions.

Alternatively, create a `.env` file in the same directory as the PHAR:

```env
TYPO3_DB_HOST=127.0.0.1
TYPO3_DB_PORT=3306
TYPO3_DB_DATABASE=typo3
TYPO3_DB_USERNAME=root
TYPO3_DB_PASSWORD=secret
TYPO3_FILEADMIN_PATH=/var/www/html/fileadmin
```

Then run without flags:

```bash
php typo3-to-confluence.phar --output=/tmp
```

## Building the PHAR

```bash
composer install
composer build
```

The PHAR is written to `builds/typo3-to-confluence.phar`.

Note: building requires `phar.readonly=0` in your php.ini, or run the build step with:

```bash
php -d phar.readonly=0 vendor/bin/box compile
```

## Development Usage

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your TYPO3 database credentials, then:

```bash
php artisan app:export-typo3-to-confluence
```

## Options

| Option | Default | Description |
|---|---|---|
| `--output` | `.` (current dir) | Directory where the ZIP file will be saved |
| `--fileadmin` | Value from `TYPO3_FILEADMIN_PATH` | Path to the TYPO3 `fileadmin/` directory |
| `--space-name` | `Intranet` | Name shown in the export header |
| `--root-pid` | `0` | TYPO3 root page UID to start from (0 = all pages) |
| `--include-hidden` | `false` | Also export hidden TYPO3 pages |
| `--db-host` | from `.env` | TYPO3 database host |
| `--db-port` | `3306` | TYPO3 database port |
| `--db-name` | from `.env` | TYPO3 database name |
| `--db-user` | from `.env` | TYPO3 database username |
| `--db-password` | from `.env` | TYPO3 database password |

## What Gets Exported

- **Pages** - The full page tree from the TYPO3 `pages` table (excluding system pages)
- **Content** - All `tt_content` records per page, assembled into HTML
- **Attachments** - Files referenced via `sys_file_reference` from `tt_content` and `pages`

## Output Format

```
confluence-export.zip
├── index.html              # Page tree / table of contents
├── page-title.html         # Individual page HTML files
├── another-page.html
├── attachments/
│   └── page-title/
│       ├── document.pdf
│       └── image.png
└── styles/
    └── site.css
```

## TYPO3 Tables Used

| Table | Purpose |
|---|---|
| `pages` | Page tree structure and metadata |
| `tt_content` | Content elements (headers, body text) |
| `sys_file_reference` | Links between content/pages and files |
| `sys_file` | File metadata (name, size, MIME type) |
| `sys_file_storage` | Storage configuration (used to resolve file paths) |
