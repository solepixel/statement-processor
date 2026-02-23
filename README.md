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
- **Custom taxonomy** `sp-source`: assign a source (e.g. bank or card name) per upload or per row; filter and export by source.
- **Multiple file upload**: PDF and CSV; convention-based column/field detection with optional mapping.
- **PDF parsing**:
  - **Text extraction**: Prefers `pdftotext -layout` (poppler-utils) so columnar statement layouts stay on one line; fallback to Composer dependency `smalot/pdfparser`. OCR fallback (Tesseract) when text cannot be extracted. Uploaded files are processed from PHP temp and are not stored.
  - **Supported statement types**: Discover (with optional AI parsing), Capital One, PayPal Credit, Ally-style, and generic table/line layouts.
- **AI parsing for Discover statements**: Optional use of an LLM (OpenAI) to extract transactions from Discover bank statement PDFs. When enabled in **Settings → Statement Processor**, the plugin sends extracted PDF text to the configured provider and receives a structured list of transactions (date, description, amount), improving accuracy and coverage over regex-only parsing.
- **Review before import**: After upload, you review parsed transactions (edit, set source, exclude rows), then click **Import selected**.
- **Upload feedback**: The **Upload and review** button is disabled and shows a spinner and “Processing…” while the upload is being processed (especially useful for Discover statements using AI).
- **CSV export**: Filter by year, month, and source; download CSV with Date, Time, Description, Amount, Source, Transaction ID.

## Installation

1. Copy the plugin into `wp-content/plugins/statement-processor` (or clone this repo into that path).
2. Run `composer install` in the plugin directory (for autoload and dependencies, including `wordpress/php-ai-client` and `smalot/pdfparser`).
3. Activate the plugin in the WordPress admin.

## Requirements

- PHP 7.4+
- WordPress 5.8+
- **PDF text extraction**: The plugin uses `pdftotext -layout` (poppler-utils) when available so columnar statement layouts (e.g. PayPal Credit) parse correctly; otherwise it uses the Composer dependency `smalot/pdfparser`. Install poppler-utils for best results with credit card/bank statement PDFs.
- **OCR** (scanned PDFs): Tesseract OCR and, for PDF-to-image, Imagick extension or `pdftoppm` (poppler-utils).
- **AI parsing (optional)**: For Discover statement AI parsing, configure an OpenAI API key in **Settings → Statement Processor**. The plugin calls the OpenAI API directly over HTTP; no separate provider package is required.

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

1. **Transactions → Import**  
   In the admin sidebar, go to **Transactions → Import** (the Import submenu under the Statement Processor transactions list).

2. **Configure AI (optional)**  
   For better parsing of **Discover** statement PDFs, go to **Settings → Statement Processor**. Enable **Use AI to parse Discover (and compatible) statement PDFs**, choose **OpenAI (GPT)** as provider, enter your **API key**, and optionally set the **Model** (e.g. `gpt-4o-mini`). Save settings.

3. **Upload and review**  
   - Choose or enter a **Source** (e.g. “Discover”, “Chase Checking”, or “Detect Automatically”).
   - Select one or more PDF or CSV files (drag-and-drop or browse).
   - Click **Upload and review**. The button is disabled and shows “Processing…” while the server parses the file(s). For Discover PDFs with AI enabled, this may take longer.
   - On the review screen, you can edit date/description/amount, set or change the source per row, and uncheck rows to exclude from import. Click **Import selected** to create transaction posts, or **Cancel** to go back.

4. **Export**  
   In the same Import page, use the **Export to CSV** section to set year, month, and source filters, then click **Download CSV**.

Transactions appear under **Transactions** (the `sp-transaction` post type list). Duplicate transactions (same date, description, amount) are skipped on import using `_transaction_id`.

## Supported statement formats

- **Discover**: ACCOUNT ACTIVITY with Eff. Date, Syst. Date, Description, Amount. When AI is enabled in settings, an LLM is used to extract transactions (improved descriptions and correct debit/credit sign from transaction type).
- **Capital One**: Multi-account style with DATE DESCRIPTION AMOUNT rows.
- **PayPal Credit**: CURRENT ACTIVITY with PAYMENTS & CREDITS, PURCHASES & ADJUSTMENTS, and FEES sections (Synchrony-style).
- **Ally**: Trans Date, Post Date, Reference, Description, Amount rows.
- **Generic**: Table-style and line-style date/description/amount patterns.

## Uninstall

On plugin uninstall, data (posts and terms) is left in place. To remove all transaction data, delete the plugin and then remove posts of type `sp-transaction` and terms of taxonomy `sp-source` manually or via an optional `uninstall.php` script.
