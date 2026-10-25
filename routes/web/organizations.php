<?php

declare(strict_types=1);

use App\Modules\Organizations\Http\Controllers\Web\InvitationController;
use App\Modules\Organizations\Http\Controllers\Web\OrganizationMembersController;
use App\Modules\Organizations\Http\Controllers\Web\OrganizationSettingsController;
use App\Modules\Organizations\Http\Controllers\Web\ProjectMembersController;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use Illuminate\Support\Facades\Route;

// An invitation link works for anyone who holds it, signed in or not. Nothing here names another invitation.
$token = '[A-Za-z0-9_-]{43}';

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->where('token', $token)->name('invitations.show');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->where('token', $token)->middleware('throttle:10,1')->name('invitations.accept');
Route::post('/invitations/{token}/switch', [InvitationController::class, 'switchAccount'])->where('token', $token)->name('invitations.switch');

// Organization settings. Every route resolves the organization from the address and answers 404 to someone who does not
// belong to it. Pages need `org.read`; changing anything needs the capability named in the controller.
$slug = app(OrganizationSlugs::class)->routePattern();
$id = '[0-9a-z]{26}';

Route::middleware(['auth:web', 'portal', 'org.mfa'])->prefix('{organization}/settings')->where(['organization' => $slug])->group(function () use ($id): void {
    Route::get('/', [OrganizationSettingsController::class, 'index'])->name('organization.settings');
    Route::get('/general', [OrganizationSettingsController::class, 'general'])->name('organization.settings.general');
    Route::put('/general', [OrganizationSettingsController::class, 'updateGeneral'])->name('organization.settings.general.update');
    Route::get('/security', [OrganizationSettingsController::class, 'security'])->name('organization.settings.security');
    Route::put('/security', [OrganizationSettingsController::class, 'updateSecurity'])->name('organization.settings.security.update');
    Route::get('/projects', [OrganizationSettingsController::class, 'projects'])->name('organization.settings.projects');
    Route::post('/projects/{projectId}/join', [OrganizationSettingsController::class, 'joinProject'])->where('projectId', 'prj_[0-9a-z]{26}')->name('organization.settings.projects.join');

    Route::get('/members', [OrganizationMembersController::class, 'index'])->name('organization.settings.members');
    Route::post('/members/invitations', [OrganizationMembersController::class, 'invite'])->middleware('throttle:30,1')->name('organization.settings.members.invite');
    Route::post('/members/invitations/{invitation}/resend', [OrganizationMembersController::class, 'resend'])->where('invitation', $id)->middleware('throttle:30,1')->name('organization.settings.members.resend');
    Route::delete('/members/invitations/{invitation}', [OrganizationMembersController::class, 'revoke'])->where('invitation', $id)->name('organization.settings.members.revoke');
    Route::patch('/members/{membership}', [OrganizationMembersController::class, 'changeRole'])->where('membership', $id)->name('organization.settings.members.role');
    Route::delete('/members/{membership}', [OrganizationMembersController::class, 'remove'])->where('membership', $id)->name('organization.settings.members.remove');
});

// A project's Members tab, and leaving a project.
$key = '[a-z0-9][a-z0-9-]{0,39}';

Route::middleware(['auth:web', 'portal', 'org.mfa'])->group(function () use ($slug, $key, $id): void {
    Route::prefix('{organization}/{project}/{environment}/settings/members')->where(['organization' => $slug, 'project' => $key, 'environment' => $key])->group(function () use ($id): void {
        Route::get('/', [ProjectMembersController::class, 'index'])->name('project.settings.members');
        Route::post('/invitations', [ProjectMembersController::class, 'invite'])->middleware('throttle:30,1')->name('project.settings.members.invite');
        Route::post('/invitations/{invitation}/resend', [ProjectMembersController::class, 'resend'])->where('invitation', $id)->middleware('throttle:30,1')->name('project.settings.members.resend');
        Route::delete('/invitations/{invitation}', [ProjectMembersController::class, 'revoke'])->where('invitation', $id)->name('project.settings.members.revoke');
        Route::patch('/{membership}', [ProjectMembersController::class, 'changeRole'])->where('membership', $id)->name('project.settings.members.role');
        Route::delete('/{membership}', [ProjectMembersController::class, 'remove'])->where('membership', $id)->name('project.settings.members.remove');
    });

    Route::delete('/{organization}/{project}/leave', [ProjectMembersController::class, 'leave'])->where(['organization' => $slug, 'project' => $key])->name('project.leave');
});
