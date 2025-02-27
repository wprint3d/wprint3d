<?php

/*
 * This file overrides the default Laravel model for notifications.
 * 
 * It allows us to use MongoDB as the database for notifications.
 */

namespace App\Models;

use MongoDB\Laravel\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;

use Illuminate\Database\Eloquent\HasCollection;

use Illuminate\Notifications\DatabaseNotificationCollection;

class DatabaseNotification extends Model
{
    /** @use HasCollection<DatabaseNotificationCollection> */
    use HasCollection;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * The guarded attributes on the model.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * The type of collection that should be used for the model.
     */
    protected static string $collectionClass = DatabaseNotificationCollection::class;

    /**
     * Get the notifiable entity that the notification belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function notifiable()
    {
        return $this->morphTo();
    }

    /**
     * Mark the notification as read.
     *
     * @return void
     */
    public function markAsRead()
    {
        if (is_null($this->read_at)) {
            $this->forceFill(['read_at' => $this->freshTimestamp()])->save();
        }
    }

    /**
     * Mark the notification as unread.
     *
     * @return void
     */
    public function markAsUnread()
    {
        if (! is_null($this->read_at)) {
            $this->forceFill(['read_at' => null])->save();
        }
    }

    /**
     * Determine if a notification has been read.
     *
     * @return bool
     */
    public function read()
    {
        return $this->read_at !== null;
    }

    /**
     * Determine if a notification has not been read.
     *
     * @return bool
     */
    public function unread()
    {
        return $this->read_at === null;
    }

    /**
     * Scope a query to only include read notifications.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeRead(Builder $query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope a query to only include unread notifications.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeUnread(Builder $query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope a query to get a notification by its internal ID instead of the ID
     * associated to the database.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  string  $id
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeById(Builder $query, $id)
    {
        return $query->where('id', $id);
    }

    /**
     * Scope a query to only include notifications that match the internal IDs
     * given instead of the IDs associated to the database.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  array  $ids
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByIds(Builder $query, array $ids)
    {
        return $query->whereIn('id', $ids);
    }
}
