<?php

use App\Models\Printer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return $user->id == $id;
});

Broadcast::channel('console.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('connection-status.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('preview.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('failed-job.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('job-progress.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('finished-job.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('preview-loading.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('preview-command.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('recovery-stage-changed.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('recovery-progress.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('recovery-completed.{printerId}', function (User $user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $printerId == $user->getActivePrinter();
});

Broadcast::channel('system-message.{userId}', function (User $user, $userId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($userId));

    /*
     * True if the user ID passed in the URL is equal to the stored value in
     * the active session.
     */
    return $userId == Auth::id();
});