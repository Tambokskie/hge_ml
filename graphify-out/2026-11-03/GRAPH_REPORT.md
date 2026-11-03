# Graph Report - hge_masterlist  (2026-10-23)

## Corpus Check
- 268 files · ~303,707 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 18 file(s) not represented in the graph (top: (none) 14, .css 2, .xml 1)

## Summary
- 1651 nodes · 3829 edges · 152 communities (47 shown, 105 thin omitted)
- Extraction: 76% EXTRACTED · 24% INFERRED · 0% AMBIGUOUS · INFERRED: 913 edges (avg confidence: 0.85)
- Token cost: 254,806 input · 13,111 output

## Community Hubs (Navigation)
- Extraction Review Workflow
- Upload Record Management
- Bulk HGE Update Parsing
- Gemini AI Extractor
- School Management CRUD
- Auth & Login
- OCR Keywords & Recycle Bin
- Generic Text Extractor
- Template Extractor & XLSX Writer
- Graduate Search & Applications
- OCR Image Extractor
- Bulk HGE Review Queue
- Review Import & Template Upload
- Duplicate Detection
- Dashboard Utils (JS)
- Legacy XLS Reader
- PDF File Validation
- Shared Helper Functions
- PHPUnit Test Suite Base
- Test Setup Boilerplate
- Activity Log
- Reports Chart (JS)
- Lookup Page (JS)
- Application Filing Report
- Dashboard & Reports Pages
- Help Screenshot Capture Tool
- Admin Tools Bulk UI (JS)
- Help Page Navigation (JS)
- App Settings (Gemini Keys)
- Dashboard Stats Queries
- Honor Row Parsing Trait
- CSV Reader
- Composer Config
- Save Staged Records
- View Helper Functions
- Help Page Rendering
- School Records Page (JS)
- Database Connection
- Review Page (JS)
- Upload Page (JS)
- Extract Pdf
- Stagedduplicatechecktest
- View Upload
- Dashboard
- Package
- Modal
- Pdf Drawer
- Recycle Bin
- Activity Log
- Idle Timeout
- Recycle Bin
- Users
- Seedadmintest
- Sidebarnavtest
- Duplicates
- Ocr Tools
- Claude
- Bulkhgereviewtest
- Csclogo
- Dashboard Dark
- Lookup Light
- Claude
- Claude
- Claude
- 2026 10 06 Chunked Ai Extraction Design
- Recycle Bin Light
- Reports Dark
- Review Dark
- School Records Dark
- Upload Pdf Dark
- View Upload Dark

## God Nodes (most connected - your core abstractions)
1. `GenericExtractorTest` - 49 edges
2. `GeminiExtractorTest` - 41 edges
3. `GraduateSearchTest` - 41 edges
4. `TestDb` - 34 edges
5. `FunctionsTest` - 33 edges
6. `GeminiExtractor` - 32 edges
7. `BulkHgeReviewTest` - 32 edges
8. `SchoolsTest` - 31 edges
9. `get_extraction_review()` - 28 edges
10. `create_school()` - 28 edges

## Surprising Connections (you probably didn't know these)
- `{closure#1}()` --calls--> `format_school_display_name()`  [INFERRED]
  admin/dashboard.php → includes/functions.php
- `{closure#1}()` --calls--> `asset_url()`  [INFERRED]
  admin/help.php → includes/functions.php
- `help_admin_badge()` --calls--> `e()`  [INFERRED]
  admin/help.php → includes/functions.php
- `{closure#1}()` --calls--> `format_school_display_name()`  [INFERRED]
  admin/reports.php → includes/functions.php
- `{closure#1}()` --calls--> `format_school_display_name()`  [INFERRED]
  api/export_application_filing_report.php → includes/functions.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **School Management CRUD Flow** — admin_schools, assets_js_schools, api_create_school, api_update_school, api_remove_school [EXTRACTED 1.00]

## Communities (152 total, 105 thin omitted)

### Community 0 - "Extraction Review Workflow"
Cohesion: 0.05
Nodes (22): append_extraction_draft_rows(), clear_extraction_review(), count_saved_graduates(), delete_extraction_draft(), derive_fallback_year(), get_extraction_draft(), get_extraction_review(), mass_update_staged_graduation_date() (+14 more)

