<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat</title>
    <link rel="stylesheet" href="{{ asset('widget/'.$style) }}">
</head>
<body>
    <div id="app"></div>
    <script>
        window.__CHAT__ = @json(['key' => $widgetKey, 'api' => $apiUrl]);
    </script>
    <script type="module" src="{{ asset('widget/'.$script) }}"></script>
</body>
</html>
