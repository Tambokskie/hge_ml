<div id="pdf-drawer-overlay" class="hidden fixed inset-0 z-50 bg-black/60 opacity-0 transition-opacity duration-300 ease-out"></div>
<aside id="pdf-drawer" role="dialog" aria-modal="true" aria-label="PDF preview"
       class="fixed inset-y-0 right-0 z-50 flex w-full max-w-full translate-x-full flex-col border-l border-gray-200 bg-white shadow-2xl transition-transform duration-300 ease-out sm:w-[92vw] lg:w-[960px] dark:border-white/10 dark:bg-surface-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10 px-4 py-3">
        <p id="pdf-drawer-filename" class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white">PDF PREVIEW</p>
        <div class="flex shrink-0 items-center gap-2">
            <a id="pdf-drawer-new-tab" href="#" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 text-xs font-semibold px-3 py-1.5 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                OPEN IN NEW TAB
            </a>
            <button type="button" id="pdf-drawer-close" aria-label="Close"
                    class="rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </div>
    <div class="relative min-h-0 flex-1 bg-gray-100 dark:bg-surface-950">
        <div id="pdf-drawer-loading" class="hidden absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100 dark:bg-surface-950">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 animate-spin text-accent-600 dark:text-accent-400" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">LOADING PDF...</p>
        </div>
        <iframe id="pdf-drawer-frame" src="" title="PDF preview" class="h-full w-full border-0"></iframe>
    </div>
</aside>