### Community 1 - "Upload Record Management"
Cohesion: 0.05
Nodes (20): change_upload_campus(), create_upload_record(), find_existing_upload(), get_distinct_upload_school_years(), get_other_campuses_for_upload(), get_recent_uploads(), get_upload_action_summary(), handle_upload_request() (+12 more)

### Community 2 - "Bulk HGE Update Parsing"
Cohesion: 0.06
Nodes (20): bulk_hge_apply(), bulk_hge_build_preview(), bulk_hge_candidate_level(), bulk_hge_clean(), bulk_hge_collapse_duplicates(), bulk_hge_fold(), bulk_hge_header_aliases(), bulk_hge_insert_pairs() (+12 more)

### Community 3 - "Gemini AI Extractor"
Cohesion: 0.06
Nodes (4): GeminiExtractor, RetryableGeminiApiException, GeminiExtractorTest, GeminiExtractorTimeoutTest

### Community 4 - "School Management CRUD"
Cohesion: 0.06
Nodes (22): enforceUppercase(), openConfirmModal(), openCreateModal(), openEditModal(), openRemoveConfirmModal(), postJson(), reload(), get_distinct_graduation_years() (+14 more)

### Community 5 - "Auth & Login"
Cohesion: 0.07
Nodes (20): attempt_login(), csrf_token(), csrf_verify(), current_user(), fetch_current_role_and_status(), login_user(), logout_user(), require_login() (+12 more)

