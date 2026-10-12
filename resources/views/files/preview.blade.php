<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview {{ $filename }}</title>
    @vite(['resources/css/app.css'])
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
        }

        .file-preview-page {
            display: flex;
            height: 100dvh;
            min-height: 100dvh;
            flex-direction: column;
            overflow: hidden;
        }

        .file-preview-main {
            position: relative;
            min-height: 0;
            flex: 1 1 0%;
            overflow: hidden;
            padding: 0;
        }

        .file-preview-frame {
            position: absolute;
            inset: 0;
            display: block;
            height: auto;
            min-height: 0;
            width: auto;
            min-width: 0;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        @media (min-width: 640px) {
            .file-preview-frame {
                inset: 0;
            }
        }
    </style>
</head>
<body class="file-preview-page bg-slate-100 text-slate-900">
    @php
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $officeExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $isOfficeFile = in_array($extension, $officeExtensions, true);
        $viewerUrl = $isOfficeFile
            ? 'https://view.officeapps.live.com/op/embed.aspx?src='.urlencode($url)
            : $url.(str_contains($url, '#') ? '&' : '#').'view=FitH&zoom=page-width';
    @endphp

    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-2 shadow-sm">
        <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Online file preview</p>
            <h1 class="truncate text-sm font-bold" title="{{ $filename }}">{{ $filename }}</h1>
        </div>
        <a href="{{ $url }}" download="{{ $filename }}" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">
            Download
        </a>
    </header>

    <main class="file-preview-main">
        <iframe
            src="{{ $viewerUrl }}"
            title="Preview of {{ $filename }}"
            class="file-preview-frame rounded-xl border border-slate-300 bg-white shadow-sm"
            allow="fullscreen"
        ></iframe>
    </main>
</body>
</html>