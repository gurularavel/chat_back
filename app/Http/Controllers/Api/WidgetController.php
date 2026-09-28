<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WidgetResource;
use App\Models\AuditLog;
use App\Models\Widget;
use App\Services\Billing\PlanLimits;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WidgetController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WidgetResource::collection(Widget::orderBy('id')->get())
            ->additional([
                'branding' => Branding::toArray(),
                // Built-in texts, shown as placeholders in the text editor
                'text_defaults' => collect(config('chat.locales'))->mapWithKeys(fn (string $locale) => [$locale => Widget::defaultTexts($locale)]),
            ]);
    }

    public function store(Request $request, PlanLimits $limits): WidgetResource
    {
        $limits->ensureCanCreateWidget($request->attributes->get('workspace'));
        $widget = Widget::create($this->validated($request, creating: true));
        AuditLog::record('widget.created', $widget);

        return new WidgetResource($widget);
    }

    public function show(Widget $widget): WidgetResource
    {
        return new WidgetResource($widget);
    }

    public function update(Request $request, Widget $widget): WidgetResource
    {
        $data = $this->validated($request, creating: false);
        if (isset($data['appearance'])) {
            $data['appearance'] = array_merge($widget->appearance ?? [], $data['appearance']);
        }
        $widget->update($data);

        return new WidgetResource($widget);
    }

    /**
     * Customer's own launcher (bubble) image. Raster formats only: an SVG served
     * from the API domain could carry scripts.
     */
    public function uploadLauncherImage(Request $request, Widget $widget): WidgetResource
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:256', 'dimensions:max_width=512,max_height=512'],
        ]);

        $widget->deleteLauncherImage();
        $path = $request->file('image')->store(Widget::LAUNCHER_IMAGE_DIRECTORY.'/'.$widget->workspace_id, 'public');
        $widget->update(['appearance' => array_merge($widget->appearance ?? [], ['launcher_image' => $path])]);

        return new WidgetResource($widget);
    }

    public function deleteLauncherImage(Widget $widget): WidgetResource
    {
        $widget->deleteLauncherImage();
        $appearance = $widget->appearance ?? [];
        unset($appearance['launcher_image']);
        $widget->update(['appearance' => $appearance]);

        return new WidgetResource($widget);
    }

    public function destroy(Widget $widget): JsonResponse
    {
        $widget->deleteLauncherImage();
        $widget->delete();
        AuditLog::record('widget.deleted', $widget);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'allowed_domains' => ['sometimes', 'array', 'max:50'],
            'allowed_domains.*' => ['string', 'max:190', 'regex:/^(\*\.)?[a-z0-9.-]+(:\d+)?$/i'],
            'appearance' => ['sometimes', 'array'],
            'appearance.color' => ['sometimes', 'regex:/^#[0-9a-f]{6}$/i'],
            'appearance.position' => ['sometimes', Rule::in(['left', 'right'])],
            'appearance.avatar_url' => ['nullable', 'url', 'max:500'],
            'appearance.launcher_icon' => ['sometimes', Rule::in(Widget::LAUNCHER_ICONS)],
            'texts' => ['sometimes', 'nullable', 'array:'.implode(',', config('chat.locales'))],
            'texts.*' => ['nullable', 'array:'.implode(',', Widget::textKeys())],
            'texts.*.*' => ['nullable', 'string', 'max:500'],
            'pre_chat_form' => ['sometimes', 'array'],
            'pre_chat_form.enabled' => ['boolean'],
            'pre_chat_form.fields' => ['array'],
            'pre_chat_form.fields.*' => [Rule::in(['name', 'email', 'phone'])],
            'is_active' => ['sometimes', 'boolean'],
            'social_links' => ['sometimes', 'nullable', 'array:'.implode(',', Widget::SOCIAL_NETWORKS)],
            'social_links.phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[\d\s()-]{5,}$/'],
            'social_links.email' => ['nullable', 'email', 'max:190'],
            ...collect(Widget::SOCIAL_NETWORKS)
                ->reject(fn (string $network) => in_array($network, ['phone', 'email'], true))
                ->mapWithKeys(fn (string $network) => ["social_links.{$network}" => ['nullable', 'url:https', 'max:300']])
                ->all(),
            'show_social_links' => ['sometimes', 'boolean'],
            'hide_when_offline' => ['sometimes', 'boolean'],
        ]);

        // The launcher image is only set through uploadLauncherImage(); never trust a client path/URL.
        if (isset($data['appearance'])) {
            unset($data['appearance']['launcher_image'], $data['appearance']['launcher_image_url']);
        }

        if (array_key_exists('texts', $data)) {
            // Only real overrides are stored; empty = use the built-in text.
            $data['texts'] = array_filter(array_map(
                fn ($texts) => array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $texts ?? []), fn ($v) => filled($v)),
                $data['texts'] ?? [],
            )) ?: null;
        }

        if (array_key_exists('social_links', $data)) {
            $data['social_links'] = array_filter($data['social_links'] ?? [], fn ($value) => filled($value));
        }

        if (isset($data['allowed_domains'])) {
            $data['allowed_domains'] = array_values(array_unique(array_map('strtolower', $data['allowed_domains'])));
        }

        return $data;
    }
}
