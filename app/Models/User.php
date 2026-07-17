<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;

#[Fillable(['name', 'email', 'password', 'role', 'suspended_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            $this->attributes['password'] = Hash::make($value);
        }
    }

    /**
     * The classes this user is subscribed to as a student.
     */
    public function subscribedClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_user', 'user_id', 'class_id')->withTimestamps();
    }
}
