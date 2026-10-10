{{-- NU HRIS Favicon --}}
@php
	$logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 768 768"><rect width="768" height="768" rx="112" fill="#063f77"/><path d="M431 191a245 245 0 1 0 168 129" fill="none" stroke="#ffd500" stroke-linecap="round" stroke-width="38"/><circle cx="333" cy="354" r="60" fill="#fff"/><path d="M220 503c0-41 33-74 74-74h79c41 0 74 33 74 74v37c0 34-57 54-114 54s-113-20-113-54z" fill="#fff"/><path d="m436 414 45 45 136-258" fill="none" stroke="#ffd500" stroke-linecap="round" stroke-linejoin="round" stroke-width="38"/></svg>';
	$logoDataUri = 'data:image/svg+xml,' . rawurlencode($logoSvg);
@endphp
<link rel="icon" type="image/svg+xml" href="{{ $logoDataUri }}">
<link rel="shortcut icon" type="image/svg+xml" href="{{ $logoDataUri }}">
<link rel="apple-touch-icon" href="{{ $logoDataUri }}">
<meta name="theme-color" content="#00386f">
