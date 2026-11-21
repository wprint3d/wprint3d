<?php

/*
 * This file overrides the default Laravel model for notifications.
 * 
 * It allows us to use MongoDB as the database for notifications.
 */

namespace App\Models;

use App\Models\Concerns\SerializesLegacyMongoId;

use MongoDB\Laravel\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;

use Illuminate\Database\Eloquent\HasCollection;

use Illuminate\Notifications\DatabaseNotificationCollection;

class DatabaseNotification extends Model
{
    use SerializesLegacyMongoId;

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
     * Set the keys for a save (update or delete) query.
     *
     * Notifications stored by mongodb/laravel-mongodb 4.x keep their UUID in
     * an "id" field next to an ObjectId "_id". Since 5.0, the "id" key is
     * compiled to "_id", so writes must target the stored "_id" of those
     * legacy documents instead.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $legacyKey = $this->getOriginal('_id');

        if ($legacyKey !== null && (string) $legacyKey !== (string) $this->getKeyForSaveQuery()) {
            $query->where('_id', '=', $legacyKey);

            return $query;
        }

        return parent::setKeysForSaveQuery($query);
    }

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
        return $query->byIds([$id]);
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
        $ids = array_values($ids);

        /*
         * Since mongodb/laravel-mongodb 5.0, a root "id" column is compiled to
         * "_id". Notifications stored by older versions keep their UUID in a
         * separate "id" field (next to an ObjectId "_id"), while newer ones
         * store it in "_id", so match both fields explicitly.
         */
        $filter = [
            '$or' => [
                ['id' => ['$in' => $ids]],
                ['_id' => ['$in' => $ids]],
            ],
        ];

        return $query->whereRaw($filter);
    }
}
