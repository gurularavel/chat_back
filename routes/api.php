<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Api;
use App\Http\Controllers\Widget\WidgetApiController;
use Illuminate\Support\Facades\Route;

/*
| Public widget API (customer websites). Token based, no session.
*/
Route::prefix('widget/{key}')->group(function () {
    Route::get('config', [WidgetApiController::class, 'config'])->middleware('throttle:widget-session');
    Route::post('session', [WidgetApiController::class, 'session'])->middleware('throttle:widget-session');
    Route::middleware('throttle:widget')->group(function () {
        Route::get('messages', [WidgetApiController::class, 'messages']);
        Route::post('messages', [WidgetApiController::class, 'send']);
        Route::post('contact', [WidgetApiController::class, 'contact']);
        Route::post('rate', [WidgetApiController::class, 'rate']);
        Route::post('broadcasting/auth', [WidgetApiController::class, 'broadcastingAuth']);
    });
});

/*
| Auth (Sanctum SPA cookie session).
*/
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [Api\AuthController::class, 'register']);
    Route::post('login', [Api\AuthController::class, 'login']);
    Route::post('forgot-password', [Api\AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [Api\AuthController::class, 'resetPassword']);
    Route::get('verify-email/{id}/{hash}', [Api\AuthController::class, 'verifyEmail'])
        ->middleware('signed')->name('verification.verify');
});

