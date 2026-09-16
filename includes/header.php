<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HGE DATABASE</title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-surface-950 text-gray-100">
<header class="lg:ml-64 flex items-center justify-between gap-4 border-b border-white/10 bg-surface-900/60 px-4 py-3 sticky top-0 z-20 backdrop-blur">
    <button id="sidebar-toggle" class="lg:hidden text-gray-400 hover:text-white" aria-label="Open menu" aria-expanded="false">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="flex-1 max-w-md">
        <label for="header-search" class="sr-only">Search graduates</label>
        <input id="header-search" type="search" placeholder="SEARCH GRADUATES..." class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-1.5 text-sm placeholder:text-gray-600 focus:outline-none focus:border-accent-500">
    </div>
    <div class="flex items-center gap-3 text-sm text-gray-400">
        <span><?= e_upper($currentUser['name'] ?? '') ?></span>
        <span class="hidden sm:inline text-xs uppercase text-gray-600">(<?= e($currentUser['role'] ?? '') ?>)</span>
    </div>
</header>
