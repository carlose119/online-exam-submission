<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role', 'suspended_at'])]
#[Hidden(['password', 'remember_token', 'feed_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private const FEED_TOKEN_MAX_ATTEMPTS = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Hash the password whenever it is set on the model.
     *
     * Guards against accidental plain-text storage on ANY assignment path.
     */
    protected function setPasswordAttribute(?string $value): void
    {
        if ($value !== null) {
            $this->attributes['password'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
        }
    }

    /**
     * Issue and persist a new calendar feed token.
     */
    public function generateFeedToken(): string
    {
        return $this->issueFeedToken();
    }

    /**
     * Replace the current calendar feed token, immediately invalidating the old one.
     */
    public function regenerateFeedToken(): string
    {
        return $this->issueFeedToken();
    }

    private function issueFeedToken(): string
    {
        for ($attempt = 1; $attempt <= self::FEED_TOKEN_MAX_ATTEMPTS; $attempt++) {
            $token = Str::random(64);

            try {
                $this->forceFill(['feed_token' => $token]);

                if ($this->save()) {
                    return $token;
                }
            } catch (QueryException $exception) {
                if (! $this->isFeedTokenUniqueViolation($exception) || $attempt === self::FEED_TOKEN_MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to persist a calendar feed token.');
    }

    private function isFeedTokenUniqueViolation(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'feed_token');
    }

    /**
     * The classes this user is subscribed to as a student.
     */
    public function subscribedClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_user', 'user_id', 'class_id')->withTimestamps();
    }

    /**
     * The exam attempts made by this user.
     */
    public function studentAttempts(): HasMany
    {
        return $this->hasMany(StudentAttempt::class, 'student_id');
    }
}
