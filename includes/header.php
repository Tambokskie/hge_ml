<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSC-HGE VERIFY</title>
    <link rel="icon" type="image/png" href="<?= e(asset_url('assets/images/csclogo.png')) ?>">
    <script>
    (function () {
        try {
            if (localStorage.getItem('hge_theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-gray-50 dark:bg-surface-950 text-gray-900 dark:text-gray-100">
<header class="lg:ml-64 flex items-center justify-between gap-4 border-b border-gray-200 dark:border-white/10 bg-white/60 dark:bg-surface-900/60 px-4 py-3 sticky top-0 z-20 backdrop-blur">
    <button id="sidebar-toggle" class="lg:hidden text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white" aria-label="Open menu" aria-expanded="false">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="flex-1"></div>
    <div class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
        <button id="theme-toggle" type="button" class="rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white" aria-label="Switch between dark mode and light mode">
            <svg class="h-5 w-5 hidden dark:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.485-15.485l-.707.707M5.222 18.778l-.707.707M21 12h-1M4 12H3m16.485 5.485l-.707-.707M6.929 6.929l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <svg class="h-5 w-5 block dark:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        </button>
        <div class="flex flex-col items-end leading-tight">
            <span class="text-gray-900 dark:text-white"><?= e_upper($currentUser['name'] ?? '') ?></span>
            <span class="text-xs uppercase text-gray-400 dark:text-gray-600"><?= e($currentUser['role'] ?? '') ?></span>
        </div>
    </div>
</header>
