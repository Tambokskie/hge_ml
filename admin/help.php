<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Open to every signed-in user: the guide below only shows the sections an
// account can actually use (administrator-only sections are left out for a
// plain user).
require_login();

$currentUser = current_user();
$currentPage = 'help';
$isAdmin = user_has_role($currentUser, 'administrator');

/**
 * The guide's text is plain data so it is easy to keep in step with the app.
 * Prose is written in sentence case; [[LABEL]] marks the exact name of a button,
 * tab or field (the app's UI is all caps) and is shown highlighted in capitals.
 *
 * Section: id, title, admin (only administrators see it), intro, then either
 * 'steps' (a numbered list), 'parts' (several titled numbered lists) or
 * 'items' (a problem: answer list), and optional 'tips' (helpful notes),
 * 'notes' (things to be careful about) and 'security' (sign-in and password
 * information). Where a section sits in the contents (group) and its icon are
 * in $chapterMeta below.
 */
$sections = [
    [
        'id' => 'getting-started',
        'title' => 'Getting started',
        'admin' => false,
        'intro' => 'This system keeps the Latin Honor lists sent by schools, so you can check whether an applicant holds a Latin Honor and record when their Honor Graduate Eligibility (HGE) application was filed.',
        'steps' => [
            'You land on the [[DASHBOARD]]. Use the menu on the left to go to the other pages. On a small screen, open the menu with the button at the top left.',
            'Switch between the light and dark look with the moon button at the top right.',
            'When you are finished, click [[LOGOUT]] at the bottom of the menu.',
        ],
        'tips' => [
            'Forgot your password? Click [[Forgot password?]] on the sign-in page and ask your system administrator to reset it.',
            'There are two kinds of accounts. An ADMINISTRATOR can use every page. A USER can use the dashboard, look up records and record applications.',
        ],
        'security' => [
            'For your security you are signed out after 4 hours without activity. Just sign in again.',
        ],
    ],
    [
        'id' => 'dashboard',
        'title' => 'The dashboard',
        'admin' => false,
        'intro' => 'The dashboard is a quick summary of what is in the system. It is the first page you see after signing in, and every card on it is scoped to one school year at a time.',
        'steps' => [
            'Click [[DASHBOARD]] in the menu.',
            'Use the row of year buttons below your name to choose the school year every card below reflects. Click the arrows on either side if there are more years than fit.',
            'Read the cards at the top: [[RECORD FOR THE YEAR SELECTED]], [[HGE APPLICATIONS RECEIVED]], [[APPROVED HGE]], [[SCHOOLS WITH RECORDS]] and [[TOTAL GRADUATES ON RECORD]].',
            '[[APPLICATION FILING OVERVIEW]] breaks the selected year down into ON RECORD, APPLIED (split into PROCESSING and APPROVED) and NOT YET APPLIED, with a donut chart and, below it, the Cum Laude/Magna Cum Laude/Summa Cum Laude split.',
            '[[TREND]] charts graduates on record against processing and approved applications across the months of the selected year.',
            '[[SCHOOL FILING SUMMARY]] lists every school\'s on-record, applied, approved and filing/approval rates for the year, best filing rate first. Click [[SHOW MORE]] to see the rest of the list, and [[SHOW LESS]] to collapse it again.',
            '[[LATEST UPLOAD]] lists the most recently uploaded school lists.',
        ],
        'tips' => [
            'To see individual graduates use [[HONOR GRAD LOOK-UP]].',
            'Click a school\'s name in [[SCHOOL FILING SUMMARY]] to open its records.',
        ],
    ],
    [
        'id' => 'lookup',
        'title' => 'Look up a Latin Honor graduate',
        'admin' => false,
        'intro' => 'Use this page to check whether a person holds a Latin Honor, and to record that their HGE application was filed.',
        'parts' => [
            [
                'title' => 'Find a graduate',
                'steps' => [
                    'Click [[HONOR GRAD LOOK-UP]] in the menu. The list opens with [[ALL YEARS]] selected and shows every record.',
                    'To narrow it down, use [[SCHOOL]], [[GRADUATION YEAR]] and [[GRADUATE NAME]]. You can use just one of them or all three.',
                    'Click [[SEARCH]]. Click [[CLEAR]] to go back to the full list.',
                    'Read the result: name, degree/course, school, Latin Honor, date of graduation, year, and the date applied if an application was recorded.',
                    'For a long list, use the page numbers at the bottom.',
                ],
                'tips' => [
                    'A yellow [[DUPLICATE]] tag next to a name means the same name appears on more than one saved record.',
                ],
            ],
            [
                'title' => 'See a record in detail',
                'steps' => [
                    'Click the eye icon on the row to open the record\'s details and see which uploaded list it came from.',
                ],
            ],
            [
                'title' => 'Record that an application was filed',
                'steps' => [
                    'Find the graduate and click the green check button on the row.',
                    'In [[HONOR GRADUATE ELIGIBLE]], check the [[DATE OF APPLICATION]]. Today\'s date is filled in; change it if the application was filed on another day.',
                    'Click [[CONFIRM]]. The date now shows under [[APPLIED]] and the green check disappears.',
                ],
                'notes' => [
                    'An application can be recorded only once for each graduate, so check the name and date before you confirm.',
                ],
            ],
            [
                'title' => 'Administrators only: fix or remove a record',
                'admin' => true,
                'steps' => [
                    'Click the pencil icon to correct a record\'s name, course, honor, date or year, then save.',
                    'Click the trash icon to delete a record. You must type a reason. Deleted records are moved to the [[RECYCLE BIN]] and can be restored. A graduate who already has a filed application cannot be deleted.',
                    'Click [[UPDATE GRADUATION DATE]] to set the date of graduation for every record of one school and year at once.',
                    'Click [[FIND DUPLICATES]] to open the duplicate records page (see "Settings" below).',
                ],
            ],
        ],
    ],
    [
        'id' => 'upload-pdf',
        'title' => 'Upload a Latin Honor list (PDF)',
        'admin' => true,
        'intro' => 'Start here when a school sends its list as a PDF or as a photo or scan (JPG, JPEG or PNG). The system reads the file for you; you then check the result before it is saved.',
        'steps' => [
            'Click [[UPLOAD HON GRAD LIST]] in the menu.',
            'Under [[SCHOOL]], choose the school. If it is not in the list, click [[MANAGE SCHOOLS]] and create it first (see "Settings" below).',
            'Type the [[SCHOOL YEAR]], for example 2025.',
            'Drag the file onto the box, or click it to browse your computer and choose it (PDF, JPG, JPEG or PNG, up to 20 MB). An image counts as one page, so a list photographed on several pages needs one upload per image; the later ones are marked as duplicates (DUP) and can be told apart on their upload pages.',
            'Click [[UPLOAD & EXTRACT RECORDS]]. A progress window shows while the list is being read.',
            'The AI reads a PDF 2 pages at a time (an image is read in one go). The batches follow one another on their own, and a batch the AI fails to read is sent again, up to 3 tries. When every page has been read you are taken straight to the review screen. If a batch still fails after 3 tries you are left on the upload\'s page: click [[CONTINUE EXTRACTION]] to carry on from where it stopped.',
        ],
        'tips' => [
            'The colored dot on the upload card shows how much of today\'s free AI quota is left: green is available, yellow is moderate, red is low. When it is red, uploads use the standard text/OCR reading instead.',
            'If the AI cannot read a batch, nothing is lost: the pages already read are kept. Try again from the upload\'s page (see "School records and upload details") and it carries on from the pages it has not read yet.',
            'If the AI keeps failing, open the upload and click [[EXTRACT VIA TEXT/OCR]] to read it the standard way. It asks your permission before using OCR, which is the slowest and least accurate method.',
            'If a list for the same school and year was already uploaded, a window says so and shows the existing file. Click [[VIEW EXISTING LIST]] to look at it, [[CANCEL]] to stop, or [[ADD AS SEPARATE UPLOAD]] to keep both. The existing list is never changed or replaced.',
            'The first upload for a school and year keeps its normal file name (for example ..._2025_1.pdf). The second and later ones are marked as duplicates in the stored file name (..._2025_dup01.pdf, then ..._2025_dup02.pdf), so you can tell them apart on the upload\'s page.',
        ],
    ],
    [
        'id' => 'review',
        'title' => 'Review and save the records',
        'admin' => true,
        'intro' => 'After an upload you land on the review screen. Nothing is saved to the database until you click [[SAVE RECORDS]], so take your time and correct what the AI or OCR got wrong.',
        'parts' => [
            [
                'title' => 'Check the rows',
                'steps' => [
                    'Look at the [[STATUS]] column. [[VALID]] rows are ready. [[INCOMPLETE]] rows are missing a name, a Latin Honor or a year and must be fixed before you can save.',
                    'Click the pencil icon on a row to correct it. Rows read by a machine carry an [[AI — VERIFY]] or [[OCR — VERIFY]] tag: compare them with the original.',
                    'Click [[VIEW UPLOADED PDF]] (or [[VIEW UPLOADED IMAGE]] for a JPG/PNG upload) to open the original file next to the table.',
                    'Use the search box and the course filter above the table to find a row quickly.',
                ],
            ],
            [
                'title' => 'Change the list',
                'steps' => [
                    '[[ADD RECORD]] adds one graduate by hand.',
                    '[[MASS UPDATE DATE]] puts the same date of graduation on every row. This is handy for lists that have no date column.',
                    '[[MULTIPLE DELETION]]: tick the rows to remove, click the button, and type a reason. The trash icon on a row removes just that row (a reason is also required).',
                    '[[UPLOAD EXCEL]] adds many graduates at once from a spreadsheet when the AI or OCR misread the list. Click [[DOWNLOAD TEMPLATE]] for the format. Rows added this way are marked [[MANUAL]]. A row with no name, or with an unrecognized honor, is skipped and listed so you can fix it.',
                ],
            ],
            [
                'title' => 'Save',
                'steps' => [
                    '[[SAVE AS DRAFT]] keeps your work so you can finish later. Open the review again and it resumes where you stopped.',
                    'When the list is right, click [[SAVE RECORDS]]. The system first checks for names that appear more than once, in your list or already saved (including at other schools).',
                    'If duplicates are found, a window lists them. Tick the copies from this list that should go and click [[REMOVE SELECTED]], click [[SAVE ANYWAY]] if they are different people, or [[CANCEL]] to go back and look. A saved record that already has an application takes priority, so the copies in your list are pre-ticked.',
                    'Confirm the save. The records can now be found in [[HONOR GRAD LOOK-UP]].',
                ],
                'notes' => [
                    'Clicking [[CANCEL]] on the review screen throws the review away. If you only want to stop for now, use [[SAVE AS DRAFT]]. Leaving the page without saving asks you to confirm.',
                ],
            ],
        ],
    ],
    [
        'id' => 'school-records',
        'title' => 'School records and upload details',
        'admin' => true,
        'intro' => 'Use this page to see every uploaded list, check whether it is finished, and open or correct it later.',
        'steps' => [
            'Click [[SCHOOL RECORDS]] in the menu and filter by [[SCHOOL]] and [[SCHOOL YEAR]] if you need to. Click [[SEARCH]].',
            'Each list shows its status: completed, or pending action. A pending list shows how long it has been waiting.',
            'Click the eye icon to open the upload\'s details: school, file name, year, pages, who uploaded it, when, and the last extraction error if there was one.',
            'On the details page, [[VIEW DOCUMENT]] opens the file in a new tab and [[DOWNLOAD]] saves a copy to your computer.',
            '[[EDIT RECORDS]] (or the pencil icon in the list) lets you add, change or remove the saved records of that list.',
            '[[EXTRACT RECORDS]] and [[CONTINUE EXTRACTION]] read a list that has not been saved yet. Reading it again from the start replaces the extracted data that is waiting for review, including any edits you made to it.',
            'For a list that is already saved (for example when an earlier OCR reading was unclear), click [[RE-EXTRACT WITH AI]] or [[RE-EXTRACT VIA TEXT/OCR]] ([[RE-READ TEMPLATE]] for an Excel upload). The result opens as a new review, and nothing changes until you click [[SAVE RECORDS]].',
            'Records that already have a filed application are kept exactly as they are: they show as locked and marked [[APPLIED]], cannot be changed or removed in that review, and a new row with the same name is left out. The other saved records are replaced by the new list; any record the new reading does not include goes to the Recycle Bin and can be restored.',
            '[[CHANGE CAMPUS]] moves the upload and its saved records to another campus of the same school, for a list that was filed under the wrong campus. The records and any filed applications stay exactly as they are, and the stored file is renamed to carry the new campus. The upload keeps its number unless the new campus already uses it. If the school has no other campus yet, add it in [[SCHOOL MANAGEMENT]] first (same school name, different campus).',
            '[[REMOVE FILE]] deletes an upload that has no saved records. It goes to the Recycle Bin.',
        ],
    ],
    [
        'id' => 'bulk-update',
        'title' => 'Bulk update of HGE applications',
        'admin' => true,
        'intro' => 'Use this to record many applications at once from a spreadsheet of applicants, instead of one at a time in Look-Up. It is found on the [[ADMIN]] page. Nothing is recorded until you confirm.',
        'steps' => [
            'Open [[SETTINGS]] in the menu, then click [[ADMIN]], and find [[BULK UPDATE HGE APPLICATIONS]].',
            'Click [[DOWNLOAD TEMPLATE]] and fill in one applicant per row: the CoE issue date (this becomes the approval date), last name, first name, middle initial, middle name and extension. Type an underscore ( _ ) when there is no middle name. A spreadsheet exported from your source system also works as it is: the system finds the columns by their headings (CoEIssueDate, LName, FName, MI, MName, ExtName) and ignores every other column, such as address, remarks, honors or the spoiled/cancelled flags.',
            'Click [[CHOOSE FILE]] and pick the file (.xls, .xlsx or .csv), then click [[UPLOAD & PREVIEW]]. This only checks the file; nothing is saved.',
            'Read the preview: [[WILL BE FILED/APPROVED]] (a Latin Honor record matched), [[ALREADY APPROVED]] (skipped, left unchanged), [[NEEDS REVIEW]] (not sure who it is), [[NOT FOUND]] (no Latin Honor record) and [[UNREADABLE ROWS]] (missing name or bad date).',
            'If it looks right, click the confirm button, for example [[CONFIRM & FILE]] followed by the number of applications.',
            'For the applicants under [[NEEDS REVIEW]], click [[GO TO NEEDS REVIEW]]. For each one choose [[FILE FOR THIS RECORD]] next to the right saved record, or [[NOT A MATCH · DISMISS]] if none of them is the person.',
        ],
        'tips' => [
            'The date in the file is used as the approval date. An applicant with no application yet is filed and approved on that date; one whose application is still processing is approved on that date (the original filing date is kept). Choosing [[FILE FOR THIS RECORD]] under [[NEEDS REVIEW]] also files and approves on the file\'s date. If the saved record is already filed but not yet approved, the button reads [[APPROVE THIS RECORD]] and approves it on the file\'s date without changing its filing date.',
            'Applicants under [[NEEDS REVIEW]] stay on the list until you act on them. One that already has an application recorded for that date closes by itself.',
            'Because the other columns are ignored, a raw export is not checked for spoiled or cancelled rows, or for a different honor. When a person appears on two rows, the earlier date is used. Look over the preview before you confirm, and check unusual applicants in Look-Up afterwards.',
            '[[DOWNLOAD REVIEW FILE]] saves the uncertain and unreadable rows so you can fix them in your spreadsheet.',
        ],
    ],
    [
        'id' => 'settings',
        'title' => 'Settings: managing the system',
        'admin' => true,
        'intro' => 'These tools are under [[SETTINGS]] in the menu. Actions that cannot easily be undone ask for your password first.',
        'parts' => [
            [
                'title' => 'School management',
                'steps' => [
                    'Click [[SCHOOL MANAGEMENT]], then [[CREATE SCHOOL]]. Enter the school name and address; the campus is optional.',
                    'Click the pencil icon to edit a school or set it ACTIVE or DISABLED. A school can only be removed when it has no uploads and no records.',
                ],
            ],
            [
                'title' => 'OCR course keywords',
                'steps' => [
                    'If rows go missing when a list is read by text/OCR, a course abbreviation is probably not known yet. Click [[OCR COURSE KEYWORDS]].',
                    'Type the abbreviation under [[NEW DEGREE / COURSE KEYWORD]] (for example BSCRIM) and add it. Use letters, numbers, dots and hyphens only; it is stored in capitals.',
                ],
            ],
            [
                'title' => 'Duplicate records',
                'steps' => [
                    'Click [[DUPLICATE RECORDS]]. Records with the same name are grouped, whatever their school, year or course.',
                    'In each group the copy marked [[KEEP]] stays. A copy that already has a filed application always stays and cannot be removed. Otherwise the oldest copy is suggested.',
                    'Tick the extra copies and remove them. They go to the Recycle Bin and can be restored.',
                    'A small [[DUP01]] tag next to "Upload 2" means the record came from a second upload of the same school and year (DUP02 for the third, and so on). Records from the first upload have no tag.',
                ],
            ],
            [
                'title' => 'Recycle bin',
                'steps' => [
                    'Everything removed from the system waits in the [[RECYCLE BIN]] first: records, uploads, schools, users and keywords.',
                    'Filter by name or type, tick what you want, then click [[RESTORE SELECTED]] to put it back exactly as it was, or [[DELETE PERMANENTLY]] (a reason is required).',
                ],
            ],
            [
                'title' => 'Activity log',
                'steps' => [
                    'Click [[ACTIVITY LOG]] to see who did what: sign-ins, uploads, changes, removals, searches and more.',
                    'Filter by user, action and dates. Entries are kept for 6 months.',
                ],
            ],
            [
                'title' => 'Admin tools',
                'steps' => [
                    'Click [[ADMIN]]. [[CREATE DB BACKUP]] downloads a full copy of the database as a .sql file.',
                    '[[AI EXTRACTION SETTINGS]] lets you view or change the five API keys used for AI reading. For each key choose the service it belongs to, Gemini or Claude, and paste the key (leave a key blank to keep the current one). The keys are tried in order, key 1 first: when one cannot read a list, for example because its daily quota or credit is used up, the next one takes over.',
                    'Both ask you to type your username and password again before they continue.',
                ],
            ],
        ],
    ],
    [
        'id' => 'troubleshooting',
        'title' => 'When something goes wrong',
        'admin' => false,
        'intro' => 'Most problems have a simple fix.',
        'items' => [
            ['admin' => false, 'text' => 'You were signed out: this happens after 4 hours without activity. Sign in again.'],
            ['admin' => false, 'text' => 'You cannot find a graduate: clear the filters, make sure [[ALL YEARS]] and [[ALL SCHOOLS]] are selected, and try just part of the name.'],
            ['admin' => false, 'text' => 'You do not see a page or button described here: some of them are for administrators only.'],
            ['admin' => true, 'text' => '"THE AI TOOK TOO LONG TO ANSWER": the pages already read were not lost, and each batch is already tried up to 3 times before this appears. Open the upload from [[SCHOOL RECORDS]] and click [[CONTINUE EXTRACTION]] (or [[EXTRACT RECORDS]] if nothing was read yet) and it continues from where it stopped.'],
            ['admin' => true, 'text' => '"TODAY\'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP": try again tomorrow, or open the upload and click [[EXTRACT VIA TEXT/OCR]].'],
            ['admin' => true, 'text' => 'Rows are missing from a list read by OCR: add the missing course abbreviation in [[OCR COURSE KEYWORDS]], read the file again, or add the rows with [[ADD RECORD]] or [[UPLOAD EXCEL]].'],
            ['admin' => true, 'text' => 'A record has a wrong name or date: fix it on the review screen before saving, or afterwards with the pencil icon in Look-Up or [[EDIT RECORDS]].'],
            ['admin' => true, 'text' => 'You removed something by mistake: open the [[RECYCLE BIN]] and restore it.'],
            ['admin' => false, 'text' => 'Still stuck: contact your system administrator.'],
        ],
    ],
];

