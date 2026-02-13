# GEMINI.md - The Adamson Archive Theme

## Project Vision
**The Adamson Archive** is a high-performance, custom-built private media library for WordPress designed to ingest and organize 20,000+ personal media files. 

### Hybrid Storage Model
* **Photos:** Stored and served **locally** from the server filesystem.
* **Videos:** Local files act as temporary "source" files. Once uploaded to YouTube via API and a `yt_video_id` is recorded, the local source file is deleted to preserve server disk space.

## Technical Spec Sheet & System Architecture

### 1. Data Processing Logic (Scanner & Ingestion)
* **Source:** Recursive scan of `/wp-content/uploads/albums/`.
* **Parsing Logic:** Folder names follow the pattern `YYYY-MM-DD - [Album Name]`. Use Regex to extract the **Date** and **Clean Name**.
* **File Handling:** Support all common image/video formats (case-insensitive).
* **Queue System:** Must use AJAX-driven batch processing to handle the 20,000+ file scale without PHP timeouts. Progress must be reported in real-time.

### 2. Database Schema (Flexible Growth)
We will use three primary custom tables. Gemini should suggest and add columns (including proper data types and indexes) as specific features are implemented. Always use `$wpdb` and check if columns exist before creation.

* **Table: `adamson_archive_albums`**
    * **Purpose:** Stores metadata for processed folders (Display names, extracted dates, YouTube playlist associations).
* **Table: `adamson_archive_media`**
    * **Purpose:** Stores individual file data for photos (local) and videos (offloaded).
* **Table: `adamson_archive_queue`**
    * **Purpose:** Manages the background processing state and tracks error logs for failed items.

### 3. External API: YouTube Integration
* **Logic:** Check for an existing `yt_playlist_id` for the album; create it if missing. Upload videos to that playlist and record the `yt_video_id` back to the media table.

### 4. Admin UI & Frontend Behavior
* **Dashboard:** Custom WP Admin page with a "Scan" button and a real-time progress bar.
* **Album List:** Lazy-loaded list (10 items) with "Load More" AJAX pagination.
* **Interaction:** Accordion-style rows that fetch and display media via AJAX only when expanded.

## Project Context
- **Tech Stack:** Vanilla PHP, HTML5, CSS3, jQuery (No modern frameworks).
- **Location:** `/wp-content/themes/theadamsonarchive/`

## PHP & Backend Rules
- **Includes & Requires:** All php includes and requires should go at the top of the file 
- **Batching:** Use iterative processing or Action Scheduler for all heavy filesystem/API tasks.
- **Security:** Strict use of `$wpdb->prepare()` and `check_admin_referer()`.
- **YouTube API:** Use official Google Client Library. Assume credentials are in `wp-config.php`.

## JavaScript & jQuery Rules
- **Syntax:** Always use the `$` alias.
- **Implementation:** Always wrap in `jQuery(document).ready(function($) { ... });`.

## CSS Formatting & Structure (Strict)
- **Hierarchy:** Indent selectors with one **Tab** per level of nesting to mirror HTML structure.
- **Ordering:** All properties within a declaration block must be listed **alphabetically**.
- **Spacing:** Exactly **one** empty line between rules; **two** empty lines between major layout sections.
- **No Inline Styles:** Never use the `style=""` attribute in HTML.