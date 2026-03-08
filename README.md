# TYPO3 to Confluence Exporter

A Laravel console application that connects to a TYPO3 MySQL database, reads all pages and file attachments, and exports them as a Confluence HTML export ZIP file. The export matches the format produced by Confluence's "Space Tools > Content Tools > Export" (HTML export) and can be imported into tools like the Intravox Nextcloud extension.

## Requirements

- PHP 8.2+
- Composer
- Access to the TYPO3 MySQL database
- Access to the TYPO3 `fileadmin/` directory (for attachments)

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Configuration

Edit your `.env` file with the TYPO3 database credentials:

```env
TYPO3_DB_HOST=127.0.0.1
TYPO3_DB_PORT=3306
TYPO3_DB_DATABASE=typo3
TYPO3_DB_USERNAME=root
TYPO3_DB_PASSWORD=

# Absolute path to the TYPO3 fileadmin directory
TYPO3_FILEADMIN_PATH=/var/www/html/fileadmin
```

## Usage

```bash
php artisan app:export-typo3-to-confluence
```

### Options

| Option | Default | Description |
|---|---|---|
| `--output` | `storage/app` | Directory where the ZIP file will be saved |
| `--fileadmin` | Value from `TYPO3_FILEADMIN_PATH` | Path to the TYPO3 `fileadmin/` directory |
| `--space-name` | `Intranet` | Name shown in the export header |
| `--root-pid` | `0` | TYPO3 root page UID to start the export from (0 = all pages) |
| `--include-hidden` | `false` | Also export hidden TYPO3 pages |

### Examples

Export all visible pages:

```bash
php artisan app:export-typo3-to-confluence --fileadmin=/var/www/html/fileadmin
```

Export a specific page tree starting from page UID 1:

```bash
php artisan app:export-typo3-to-confluence --root-pid=1 --fileadmin=/var/www/html/fileadmin
```

Export including hidden pages to a custom directory:

```bash
php artisan app:export-typo3-to-confluence --include-hidden --output=/tmp/export
```

## What Gets Exported

- **Pages** - The full page tree hierarchy from the TYPO3 `pages` table (excluding system pages like folders, recycler, etc.)
- **Content** - All `tt_content` records per page, assembled into HTML with headers and body text
- **Attachments** - Files referenced via `sys_file_reference` from both `tt_content` and `pages` records

## Output Format

The command produces a `confluence-export.zip` matching the Confluence HTML export format:

```
confluence-export.zip
├── index.html              # Page tree / table of contents
├── page-title.html         # Individual page HTML files
├── another-page.html
├── attachments/
│   └── page-title/
│       ├── document.pdf    # Attachment files per page
│       └── image.png
└── styles/
    └── site.css
```

Each page is a standalone HTML file with:
- Breadcrumb navigation
- Page content converted from TYPO3 `tt_content`
- Attachment list with links to files

## TYPO3 Tables Used

| Table | Purpose |
|---|---|
| `pages` | Page tree structure and metadata |
| `tt_content` | Content elements (headers, body text) |
| `sys_file_reference` | Links between content/pages and files |
| `sys_file` | File metadata (name, size, MIME type) |
| `sys_file_storage` | Storage configuration (used to resolve file paths) |
