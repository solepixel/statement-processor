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
  - **Supported statement types**: Discover (with optional AI parsing), Capital One, PayPal Credit, Ally (with optional AI parsing), and generic table/line layouts.
- **AI parsing**: Optional use of an LLM (OpenAI) to extract transactions from **Discover** and **Ally Bank** statement PDFs. When enabled in **Settings → Statement Processor**, the plugin sends extracted PDF text to the configured provider and receives a structured list of transactions (date, description, amount). For Ally, AI is tried first and the plugin falls back to text-based parsing if AI is off or returns nothing.
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
- **PDF text extraction**: The plugin uses `pdftotext -layout` (poppler-utils) when available so columnar statement layouts parse correctly; otherwise it uses the Composer dependency **smalot/pdfparser** (pure PHP, no server binary required). So you can run without any system PDF tools; `smalot/pdfparser` is the in-process fallback.
- **OCR** (scanned/image-only PDFs): Optional Tesseract OCR and Imagick or `pdftoppm` for PDF-to-image when the text layer is empty. For environments where you cannot install Tesseract, consider a cloud OCR API (e.g. Google Cloud Vision, AWS Textract) and integrate via a custom filter or wrapper around the plugin’s text extractor.
- **AI parsing (optional)**: For Discover and Ally statement parsing, configure an OpenAI API key in **Settings → Statement Processor**. The plugin calls the OpenAI API directly over HTTP; no separate provider package is required.

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
   For better parsing of **Discover** and **Ally Bank** statement PDFs, go to **Settings → Statement Processor**. Enable **Use AI to parse Discover (and compatible) statement PDFs**, choose **OpenAI (GPT)** as provider, enter your **API key**, and optionally set the **Model** (e.g. `gpt-4o-mini`). Save settings. Ally statements use AI first and fall back to text parsing if needed.

3. **Upload and review**  
   - Choose or enter a **Source** (e.g. “Discover”, “Chase Checking”, or “Detect Automatically”).
   - Select one or more PDF or CSV files (drag-and-drop or browse).
   - Click **Upload and review**. The button is disabled and shows “Processing…” while the server parses the file(s). For Discover PDFs with AI enabled, this may take longer.
   - On the review screen, you can edit date/description/amount, set or change the source per row, and uncheck rows to exclude from import. Click **Import selected** to create transaction posts, or **Cancel** to go back.

4. **Export**  
   In the same Import page, use the **Export to CSV** section to set year, month, and source filters, then click **Download CSV**.

Transactions appear under **Transactions** (the `sp-transaction` post type list). Duplicate detection is only against **already-imported** transactions (previous imports). Within the same upload/session, multiple rows with the same date/description/amount are all imported; each gets a unique `_transaction_id` so they are not treated as duplicates of each other.

## Supported statement formats

- **Discover**: ACCOUNT ACTIVITY with Eff. Date, Syst. Date, Description, Amount. When AI is enabled in settings, an LLM is used to extract transactions (improved descriptions and correct debit/credit sign from transaction type).
- **Capital One**: Multi-account style with DATE DESCRIPTION AMOUNT rows.
- **PayPal Credit**: CURRENT ACTIVITY with PAYMENTS & CREDITS, PURCHASES & ADJUSTMENTS, and FEES sections (Synchrony-style).
- **Ally**: Combined customer statement with Activity table (Date, Description, Credits, Debits, Balance). When AI is enabled, an LLM extracts only real transactions (skips Beginning/Ending Balance, page numbers, addresses); otherwise text-based parsing is used.
- **Generic**: Table-style and line-style date/description/amount patterns.

## PDF and OCR (no server software)

- **Text PDFs**: If `pdftotext` (poppler-utils) is not installed, the plugin uses the Composer package **smalot/pdfparser** to extract text. No server binaries are required; it runs in PHP only. Layout may differ slightly from `pdftotext -layout`.
- **Scanned/image PDFs**: The plugin can use Tesseract OCR when the native text layer is empty (requires Tesseract and Imagick or pdftoppm on the server). To avoid installing OCR software, you can use a cloud OCR API (e.g. Google Cloud Vision, AWS Textract, Azure Document Intelligence) by extending or replacing `PdfTextExtractor` to call the API and return the extracted text.

## Troubleshooting (Import returns HTML / "invalid response")

If "Import selected" shows "Server returned an invalid response" and the browser gets HTML instead of JSON:

1. **Browser console**  
   Open DevTools (F12) → Console. After reproducing the error, expand the **"Statement Processor import: debug"** group. Check:
   - **Request URL** – should be `.../wp-admin/admin-ajax.php?action=statement_processor_import_batch`.
   - **Response URL** – if it differs from the request URL, the request was redirected (e.g. to login or the Import page).
   - **Response status** – 200 with HTML usually means the wrong page was served; 302 means a redirect.
   - **Response preview** – confirms whether the body is HTML or JSON.

2. **Server debug log (optional)**  
   In `wp-config.php`, enable:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```
   Reproduce the import, then check `wp-content/debug.log`:
   - **`admin-ajax request: action=statement_processor_import_batch`** – WordPress received the action.
   - **`ajax_import_batch entered`** – the batch handler ran.
   - **`sending JSON success`** – the handler sent a JSON response.  
   If the first line never appears, the action is not reaching WordPress (e.g. redirect before `admin-ajax.php`). If the first appears but not the second, the handler is not firing. If all appear but the browser still gets HTML, something else is sending output or redirecting after the handler.

## Uninstall

On plugin uninstall, data (posts and terms) is left in place. To remove all transaction data, delete the plugin and then remove posts of type `sp-transaction` and terms of taxonomy `sp-source` manually or via an optional `uninstall.php` script.
