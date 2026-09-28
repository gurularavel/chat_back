@props(['url'])
{{-- Brand logo on every email (invitations, password resets, invoices, reminders); links to the dashboard. --}}
<tr>
<td class="header">
<a href="{{ rtrim(config('chat.frontend_url'), '/') }}" style="display: inline-block;">
<img src="{{ \App\Support\Branding::fullLogoUrl() }}" alt="{{ trim(strip_tags($slot)) }}" width="152" height="48" style="height: 48px; width: auto; max-width: 100%; border: 0;">
</a>
</td>
</tr>
