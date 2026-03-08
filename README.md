# TYPO3 to Confluence Exporter

A Laravel console application that connects to a TYPO3 MySQL database, reads all pages and file attachments, and exports them as a Confluence-compatible ZIP file. The export can be imported into tools that support the Confluence export format, such as the Intravox Nextcloud extension.

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
| `--space-key` | `INTRANET` | Confluence space key |
| `--space-name` | `Intranet` | Confluence space name |
| `--root-pid` | `0` | TYPO3 root page UID to start the export from (0 = all pages) |
| `--include-hidden` | `false` | Include hidden TYPO3 pages in the export |

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
- **Content** - All `tt_content` records for each page, assembled into HTML with headers and body text
- **Attachments** - Files referenced via `sys_file_reference` from both `tt_content` and `pages` records

## Output Format

The command produces a `confluence-export.zip` containing:

- `entities.xml` - Confluence Hibernate-style XML with Space, Page, BodyContent, and Attachment objects
- `attachments/` - Directory with the actual attachment files, organized by page and attachment ID

## TYPO3 Tables Used

| Table | Purpose |
|---|---|
| `pages` | Page tree structure and metadata |
| `tt_content` | Content elements (headers, body text) |
| `sys_file_reference` | Links between content/pages and files |
| `sys_file` | File metadata (name, size, MIME type) |
| `sys_file_storage` | Storage configuration (used to resolve file paths) |
