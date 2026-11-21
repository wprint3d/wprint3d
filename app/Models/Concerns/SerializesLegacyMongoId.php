<?php

namespace App\Models\Concerns;

/**
 * Keeps the serialized primary key exposed as "_id".
 *
 * Starting with mongodb/laravel-mongodb 5.0, the library aliases the
 * MongoDB "_id" field to "id" in query results, so models serialize their
 * primary key as "id". The API consumers (frontend, plugins, OctoPrint
 * compatibility layer) expect "_id", so this trait exposes the primary key
 * under "_id" again while keeping the new "id" alias (notifications are read
 * through both keys). Documents that store a separate "id" field next to
 * "_id" are left untouched.
 */
trait SerializesLegacyMongoId
{
    /**
     * Convert the model's attributes to an array, exposing "_id" again.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        $hasId = (array_key_exists('id', $attributes) === true);
        $hasLegacyId = (array_key_exists('_id', $attributes) === true);

        if ($hasId === true && $hasLegacyId === false) {
            $attributes = (['_id' => $attributes['id']] + $attributes);
        }

        return $attributes;

    }
}
