<?php

declare(strict_types=1);

use App\Modules\Organizations\Http\Controllers\Web\InvitationController;
use Illuminate\Support\Facades\Route;

// An invitation link works for anyone who holds it, signed in or not. Nothing here names another invitation.
$token = '[A-Za-z0-9_-]{43}';

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->where('token', $token)->name('invitations.show');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->where('token', $token)->middleware('throttle:10,1')->name('invitations.accept');
Route::post('/invitations/{token}/switch', [InvitationController::class, 'switchAccount'])->where('token', $token)->name('invitations.switch');