Route::get('plans', [Api\BillingController::class, 'plans']);
Route::get('invitations/{token}', [Api\InvitationController::class, 'show']);
Route::post('invitations/{token}/accept', [Api\InvitationController::class, 'accept']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [Api\AuthController::class, 'logout']);
    Route::post('auth/email/resend', [Api\AuthController::class, 'resendVerification'])->middleware('throttle:auth');

    Route::get('me', [Api\MeController::class, 'show']);
    Route::patch('me', [Api\MeController::class, 'update']);
    Route::post('me/workspace', [Api\MeController::class, 'switchWorkspace']);
    Route::post('workspaces', [Api\MeController::class, 'createWorkspace']);

    /*
    | Tenant API — scoped to the X-Workspace header.
    */
    Route::middleware('workspace')->group(function () {
        // Operator inbox (all roles)
        Route::get('dashboard', Api\DashboardController::class);
        Route::post('presence', [Api\PresenceController::class, 'heartbeat']);
        Route::get('conversations', [Api\ConversationController::class, 'index']);
        Route::get('conversations/counts', [Api\ConversationController::class, 'counts']);
        Route::get('conversations/{conversation}', [Api\ConversationController::class, 'show']);
        Route::post('conversations/{conversation}/messages', [Api\ConversationController::class, 'reply']);
        Route::post('conversations/{conversation}/claim', [Api\ConversationController::class, 'claim']);
        Route::post('conversations/{conversation}/transfer', [Api\ConversationController::class, 'transfer']);
        Route::post('conversations/{conversation}/return-to-ai', [Api\ConversationController::class, 'returnToAi']);
        Route::post('conversations/{conversation}/close', [Api\ConversationController::class, 'close']);
        Route::get('members', [Api\MemberController::class, 'index']);
        Route::get('departments', [Api\DepartmentController::class, 'index']);

        // Owner / admin
        Route::middleware('workspace.admin')->group(function () {
            Route::patch('workspace', [Api\WorkspaceController::class, 'update']);

            Route::patch('members/{userId}', [Api\MemberController::class, 'update']);
            Route::delete('members/{userId}', [Api\MemberController::class, 'destroy']);
            Route::get('invitations', [Api\InvitationController::class, 'index']);
            Route::post('invitations', [Api\InvitationController::class, 'store']);
            Route::delete('invitations/{id}', [Api\InvitationController::class, 'destroy']);
            Route::apiResource('departments', Api\DepartmentController::class)->only(['store', 'update', 'destroy']);

            Route::apiResource('ai-credentials', Api\AiCredentialController::class)->except('show')
                ->parameters(['ai-credentials' => 'aiCredential']);
            Route::post('ai-credentials/{aiCredential}/test', [Api\AiCredentialController::class, 'test']);
            Route::get('ai-settings', [Api\AiSettingController::class, 'show']);
            Route::put('ai-settings', [Api\AiSettingController::class, 'update']);
            Route::post('ai-settings/playground', [Api\AiSettingController::class, 'playground'])->middleware('throttle:20,1');

            Route::get('documents', [Api\DocumentController::class, 'index']);
            Route::post('documents', [Api\DocumentController::class, 'store']);
            Route::post('documents/reindex', [Api\DocumentController::class, 'reindex']);
            Route::delete('documents/{document}', [Api\DocumentController::class, 'destroy']);
            Route::post('documents/{document}/reprocess', [Api\DocumentController::class, 'reprocess']);

            Route::apiResource('widgets', Api\WidgetController::class);
            Route::post('widgets/{widget}/launcher-image', [Api\WidgetController::class, 'uploadLauncherImage']);
            Route::delete('widgets/{widget}/launcher-image', [Api\WidgetController::class, 'deleteLauncherImage']);

            Route::get('billing', [Api\BillingController::class, 'show']);
            Route::post('billing/checkout', [Api\BillingController::class, 'checkout']);
            Route::post('billing/seats', [Api\BillingController::class, 'seats']);
            Route::post('billing/cancel', [Api\BillingController::class, 'cancel']);
            Route::post('billing/resume', [Api\BillingController::class, 'resume']);
            Route::get('billing/payments/{payment}', [Api\BillingController::class, 'payment']);
            Route::get('billing/invoices/{invoice}', [Api\BillingController::class, 'invoice']);
            Route::post('billing/invoices/{invoice}/pay', [Api\BillingController::class, 'payInvoice']);
            Route::patch('billing/details', [Api\BillingController::class, 'updateDetails']);
        });
    });

    /*
    | Superadmin.
    */
    Route::prefix('admin')->middleware('superadmin')->group(function () {
        Route::get('overview', Admin\OverviewController::class);
        Route::get('workspaces', [Admin\WorkspaceController::class, 'index']);
        Route::get('workspaces/{workspace}', [Admin\WorkspaceController::class, 'show']);
        Route::patch('workspaces/{workspace}', [Admin\WorkspaceController::class, 'update']);
        Route::patch('workspaces/{workspace}/subscription', [Admin\WorkspaceController::class, 'updateSubscription']);
        Route::post('workspaces/{workspace}/grant', [Admin\WorkspaceController::class, 'grant']);
        Route::post('workspaces/{workspace}/impersonate', [Admin\WorkspaceController::class, 'impersonate']);
        Route::get('conversations', [Admin\ConversationController::class, 'index']);
        Route::get('conversations/{conversation}', [Admin\ConversationController::class, 'show']);
        Route::get('usage', Admin\UsageController::class);
        Route::get('documents', [Admin\DocumentController::class, 'index']);
        Route::post('documents/{document}/retry', [Admin\DocumentController::class, 'retry']);
        Route::get('documents/{document}/file', [Admin\DocumentController::class, 'file']);
        Route::get('documents/{document}/text', [Admin\DocumentController::class, 'text']);
        Route::apiResource('plans', Admin\PlanController::class)->except('show');
        Route::get('payments', [Admin\PaymentController::class, 'index']);
        Route::post('payments/{payment}/refund', [Admin\PaymentController::class, 'refund']);
        Route::get('settings', [Admin\SettingController::class, 'show']);
        Route::put('settings', [Admin\SettingController::class, 'update']);
        Route::post('settings/test-mail', [Admin\SettingController::class, 'testMail'])->middleware('throttle:5,1');
        Route::post('settings/logo', [Admin\SettingController::class, 'uploadLogo']);
        Route::delete('settings/logo', [Admin\SettingController::class, 'deleteLogo']);
        Route::get('audit-logs', Admin\AuditLogController::class);
    });
});
