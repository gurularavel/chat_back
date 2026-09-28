<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Serves the widget iframe. The browser enforces the domain whitelist through
 * the CSP frame-ancestors header, so the chat cannot be framed on other sites.
 */
class WidgetFrameController extends Controller
{
    public function __invoke(string $key): Response
    {
        abort_unless(Str::isUuid($key), 404);
        $widget = Widget::withoutGlobalScopes()->where('public_key', $key)->firstOrFail();

        $manifest = public_path('widget/manifest.json');
        $assets = is_file($manifest) ? json_decode(file_get_contents($manifest), true) : [];

        $response = response()
            ->view('widget.frame', [
                'widgetKey' => $widget->public_key,
                'apiUrl' => rtrim(config('app.url'), '/'),
                'script' => $assets['script'] ?? 'app.js',
                'style' => $assets['style'] ?? 'app.css',
            ])
            ->header('Cache-Control', 'no-store');

        // No whitelist = embeddable anywhere. We omit the header instead of sending
        // "frame-ancestors *", because "*" does not match file:// or other non-network origins.
        $domains = array_filter($widget->allowed_domains ?? []);
        if ($domains !== []) {
            $ancestors = implode(' ', array_map(fn (string $d) => 'https://'.$d.' http://'.$d, $domains));
            $response->header('Content-Security-Policy', "frame-ancestors {$ancestors}");
        }

        return $response;
    }
}
