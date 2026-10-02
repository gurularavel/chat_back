<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:new,handled'], 'search' => ['nullable', 'string', 'max:100']]);

        return response()->json(
            ContactRequest::query()
                ->when($request->input('status') === 'new', fn ($q) => $q->whereNull('handled_at'))
                ->when($request->input('status') === 'handled', fn ($q) => $q->whereNotNull('handled_at'))
                ->when($request->input('search'), function ($q, $search) {
                    $like = '%'.mb_strtolower($search).'%';
                    $digits = preg_replace('/\D/', '', $search);
                    $q->where(fn ($q) => $q
                        ->whereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like])
                        ->orWhere('email', 'like', $like)
                        ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$digits.'%')));
                })
                ->latest()
                ->paginate(50)
        );
    }

    public function update(Request $request, ContactRequest $contactRequest): JsonResponse
    {
        $request->validate(['handled' => ['required', 'boolean']]);
        $handled = $request->boolean('handled');

        $contactRequest->update(['handled_at' => $handled ? now() : null]);
        AuditLog::record('admin.contact_request.'.($handled ? 'handled' : 'reopened'), $contactRequest);

        return response()->json($contactRequest);
    }

    public function destroy(ContactRequest $contactRequest): JsonResponse
    {
        AuditLog::record('admin.contact_request.delete', $contactRequest, ['email' => $contactRequest->email]);
        $contactRequest->delete();

        return response()->json(['ok' => true]);
    }
}
