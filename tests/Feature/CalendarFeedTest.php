<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('persists unique 64-character feed tokens and overwrites them on regeneration', function () {
    expect(Schema::hasColumn('users', 'feed_token'))->toBeTrue()
        ->and(Schema::getColumnType('users', 'feed_token'))->toBe('varchar')
        ->and(Schema::hasIndex('users', ['feed_token'], 'unique'))->toBeTrue();

    $user = User::factory()->create();
    $user->fill(['feed_token' => str_repeat('x', 64)]);

    expect($user->feed_token)->toBeNull();

    $firstToken = $user->generateFeedToken();

    expect($firstToken)->toHaveLength(64)
        ->and($user->fresh()->feed_token)->toBe($firstToken)
        ->and($user->toArray())->not->toHaveKey('feed_token');

    $secondToken = $user->regenerateFeedToken();

    expect($secondToken)->toHaveLength(64)
        ->and($secondToken)->not->toBe($firstToken)
        ->and($user->fresh()->feed_token)->toBe($secondToken);

    $otherUser = User::factory()->create();
    $otherUser->feed_token = $secondToken;

    expect(fn () => $otherUser->save())->toThrow(QueryException::class);
});
