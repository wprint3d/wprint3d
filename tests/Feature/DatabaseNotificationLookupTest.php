<?php

namespace Tests\Feature;

use App\Models\DatabaseNotification;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class DatabaseNotificationLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseNotification::truncate();
    }

    protected function tearDown(): void
    {
        DatabaseNotification::truncate();

        parent::tearDown();
    }

    public function test_by_ids_matches_legacy_and_current_notification_documents(): void
    {
        $legacyId = (string) Str::uuid();
        $currentId = (string) Str::uuid();
        $otherId = (string) Str::uuid();

        $collection = DatabaseNotification::raw();

        // Stored by laravel-mongodb 4.x: UUID in "id", ObjectId in "_id".
        $collection->insertOne(['_id' => new ObjectId, 'id' => $legacyId, 'type' => 'legacy']);

        // Stored by laravel-mongodb 5.x: UUID in "_id".
        $collection->insertOne(['_id' => $currentId, 'type' => 'current']);

        $collection->insertOne(['_id' => $otherId, 'type' => 'other']);

        $types = DatabaseNotification::query()
            ->byIds([$legacyId, $currentId])
            ->get()
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['current', 'legacy'], $types);
    }

    public function test_by_id_matches_a_legacy_notification_document(): void
    {
        $legacyId = (string) Str::uuid();

        DatabaseNotification::raw()->insertOne(['_id' => new ObjectId, 'id' => $legacyId, 'type' => 'legacy']);

        $notification = DatabaseNotification::query()->byId($legacyId)->first();

        $this->assertNotNull($notification);
        $this->assertSame('legacy', $notification->type);
    }

    public function test_mark_as_read_and_delete_update_legacy_and_current_documents(): void
    {
        $legacyKey = new ObjectId;
        $legacyId = (string) Str::uuid();
        $currentId = (string) Str::uuid();

        $collection = DatabaseNotification::raw();

        $collection->insertOne(['_id' => $legacyKey, 'id' => $legacyId, 'type' => 'legacy', 'read_at' => null]);
        $collection->insertOne(['_id' => $currentId, 'type' => 'current', 'read_at' => null]);

        DatabaseNotification::query()->byIds([$legacyId, $currentId])->each(function ($notification) {
            $notification->markAsRead();
        });

        $this->assertNotNull($collection->findOne(['_id' => $legacyKey])['read_at']);
        $this->assertNotNull($collection->findOne(['_id' => $currentId])['read_at']);
        $this->assertSame(2, $collection->countDocuments());

        DatabaseNotification::query()->byId($legacyId)->first()->delete();
        DatabaseNotification::query()->byId($currentId)->first()->delete();

        $this->assertSame(0, $collection->countDocuments());
    }
}
