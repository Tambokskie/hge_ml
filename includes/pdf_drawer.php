<div id="pdf-drawer-overlay" class="hidden fixed inset-0 z-50 bg-black/60 opacity-0 transition-opacity duration-300 ease-out"></div>
<aside id="pdf-drawer" role="dialog" aria-modal="true" aria-label="Document preview"
       class="fixed inset-y-0 right-0 z-50 flex w-full max-w-full translate-x-full flex-col border-l border-gray-200 bg-white shadow-2xl transition-transform duration-300 ease-out sm:w-[92vw] lg:w-[960px] dark:border-white/10 dark:bg-surface-900">
    <div class="flex items-start justify-between gap-3 px-4 py-3 border-b border-gray-200 dark:border-white/10">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            </span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-gray-900 dark:text-white">DOCUMENT PREVIEW</p>
                <p id="pdf-drawer-filename" class="truncate text-xs text-gray-500 dark:text-gray-400">PDF PREVIEW</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <a id="pdf-drawer-new-tab" href="#" target="_blank" rel="noopener" title="Open in new tab"
               class="rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
            </a>
            <button type="button" id="pdf-drawer-close" aria-label="Close"
                    class="rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </div>
    <div class="flex items-center gap-1 border-b border-gray-200 dark:border-white/10 px-4">
        <button type="button" data-pdf-drawer-tab="preview"
                class="pdf-drawer-tab flex items-center gap-1.5 border-b-2 border-csc-navy-600 px-3 py-2.5 text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            PREVIEW
        </button>
        <button type="button" data-pdf-drawer-tab="details"
                class="pdf-drawer-tab flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2.5 text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
            DETAILS
        </button>
    </div>
    <div class="relative min-h-0 flex-1 bg-gray-100 dark:bg-surface-950">
        <div id="pdf-drawer-panel-preview" class="absolute inset-0">
            <div id="pdf-drawer-loading" class="hidden absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100 dark:bg-surface-950">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 animate-spin text-csc-navy-600 dark:text-csc-navy-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">LOADING PDF...</p>
            </div>
            <iframe id="pdf-drawer-frame" src="" title="PDF preview" class="h-full w-full border-0"></iframe>
        </div>
        <div id="pdf-drawer-panel-details" class="hidden absolute inset-0 overflow-y-auto bg-white dark:bg-surface-900 p-5">
            <div id="pdf-drawer-details-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4"></div>
        </div>
    </div>
</aside>
