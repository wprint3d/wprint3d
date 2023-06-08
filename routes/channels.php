<?php

use App\Models\Printer;
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

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user->id == $id;
});

Broadcast::channel('console.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('connection-status.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('preview.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('failed-job.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('job-progress.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('finished-job.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('preview-loading.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('preview-command.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('recovery-stage-changed.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('recovery-progress.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});

Broadcast::channel('recovery-completed.{printerId}', function ($user, $printerId) {
    Log::info('=> ' . json_encode($user) . ', ' . json_encode($printerId));

    return $user->_id == Printer::getActiveUserId( $printerId );
});