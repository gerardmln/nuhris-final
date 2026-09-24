<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview {{ $filename }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    @php
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $officeExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $isOfficeFile = in_array($extension, $officeExtensions, true);
        $viewerUrl = $isOfficeFile
            ? 'https://view.officeapps.live.com/op/embed.aspx?src='.urlencode($url)
            : $url;
    @endphp

    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 shadow-sm">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Online file preview</p>
            <h1 class="truncate text-lg font-bold" title="{{ $filename }}">{{ $filename }}</h1>
        </div>
        <a href="{{ $url }}" download="{{ $filename }}" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
            Download
        </a>
    </header>

    <main class="h-[calc(100vh-81px)] p-3 sm:p-5">
        <iframe
            src="{{ $viewerUrl }}"
            title="Preview of {{ $filename }}"
            class="h-full w-full rounded-xl border border-slate-300 bg-white shadow-sm"
            allow="fullscreen"
        ></iframe>
    </main>
</body>
</html>