### Community 6 - "OCR Keywords & Recycle Bin"
Cohesion: 0.08
Nodes (19): add_ocr_course_keyword(), get_ocr_course_keywords(), remove_ocr_course_keyword(), archive_item(), {closure#1}(), get_recycle_bin_combined(), purge_recycle_item(), recycle_bin_dir() (+11 more)

### Community 7 - "Generic Text Extractor"
Cohesion: 0.06
Nodes (3): ExtractorInterface, GenericExtractor, GenericExtractorTest

### Community 8 - "Template Extractor & XLSX Writer"
Cohesion: 0.11
Nodes (24): TemplateExtractor, handle_template_upload_request(), xlsx_column_index(), xlsx_read_first_sheet(), xlsx_read_shared_strings(), xlsx_read_sheet_rows(), xlsx_strip_namespaces(), build_bulk_hge_review_xlsx() (+16 more)

### Community 9 - "Graduate Search & Applications"
Cohesion: 0.10
Nodes (12): duplicate_group_key(), approve_application(), build_search_log_details(), bulk_update_graduation_date(), file_application(), find_saved_duplicates_by_name(), get_graduates_for_upload(), get_graduation_years_for_school() (+4 more)

### Community 10 - "OCR Image Extractor"
Cohesion: 0.06
Nodes (11): OcrExtractor, ParsesHonorRows (Trait), check_ocr_tools_installed(), is_ocr_tool_working(), resolve_ghostscript_path(), resolve_tesseract_path(), count_pdf_pages(), extract_pdf_page_range() (+3 more)

### Community 11 - "Bulk HGE Review Queue"
Cohesion: 0.17
Nodes (15): bulk_hge_apply_with_review(), bulk_hge_person_display(), bulk_hge_person_key(), bulk_hge_review_applied_dates(), bulk_hge_review_approve(), bulk_hge_review_classify(), bulk_hge_review_close_settled(), bulk_hge_review_dismiss() (+7 more)

### Community 12 - "Review Import & Template Upload"
Cohesion: 0.10
Nodes (13): append_staged_rows(), parse_review_import_rows(), review_import_clean(), review_import_header_aliases(), normalize_template_honor_value(), normalize_template_label(), parse_latin_honor_template(), parse_template_date_value() (+5 more)

### Community 13 - "Duplicate Detection"
Cohesion: 0.12
Nodes (11): count_duplicates_for_upload(), duplicate_exact_copy_key(), fetch_duplicate_candidates(), find_duplicate_groups(), flag_duplicates_for_rows(), group_duplicates(), normalize_duplicate_text(), remove_duplicate_graduates() (+3 more)

### Community 14 - "Dashboard Utils (JS)"
Cohesion: 0.08
Nodes (25): actionIcon(), animateCountUp(), step(), escapeHtml(), formatCount(), initFilingDonut(), animateRate(), step() (+17 more)

### Community 15 - "Legacy XLS Reader"
Cohesion: 0.12
Nodes (13): {closure#2}(), xls_decode_rk(), xls_next_record(), xls_number_to_string(), xls_parse_sst(), xls_read_biff_string(), xls_read_first_sheet(), xls_read_workbook_stream() (+5 more)

### Community 16 - "PDF File Validation"
Cohesion: 0.10
Nodes (9): build_stored_filename(), is_image_filename(), mime_type_for_filename(), sanitize_for_filename(), upload_dup_tag(), validate_pdf_file_content(), validate_pdf_upload(), FilenameHelpersTest (+1 more)

### Community 17 - "Shared Helper Functions"
Cohesion: 0.10
Nodes (7): days_since(), normalize_name_for_matching(), paginate(), pending_upload_age_class(), upload_status_pill(), validate_removal_reason(), FunctionsTest

### Community 18 - "PHPUnit Test Suite Base"
Cohesion: 0.13
Nodes (3): CreateUploadRecordTest, SmokeTest, TestDb

### Community 20 - "Activity Log"
Cohesion: 0.20
Nodes (5): activity_icon_path(), get_recent_activity(), resolve_legacy_graduate_details(), search_activity_log(), ActivityLogSearchTest

### Community 21 - "Reports Chart (JS)"
Cohesion: 0.16
Nodes (19): animateRate(), buildTrendHtml(), escapeHtmlLocal(), formatUpdatedAt(), loadReportRange(), niceAxisMax(), niceStep(), parseDisplayedNumber() (+11 more)

### Community 22 - "Lookup Page (JS)"
Cohesion: 0.24
Nodes (19): buildQueryString(), buildSchoolOptionsHtml(), currentPageFromUrl(), currentParams(), formatDisplayDate(), formatSchoolDisplayName(), loadYearsForSchool(), openApplyModal() (+11 more)

### Community 23 - "Application Filing Report"
Cohesion: 0.19
Nodes (5): get_application_filing_report(), get_applied_breakdown_by_filed_year(), get_filing_trend_monthly(), get_honor_tier_breakdown_for_year(), ReportsTest

### Community 24 - "Dashboard & Reports Pages"
Cohesion: 0.10
Nodes (7): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), format_school_display_name()

### Community 25 - "Help Screenshot Capture Tool"
Cohesion: 0.12
Nodes (13): args, CHROME, cleanup(), here, main(), OUT, PHP, repo (+5 more)

### Community 26 - "Admin Tools Bulk UI (JS)"
Cohesion: 0.24
Nodes (17): bulkReset(), bulkReviewCandidateHtml(), bulkTile(), confirmBulkApply(), confirmBulkReviewDismiss(), confirmBulkReviewFile(), formatCount(), loadBulkReview() (+9 more)

### Community 27 - "Help Page Navigation (JS)"
Cohesion: 0.18
Nodes (15): announce(), chapterOf(), clearSearch(), escapeRegExp(), flash(), highlighted(), renderSeen(), route() (+7 more)

### Community 28 - "App Settings (Gemini Keys)"
Cohesion: 0.23
Nodes (5): gemini_key_display_status(), get_app_setting(), resolve_gemini_api_keys(), set_app_setting(), AppSettingsTest

### Community 29 - "Dashboard Stats Queries"
Cohesion: 0.18
Nodes (7): get_application_years(), get_applied_count_by_year(), get_dashboard_stats(), get_graduate_count_by_year(), get_honor_type_breakdown(), get_upload_activity_by_month(), FlashAndStatsTest

### Community 32 - "Composer Config"
Cohesion: 0.12
Nodes (15): autoload-dev, psr-4, description, name, Tests\\, require, require-dev, phpunit/phpunit (+7 more)

### Community 34 - "View Helper Functions"
Cohesion: 0.14
Nodes (6): ai_availability_tier(), asset_url(), flash_get(), flash_set(), format_date_display(), redirect()

### Community 35 - "Help Page Rendering"
Cohesion: 0.27
Nodes (10): {closure#1}(), help_admin_badge(), help_icon(), help_render_callouts(), help_render_figures(), help_render_steps(), help_split_step(), help_text() (+2 more)

### Community 36 - "School Records Page (JS)"
Cohesion: 0.33
Nodes (11): bindDeleteButtons(), buildQueryString(), currentPageFromUrl(), currentParams(), formatSchoolDisplayName(), formatShortDate(), formatUploadedAt(), performSearch() (+3 more)

### Community 37 - "Database Connection"
Cohesion: 0.29
Nodes (4): connect_pdo(), get_db_config(), get_pdo(), DatabaseTest

### Community 38 - "Review Page (JS)"
Cohesion: 0.38
Nodes (9): allowNavigation(), duplicateGroupHtml(), importSkippedHtml(), openDuplicatesModal(), openEditModal(), openSaveConfirm(), postJson(), reload() (+1 more)

### Community 39 - "Upload Page (JS)"
Cohesion: 0.38
Nodes (8): extractAndRedirect(), handleUploadResponse(), hideProgress(), setDeterminateProgress(), setIndeterminateProgress(), setProgressLabel(), showProgress(), submitForm()

### Community 40 - "Extract Pdf"
Cohesion: 0.27
Nodes (5): {closure#3}(), clear_extraction_progress(), extraction_progress_file(), read_extraction_progress(), write_extraction_progress()

### Community 42 - "View Upload"
Cohesion: 0.42
Nodes (8): clearBusy(), extract(), handleExtractClick(), hideExtractionError(), isReextraction(), reextractionBodyHtml(), setBusy(), showExtractionError()

### Community 44 - "Package"
Cohesion: 0.25
Nodes (7): devDependencies, tailwindcss, name, private, scripts, build:css, tailwindcss

### Community 45 - "Modal"
Cohesion: 0.43
Nodes (5): showExtractionProgressModal(), showLoadingModal(), showModal(), close(), onKeydown()

### Community 46 - "Pdf Drawer"
Cohesion: 0.52
Nodes (6): closePdfDrawer(), onPdfDrawerKeydown(), openPdfDrawer(), pdfDrawerDetailRow(), renderPdfDrawerDetails(), setPdfDrawerTab()

### Community 47 - "Recycle Bin"
Cohesion: 0.43
Nodes (4): archive_graduate(), get_recycle_bin_items(), purge_recycle_bin_item(), restore_graduate()

### Community 48 - "Activity Log"
Cohesion: 0.53
Nodes (4): buildQueryString(), formatTimestamp(), performSearch(), renderResults()

### Community 49 - "Idle Timeout"
Cohesion: 0.67
Nodes (5): clearTimers(), goToLogout(), keepAlive(), resetTimers(), showWarning()

### Community 50 - "Recycle Bin"
Cohesion: 0.53
Nodes (3): checkboxes(), refresh(), selectedIds()

### Community 51 - "Users"
Cohesion: 0.73
Nodes (5): openCreateModal(), openEditModal(), openRemoveConfirmModal(), postJson(), reload()

### Community 56 - "Claude"
Cohesion: 0.50
Nodes (4): Generic Extractor, OCR Extractor, CI Test Workflow, ParsesHonorRows Trait

## Knowledge Gaps
- **17 isolated node(s):** `tailwindcss`, `HGE Database Project`, `HGE Architecture`, `Generic Extractor`, `Database Schema` (+12 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 268 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **105 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `GeminiExtractorTest` connect `Gemini AI Extractor` to `PHPUnit Test Suite Base`?**
  _High betweenness centrality (0.047) - this node is a cross-community bridge._
- **What connects `tailwindcss`, `HGE Database Project`, `HGE Architecture` to the rest of the system?**
  _17 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Extraction Review Workflow` be split into smaller, more focused modules?**
  _Cohesion score 0.05350877192982456 - nodes in this community are weakly interconnected._
- **Why does `FunctionsTest` connect `Shared Helper Functions` to `Dashboard & Reports Pages`, `PHPUnit Test Suite Base`, `Help Page Rendering`, `View Helper Functions`?**
  _High betweenness centrality (0.042) - this node is a cross-community bridge._
- **Should `Upload Record Management` be split into smaller, more focused modules?**
  _Cohesion score 0.051490514905149054 - nodes in this community are weakly interconnected._
- **Why does `GenericExtractorTest` connect `Generic Text Extractor` to `PHPUnit Test Suite Base`?**
  _High betweenness centrality (0.038) - this node is a cross-community bridge._
- **Should `Bulk HGE Update Parsing` be split into smaller, more focused modules?**
  _Cohesion score 0.05617283950617284 - nodes in this community are weakly interconnected._