/**
 * Where each section sits in the contents (collapsible groups) and which icon it
 * uses. Presentation only: the content above is not touched by this.
 */
$chapterGroups = [
    'basics' => 'Basics',
    'uploading' => 'Uploading lists',
    'administration' => 'Administration',
    'support' => 'Support',
];
$chapterMeta = [
    'getting-started' => ['group' => 'basics', 'icon' => 'M21 12a9 9 0 11-18 0 9 9 0 0118 0z M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z'],
    'dashboard' => ['group' => 'basics', 'icon' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z'],
    'lookup' => ['group' => 'basics', 'icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z'],
    'upload-pdf' => ['group' => 'uploading', 'icon' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5'],
    'review' => ['group' => 'uploading', 'icon' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75'],
    'school-records' => ['group' => 'uploading', 'icon' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z'],
    'bulk-update' => ['group' => 'administration', 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
    'settings' => ['group' => 'administration', 'icon' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75'],
    'troubleshooting' => ['group' => 'support', 'icon' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z'],
];
$calloutKinds = [
    'tips' => [
        'label' => 'Tip',
        'box' => 'border-csc-navy-500/25 border-l-csc-navy-500 bg-csc-navy-500/5',
        'tone' => 'text-csc-navy-600 dark:text-csc-navy-300',
        'icon' => 'M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18',
    ],
    'notes' => [
        'label' => 'Important',
        'box' => 'border-amber-500/30 border-l-amber-500 bg-amber-500/10',
        'tone' => 'text-amber-700 dark:text-amber-400',
        'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z',
    ],
    'security' => [
        'label' => 'Security',
        'box' => 'border-emerald-500/30 border-l-emerald-500 bg-emerald-500/10',
        'tone' => 'text-emerald-700 dark:text-emerald-400',
        'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    ],
];

/**
 * Screenshots shown in the guide, keyed by chapter id (placed after the chapter's
 * intro) or "chapter-id#N" (placed under that part's heading, N counting from 0).
 * Each entry is [file name without -light/-dark.webp, alt text, caption]. The images
 * live in assets/images/help/ and show made-up sample data only.
 */
$helpFigures = [
    'getting-started' => [['dashboard', 'The dashboard, with the menu on the left and the moon button at the top right', 'After you sign in you land on the dashboard. The menu on the left leads to every page you can use, and the moon button at the top right switches between the light and dark look.']],
    'dashboard' => [['dashboard', 'The dashboard with its year picker, summary cards, filing overview, trend chart and school filing summary', 'The year buttons, the cards at the top, the Application filing overview and Trend charts, and the School filing summary and Latest upload lists described in the steps below.']],
    'lookup#0' => [['lookup', 'The Honor Graduate Look-Up page with its search boxes and a list of results', 'Search by school, graduation year or name, then read each result row. The eye icon opens the record and the green check records an application.']],
    'lookup#2' => [['lookup-apply', 'The Honor graduate eligible window asking for the date of application', 'Check the date of application, then click Confirm.']],
    'lookup#3' => [['lookup-admin', 'The Look-Up page as an administrator sees it, with extra buttons and icons', 'An administrator also sees the pencil and trash icons on each row and the Update graduation date and Find duplicates buttons.']],
    'upload-pdf' => [['upload-pdf', 'The upload page with the school, school year and PDF fields', 'Choose the school, type the school year and add the PDF, then click Upload & extract records.']],
    'review#0' => [['review', 'The review screen with the extracted rows and the buttons above the table', 'Check each row, fix what is wrong, and use the buttons above the table to change the list. Nothing is saved until you click Save records.']],
    'school-records' => [
        ['school-records', 'The School records page listing uploaded lists and their status', 'Every uploaded list with its status. A pending list shows how long it has been waiting.'],
        ['view-upload', 'The upload details page of a saved list, with the re-extract buttons', 'The details page of a list that is already saved, with the Re-extract buttons and the quick actions on the right.'],
    ],
    'bulk-update' => [['bulk-update', 'The Bulk update HGE applications card on the Admin page with its Needs review list', 'The bulk update card and, below it, the Needs review list for applicants the system could not match with certainty.']],
    'settings#0' => [['schools', 'The School management page', 'Create and edit schools. A school can only be removed when it has no uploads and no records.']],
    'settings#1' => [['ocr-courses', 'The OCR course keywords page', 'Add a course abbreviation the text/OCR reading does not know yet.']],
    'settings#2' => [['duplicates', 'The Duplicate records page with groups of records that share a name', 'Records with the same name are grouped. The copy marked Keep stays; tick the extra copies to move them to the Recycle bin.']],
    'settings#3' => [['recycle-bin', 'The Recycle bin page listing removed items', 'Everything removed from the system waits here until you restore it or delete it permanently.']],
    'settings#4' => [['activity-log', 'The Activity log page', 'Who did what, with filters for user, action and dates.']],
    'settings#5' => [['admin-tools', 'The top of the Admin page with the database backup and AI settings cards', 'The database backup and the AI extraction settings. Both ask for your username and password again.']],
];

/**
 * Screenshots as figures: a light and a dark version (the one matching the theme is
 * shown), loaded only when scrolled near, and a button that opens the larger view.
 *
 * @param array<int, array{0: string, 1: string, 2: string}> $figures
 */
function help_render_figures(array $figures, string $idPrefix): void
{
    $dir = __DIR__ . '/../assets/images/help/';
    foreach ($figures as $n => [$file, $alt, $caption]) {
        $size = @getimagesize($dir . $file . '-light.webp') ?: [1280, 860];
        [$w, $h] = [(int) $size[0], (int) $size[1]];
        $img = static fn (string $theme, string $class): string => '<img src="' . e(asset_url('assets/images/help/' . $file . '-' . $theme . '.webp')) . '" alt="' . e($alt) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async" class="' . $class . '">';
        echo '<figure class="mb-6">'
            . '<button type="button" data-help-zoom class="group relative block w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-100 text-left shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/60 dark:border-white/10 dark:bg-white/5" aria-label="Enlarge the screenshot: ' . e($alt) . '">'
            . $img('light', 'block h-auto w-full dark:hidden') . $img('dark', 'hidden h-auto w-full dark:block')
            . '<span class="pointer-events-none absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-md bg-gray-900/75 px-2 py-1 text-[11px] font-medium text-white opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-visible:opacity-100">'
            . help_icon('M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6', 'h-3.5 w-3.5') . 'Click to enlarge</span>'
            . '</button>'
            . '<figcaption id="' . e($idPrefix . '-f' . ($n + 1)) . '" data-help-searchable class="mt-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">' . e($caption) . '</figcaption>'
            . '</figure>';
    }
}
/** One outline icon (heroicons path data). */
function help_icon(string $path, string $class = 'h-5 w-5'): string
{
    return '<svg class="' . e($class) . '" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="' . e($path) . '" /></svg>';
}

/** Escapes a string and turns [[LABEL]] into a highlighted button/field name (shown in capitals, as in the app). */
function help_text(string $text): string
{
    $out = '';
    foreach (preg_split('/\[\[(.+?)\]\]/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $i => $piece) {
        $out .= $i % 2 === 1
            ? '<span class="whitespace-nowrap rounded-md bg-csc-navy-600/10 px-1 py-0.5 text-[0.82em] font-semibold text-csc-navy-600 dark:bg-csc-navy-300/15 dark:text-csc-navy-300">' . e_upper($piece) . '</span>'
            : e($piece);
    }

    return $out;
}

/**
 * Splits a step into a short heading (its first sentence) and the rest as a
 * description; a one-sentence step has no description.
 *
 * @return array{0: string, 1: string}
 */
function help_split_step(string $step): array
{
    $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z\[])/u', $step, 2);

    return [$parts[0], $parts[1] ?? ''];
}

/** @param array<int, string> $steps */
function help_render_steps(array $steps, string $idPrefix): void
{
    $last = count($steps) - 1;
    echo '<ol class="help-steps">';
    foreach ($steps as $n => $step) {
        [$heading, $detail] = help_split_step($step);
        echo '<li id="' . e($idPrefix . '-s' . ($n + 1)) . '" data-help-searchable class="relative flex gap-4 scroll-mt-24' . ($n < $last ? ' pb-6' : '') . '">';
        if ($n < $last) {
            echo '<span class="absolute bottom-0 left-3.5 top-8 w-px bg-gray-200 dark:bg-white/10" aria-hidden="true"></span>';
        }
        echo '<span class="relative z-[1] flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-csc-navy-600 text-xs font-bold text-white ring-4 ring-white dark:bg-csc-navy-500 dark:ring-surface-900" aria-hidden="true">' . ($n + 1) . '</span>'
            . '<div class="min-w-0 flex-1 pt-0.5">'
            . '<p class="text-sm font-medium leading-relaxed text-gray-900 dark:text-gray-100"><span class="sr-only">Step ' . ($n + 1) . ': </span>' . help_text($heading) . '</p>'
            . ($detail !== '' ? '<p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">' . help_text($detail) . '</p>' : '')
            . '</div></li>';
    }
    echo '</ol>';
}

/**
 * Tips, important notes and security information as callouts.
 *
 * @param array<string, array<int, string>> $groups callout kind => texts
 */
function help_render_callouts(array $groups, string $idPrefix): void
{
    global $calloutKinds;
    $n = 0;
    foreach ($calloutKinds as $kind => $style) {
        foreach ($groups[$kind] ?? [] as $text) {
            $n++;
            echo '<aside id="' . e($idPrefix . '-c' . $n) . '" data-help-searchable role="note" class="mt-5 flex gap-3 rounded-xl border border-l-4 p-4 scroll-mt-24 ' . $style['box'] . '">'
                . '<span class="mt-0.5 shrink-0 ' . $style['tone'] . '">' . help_icon($style['icon']) . '</span>'
                . '<div class="min-w-0"><p class="text-sm font-semibold ' . $style['tone'] . '">' . e($style['label']) . '</p>'
                . '<p class="mt-0.5 text-sm leading-relaxed text-gray-700 dark:text-gray-300">' . help_text($text) . '</p></div>'
                . '</aside>';
        }
    }
}

/** "Admin only" label: subtle, never the first thing the eye lands on. */
function help_admin_badge(string $extraClass = ''): string
{
    return '<span class="inline-flex shrink-0 items-center gap-1 rounded-full border border-gray-300/80 px-1.5 py-px text-[10px] font-medium text-gray-500 dark:border-white/15 dark:text-gray-400 ' . e($extraClass) . '">'
        . '<svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>'
        . 'Admin only</span>';
}

$visibleSections = array_values(array_filter($sections, static fn (array $section): bool => $isAdmin || !$section['admin']));
$chapterCount = count($visibleSections);

// Chapters grouped for the contents, keeping the guide's own order inside a group.
$groupedChapters = [];
foreach ($visibleSections as $index => $section) {
    $groupedChapters[$chapterMeta[$section['id']]['group']][] = $index;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<noscript><style>[data-help-chapter][hidden]{display:block!important}</style></noscript>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
        <header class="mb-6 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-csc-navy-600 text-white shadow-sm sm:h-14 sm:w-14">
                    <?= help_icon('M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25', 'h-6 w-6 sm:h-7 sm:w-7') ?>
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-csc-navy-600 dark:text-white sm:text-3xl">Help center</h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">A step-by-step guide to using the CSC-HGE Verify system.<?= $isAdmin ? '' : ' Sections for administrators are not shown.' ?></p>
                </div>
            </div>

            <form role="search" id="help-search-form" class="relative w-full lg:max-w-md" onsubmit="return false;">
                <label for="help-search" class="sr-only">Search the guide</label>
                <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-gray-400 dark:text-gray-500">
                    <?= help_icon('M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z', 'h-5 w-5') ?>
                </span>
                <input id="help-search" type="search" autocomplete="off" spellcheck="false" placeholder="Search the guide…" aria-controls="help-results"
                       class="w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-11 pr-20 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition focus:border-csc-navy-500 focus:outline-none focus:ring-2 focus:ring-csc-navy-500/30 dark:border-white/10 dark:bg-surface-900 dark:text-white dark:placeholder:text-gray-500 [&::-webkit-search-cancel-button]:hidden">
                <kbd id="help-search-hint" class="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[11px] font-medium text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 sm:block">Press /</kbd>
                <button type="button" id="help-search-clear" hidden aria-label="Clear search"
                        class="[&[hidden]]:hidden absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white">
                    <?= help_icon('M6 18L18 6M6 6l12 12', 'h-4 w-4') ?>
                </button>
            </form>
        </header>

        <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start">
            <aside class="lg:sticky lg:top-[74px]">
                <button type="button" id="help-contents-toggle" aria-expanded="false" aria-controls="help-contents-panel"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-left text-sm font-semibold text-csc-navy-600 hge-shadow focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:border-white/10 dark:bg-surface-900 dark:text-white lg:hidden">
                    <span class="flex items-center gap-2.5">
                        <?= help_icon('M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5', 'h-5 w-5') ?>
                        <span>Contents <span class="font-normal text-gray-500 dark:text-gray-400">· <span id="help-contents-current">Chapter 1</span></span></span>
                    </span>
                    <svg class="h-4 w-4 transition-transform" data-help-chevron fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </button>

                <nav id="help-contents-panel" aria-label="Help contents" class="mt-2 hidden max-h-[calc(100vh-7rem)] overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 hge-shadow dark:border-white/10 dark:bg-surface-900 lg:mt-0 lg:block">
                    <?php foreach ($chapterGroups as $groupId => $groupLabel): ?>
                        <?php if (empty($groupedChapters[$groupId])) { continue; } ?>
                        <div class="mb-1" data-help-group="<?= e($groupId) ?>">
                            <button type="button" aria-expanded="true" aria-controls="help-group-<?= e($groupId) ?>" data-help-group-toggle
                                    class="flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                                <span><?= e($groupLabel) ?></span>
                                <span class="flex items-center gap-2">
                                    <span class="rounded-full bg-gray-100 px-1.5 text-[10px] font-medium tabular-nums text-gray-500 dark:bg-white/10 dark:text-gray-400"><?= count($groupedChapters[$groupId]) ?></span>
                                    <svg class="h-3.5 w-3.5 transition-transform" data-help-chevron fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </span>
                            </button>
                            <ul id="help-group-<?= e($groupId) ?>" class="mt-0.5 space-y-0.5">
                                <?php foreach ($groupedChapters[$groupId] as $index): ?>
                                    <?php $section = $visibleSections[$index]; ?>
                                    <li>
                                        <a href="#help-<?= e($section['id']) ?>" data-help-link="help-<?= e($section['id']) ?>" data-help-index="<?= $index + 1 ?>"
                                           class="group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-csc-navy-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors group-hover:text-csc-navy-600 dark:bg-white/5 dark:text-gray-400 dark:group-hover:text-white" data-help-icon>
                                                <?= help_icon($chapterMeta[$section['id']]['icon'], 'h-4 w-4') ?>
                                            </span>
                                            <span class="min-w-0 flex-1 leading-snug"><?= e($section['title']) ?></span>
                                            <?php if ($section['admin']): ?><?= help_admin_badge() ?><?php endif; ?>
                                            <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" data-help-seen hidden fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                            <span class="sr-only" data-help-seen-text hidden>(viewed)</span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>

                    <div class="sticky -bottom-3 -mx-3 mt-2 rounded-b-xl border-t border-gray-100 bg-white px-5 pb-3 pt-3 dark:border-white/10 dark:bg-surface-900">
                        <div class="mb-1.5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>Chapters viewed</span>
                            <span class="tabular-nums" id="help-seen-text">0 of <?= $chapterCount ?></span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="Chapters viewed" aria-valuemin="0" aria-valuemax="<?= $chapterCount ?>" aria-valuenow="0" id="help-seen-bar">
                            <div class="h-full rounded-full bg-emerald-500 transition-all duration-300" style="width:0%" id="help-seen-fill"></div>
                        </div>
                    </div>
                </nav>
            </aside>

            <div class="min-w-0 max-w-3xl">
                <p id="help-status" class="sr-only" role="status" aria-live="polite"></p>

                <div id="help-results" hidden class="rounded-2xl border border-gray-200 bg-white p-5 hge-shadow dark:border-white/10 dark:bg-surface-900 sm:p-6">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <h2 class="text-lg font-semibold text-csc-navy-600 dark:text-white" id="help-results-title">Search results</h2>
                        <button type="button" id="help-results-close" class="rounded-lg px-2.5 py-1 text-sm font-medium text-csc-navy-600 hover:bg-csc-navy-600/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:text-csc-navy-300">Back to the guide</button>
                    </div>
                    <ul id="help-results-list" class="divide-y divide-gray-100 dark:divide-white/10"></ul>
                    <p id="help-results-empty" hidden class="py-6 text-center text-sm text-gray-600 dark:text-gray-400">Nothing matches that. Try different words, or choose a chapter from the contents.</p>
                </div>

                <div id="help-chapters">
                    <?php foreach ($visibleSections as $index => $section): ?>
                        <?php
                        $chapterId = 'help-' . $section['id'];
                        $number = $index + 1;
                        $prev = $visibleSections[$index - 1] ?? null;
                        $next = $visibleSections[$index + 1] ?? null;
                        ?>
                        <article id="<?= e($chapterId) ?>" data-help-chapter data-help-title="<?= e($section['title']) ?>" data-help-number="<?= $number ?>" aria-labelledby="<?= e($chapterId) ?>-title"<?= $index === 0 ? '' : ' hidden' ?>
                                 class="scroll-mt-24 rounded-2xl border border-gray-200 bg-white p-5 hge-shadow dark:border-white/10 dark:bg-surface-900 sm:p-8">
                            <div class="mb-5">
                                <div class="mb-2 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium">Chapter <?= $number ?> of <?= $chapterCount ?></span>
                                    <?php if ($section['admin']): ?><?= help_admin_badge() ?><?php endif; ?>
                                </div>
                                <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="Chapter <?= $number ?> of <?= $chapterCount ?>" aria-valuemin="1" aria-valuemax="<?= $chapterCount ?>" aria-valuenow="<?= $number ?>">
                                    <div class="h-full rounded-full bg-csc-navy-600 dark:bg-csc-navy-300" style="width:<?= round($number / $chapterCount * 100, 1) ?>%"></div>
                                </div>
                            </div>

                            <div class="mb-3 flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300"><?= help_icon($chapterMeta[$section['id']]['icon'], 'h-5 w-5') ?></span>
                                <h2 id="<?= e($chapterId) ?>-title" tabindex="-1" class="text-xl font-bold tracking-tight text-csc-navy-600 focus:outline-none dark:text-white sm:text-2xl"><?= e($section['title']) ?></h2>
                            </div>
                            <p id="<?= e($chapterId) ?>-intro" data-help-searchable class="mb-7 text-[0.95rem] leading-relaxed text-gray-600 dark:text-gray-400"><?= help_text($section['intro']) ?></p>

                            <?php help_render_figures($helpFigures[$section['id']] ?? [], $chapterId); ?>

                            <?php if (isset($section['steps'])): ?>
                                <?php help_render_steps($section['steps'], $chapterId); ?>
                            <?php endif; ?>

                            <?php if (isset($section['parts'])): ?>
                                <div class="space-y-9">
                                    <?php foreach ($section['parts'] as $partIndex => $part): ?>
                                        <?php if (!empty($part['admin']) && !$isAdmin) { continue; } ?>
                                        <?php $partId = $chapterId . '-p' . ($partIndex + 1); ?>
                                        <section id="<?= e($partId) ?>" aria-labelledby="<?= e($partId) ?>-title" class="scroll-mt-24">
                                            <div class="mb-4 flex flex-wrap items-center gap-2 border-b border-gray-100 pb-2 dark:border-white/10">
                                                <h3 id="<?= e($partId) ?>-title" data-help-searchable class="text-base font-semibold text-gray-900 dark:text-white"><?= e($part['title']) ?></h3>
                                                <?php if (!empty($part['admin'])): ?><?= help_admin_badge() ?><?php endif; ?>
                                            </div>
                                            <?php help_render_figures($helpFigures[$section['id'] . '#' . $partIndex] ?? [], $partId); ?>
                                            <?php help_render_steps($part['steps'], $partId); ?>
                                            <?php help_render_callouts(['tips' => $part['tips'] ?? [], 'notes' => $part['notes'] ?? [], 'security' => $part['security'] ?? []], $partId); ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($section['items'])): ?>
                                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                                    <?php $itemNo = 0; ?>
                                    <?php foreach ($section['items'] as $item): ?>
                                        <?php if ($item['admin'] && !$isAdmin) { continue; } ?>
                                        <?php
                                        $itemNo++;
                                        // "Problem: what to do" reads best as a short heading plus its answer.
                                        $colon = strpos($item['text'], ': ');
                                        $heading = ($colon !== false && $colon <= 90 && strpos(substr($item['text'], 0, $colon), '[[') === false) ? substr($item['text'], 0, $colon) : '';
                                        $answer = $heading !== '' ? substr($item['text'], $colon + 2) : $item['text'];
                                        ?>
                                        <li id="<?= e($chapterId . '-i' . $itemNo) ?>" data-help-searchable class="flex gap-3 py-4 first:pt-0 last:pb-0 scroll-mt-24">
                                            <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true">
                                                <?= help_icon('M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z', 'h-5 w-5') ?>
                                            </span>
                                            <div class="min-w-0">
                                                <?php if ($heading !== ''): ?><p class="text-sm font-semibold text-gray-900 dark:text-white"><?= help_text($heading) ?>:</p><?php endif; ?>
                                                <p class="<?= $heading !== '' ? 'mt-1 ' : '' ?>text-sm leading-relaxed text-gray-600 dark:text-gray-400"><?= help_text($answer) ?></p>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php help_render_callouts(['tips' => $section['tips'] ?? [], 'notes' => $section['notes'] ?? [], 'security' => $section['security'] ?? []], $chapterId); ?>

                            <nav aria-label="Chapter navigation" class="mt-9 grid gap-3 border-t border-gray-100 pt-6 dark:border-white/10 sm:grid-cols-2">
                                <?php if ($prev): ?>
                                    <a href="#help-<?= e($prev['id']) ?>" data-help-nav rel="prev"
                                       class="group flex items-center gap-3 rounded-xl border border-gray-200 p-3.5 transition-colors hover:border-csc-navy-500/40 hover:bg-csc-navy-600/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:border-white/10 dark:hover:bg-white/5">
                                        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-hover:-translate-x-0.5 group-hover:text-csc-navy-600 dark:group-hover:text-csc-navy-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                        <span class="min-w-0"><span class="block text-xs text-gray-500 dark:text-gray-400">Previous</span><span class="block text-sm font-semibold leading-snug text-gray-900 dark:text-white"><?= e($prev['title']) ?></span></span>
                                    </a>
                                <?php else: ?>
                                    <span class="hidden sm:block"></span>
                                <?php endif; ?>
                                <?php if ($next): ?>
                                    <a href="#help-<?= e($next['id']) ?>" data-help-nav rel="next"
                                       class="group flex items-center justify-end gap-3 rounded-xl border border-gray-200 p-3.5 text-right transition-colors hover:border-csc-navy-500/40 hover:bg-csc-navy-600/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:border-white/10 dark:hover:bg-white/5 sm:col-start-2">
                                        <span class="min-w-0"><span class="block text-xs text-gray-500 dark:text-gray-400">Next</span><span class="block text-sm font-semibold leading-snug text-gray-900 dark:text-white"><?= e($next['title']) ?></span></span>
                                        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-hover:translate-x-0.5 group-hover:text-csc-navy-600 dark:group-hover:text-csc-navy-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">Can't find what you need? Contact your system administrator.</p>
                <p class="mt-1 pb-2 text-center text-xs text-gray-400 dark:text-gray-500">The screenshots in this guide use made-up sample data, so the names and figures on your screen will differ.</p>
            </div>
        </div>
    </div>

    <dialog id="help-lightbox" aria-labelledby="help-lightbox-caption" class="m-auto max-h-[94vh] w-[min(96vw,1360px)] max-w-none overflow-auto rounded-2xl bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-black/70 dark:bg-surface-900 dark:text-gray-100">
        <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-white/10 dark:bg-surface-900/95">
            <p id="help-lightbox-caption" class="text-sm leading-relaxed text-gray-700 dark:text-gray-300"></p>
            <button type="button" id="help-lightbox-close" aria-label="Close the enlarged screenshot" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/60 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white">
                <?= help_icon('M6 18L18 6M6 6l12 12', 'h-5 w-5') ?>
            </button>
        </div>
        <img id="help-lightbox-img" alt="" class="block h-auto w-full">
    </dialog>
    <button type="button" id="help-top" hidden aria-label="Back to top"
            class="[&[hidden]]:hidden fixed bottom-5 right-5 z-30 flex items-center gap-2 rounded-full bg-csc-navy-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg transition hover:bg-csc-navy-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-300 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-surface-950">
        <?= help_icon('M4.5 15.75l7.5-7.5 7.5 7.5', 'h-4 w-4') ?>
        <span class="hidden sm:inline">Back to top</span>
    </button>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= e(asset_url('assets/js/help.js')) ?>"></script>
