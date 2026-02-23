# Statement Processor

**Contributors:** Briantics, Inc.  
**Plugin URI:** https://b7s.co  
**Requires at least:** 5.8  
**Tested up to:** 6.x  
**Requires PHP:** 7.4  
**License:** GPL v2 or later  

Upload financial transaction files (PDF and CSV) from bank and credit card statements, organize transactions by month/year and source, and export to CSV.

## Features

- **Custom post type** `sp-transaction`: each transaction is a post with publish date = transaction date, title = `{Description} {Amount}`, and meta `_transaction_id`, `_amount`, `_description`.
- **Custom taxonomy** `sp-source`: assign a source (e.g. bank or card name) per upload; filter and export by source.
- **Multiple file upload**: PDF and CSV; convention-based column/field detection with optional mapping.
- **PDF**: native text extraction (pdftotext preferred for statement layouts; fallback to smalot/pdfparser); OCR fallback (Tesseract) when text cannot be extracted. Uploaded files are processed from PHP temp and are not stored.
- **CSV export**: filter by year, month, and source; download CSV with Date, Time, Description, Amount, Source, Transaction ID.

## Installation

1. Copy the plugin into `wp-content/plugins/statement-processor` (or clone this repo into that path).
2. Run `composer install` in the plugin directory (for autoload and optional dev tools).
3. Activate the plugin in the WordPress admin.

## Requirements

- PHP 7.4+
- WordPress 5.8+
- For **PDF text extraction**: The plugin tries `pdftotext` (poppler-utils) first so columnar statement layouts parse correctly; if unavailable it uses Composer dependency `smalot/pdfparser`. Install poppler-utils for best results with credit card/bank statement PDFs.
- For **OCR** (scanned PDFs): Tesseract OCR and, for PDF-to-image, Imagick extension or `pdftoppm` (poppler-utils).

**DDEV: Installing pdftotext (poppler-utils)**  
The container’s package lists are often stale. Update first, then install:

```bash
ddev exec sudo apt-get update
ddev exec sudo apt-get install -y poppler-utils
```

If you see “Package poppler-utils has no installation candidate”, add it to the DDEV image so it is installed at build time. In your **DDEV project** (not the plugin repo), edit `.ddev/config.yaml` and add:

```yaml
webimage_extra_packages: [poppler-utils]
```

Then run `ddev restart` so the web image rebuilds with the package. After that, `pdftotext` will be available inside the container.

## Usage

1. In the admin, open **Statement Processor** in the sidebar.
2. **Upload**: Choose or enter a **Source** (e.g. "Chase Checking"), select one or more PDF or CSV files, then click **Upload and import**.
3. **Export**: Use the **Export to CSV** section to set year, month, and source filters, then click **Download CSV**.

Transactions appear under **Statement Processor → All Transactions**. Duplicate transactions (same date, description, amount) are skipped on import using `_transaction_id`.

## Development

- **PHPCS:** Install [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) (e.g. `composer require --dev wp-coding-standards/wpcs`), then run `vendor/bin/phpcs` using the included `.phpcs.xml`.
- **EditorConfig:** Use the provided `.editorconfig` for indentation and line endings.

## Uninstall

On plugin uninstall, data (posts and terms) is left in place. To remove all transaction data, delete the plugin and then remove posts of type `sp-transaction` and terms of taxonomy `sp-source` manually or via an optional `uninstall.php` script.
