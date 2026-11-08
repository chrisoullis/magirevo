<!doctype html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>{{ $component['title'] }}</title>@vite(['resources/css/app.css'])</head>
<body><main class="page-shell"><p>{{ $component['summary'] }}</p><div>{{ $component['introduction'] }}</div>@include('recipes.partials.components', ['components' => [$component], 'cooking' => false, 'printing' => true])</main></body>
</html>
